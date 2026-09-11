<?php

namespace PagedesignerTestSuite\Tests\Traits;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Discovers and creates the node the Pagedesigner tests run against.
 *
 * The suite runs against existing sites rather than a fresh install, and no two
 * of them share a content model. Every test therefore needs a node that
 * - lives on a content type that actually has a pagedesigner_item field,
 * - is genuinely published (not merely `setPublished()`, which a
 *   content_moderation workflow silently overrides),
 * - is viewable by anonymous users (node-grant modules such as domain_access
 *   can withhold a programmatically created node from every domain),
 * - and satisfies its bundle's required fields, so project theme code is not
 *   handed an entity state no editor could ever produce.
 *
 * When no content type on the site can satisfy that, the test is skipped rather
 * than failed: a site whose Pagedesigner bundles are all non-public is a
 * legitimate configuration, not a regression.
 */
trait PagedesignerTestNodeTrait {

  /**
   * Content types that are, by convention, standalone public pages.
   *
   * Preferred over whatever sorts first alphabetically. The bundle that renders
   * as a page from a title alone is the only one against which "anonymous sees
   * 200 and the Pagedesigner container renders" is a meaningful assertion.
   */
  protected array $preferredPagedesignerBundles = ['page', 'iqbm_page', 'landing_page'];

  /**
   * Content types that exist specifically to not be standalone pages.
   */
  protected array $excludedPagedesignerBundles = ['pagedesigner_part'];

  /**
   * Returns the Pagedesigner-enabled content types, best candidate first.
   *
   * @return string[]
   *   Node type machine names, ordered by how suitable they are as a test
   *   target: preferred page bundles first, then by the number of required
   *   fields that would have to be invented, with fragment types excluded.
   */
  protected function findPagedesignerBundles(): array {
    /** @var \Drupal\pagedesigner\PagedesignerServiceInterface $pdService */
    $pdService = \Drupal::service('pagedesigner.service');
    $fieldManager = \Drupal::service('entity_field.manager');

    $candidates = [];
    foreach (\Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple() as $nodeType) {
      $bundle = $nodeType->id();
      if (in_array($bundle, $this->excludedPagedesignerBundles, TRUE)) {
        continue;
      }
      $probe = Node::create(['type' => $bundle, 'title' => 'Pagedesigner bundle probe']);
      if (empty($pdService->getPagedesignerFields($probe))) {
        continue;
      }

      // Fewer required fields means fewer invented values, so a lower score.
      $required = 0;
      foreach ($fieldManager->getFieldDefinitions('node', $bundle) as $definition) {
        if ($definition->isRequired() && !$definition->getFieldStorageDefinition()->isBaseField()) {
          $required++;
        }
      }
      $preferred = in_array($bundle, $this->preferredPagedesignerBundles, TRUE);
      $candidates[$bundle] = ($preferred ? -100 : 0) + $required;
    }

    asort($candidates);
    return array_keys($candidates);
  }

  /**
   * Creates a published, anonymously viewable node with a Pagedesigner field.
   *
   * @param string $title
   *   The node title to use.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: string}
   *   The saved node and the name of its pagedesigner_item field.
   */
  protected function createPagedesignerTestNode(string $title = 'Pagedesigner regression test'): array {
    /** @var \Drupal\pagedesigner\PagedesignerServiceInterface $pdService */
    $pdService = \Drupal::service('pagedesigner.service');

    $bundles = $this->findPagedesignerBundles();
    if (empty($bundles)) {
      $this->markTestSkipped('No content type with a pagedesigner_item field was found on this site.');
    }

    $rejected = [];
    foreach ($bundles as $bundle) {
      $node = Node::create(['type' => $bundle, 'title' => $title]);

      if (!$this->fillRequiredFields($node)) {
        $rejected[$bundle] = 'has a required field the test cannot populate';
        continue;
      }
      if (!$this->setPublishedModerationState($node)) {
        $rejected[$bundle] = 'is moderated with no published state available';
        continue;
      }

      $node->setPublished()->save();
      $this->markEntityForCleanup($node);

      // Re-read rather than trust the in-memory entity: content_moderation
      // rewrites `status` in presave, so setPublished() is not the last word.
      $fresh = Node::load($node->id());
      if (!$fresh->isPublished()) {
        $rejected[$bundle] = 'saved unpublished (a workflow overrode the status)';
        continue;
      }
      if (!$fresh->access('view', new AnonymousUserSession())) {
        $rejected[$bundle] = 'is not viewable by anonymous users';
        continue;
      }

      $pdFields = $pdService->getPagedesignerFields($fresh);
      if (empty($pdFields)) {
        $rejected[$bundle] = 'lost its pagedesigner_item field on save';
        continue;
      }

      return [$fresh, array_key_first($pdFields)];
    }

    $reasons = [];
    foreach ($rejected as $bundle => $reason) {
      $reasons[] = "$bundle $reason";
    }
    $this->markTestSkipped(
      'No Pagedesigner content type on this site can host a publicly viewable test node: '
      . implode('; ', $reasons) . '.'
    );
  }

  /**
   * Populates the required fields of an unsaved node.
   *
   * A node saved through the API skips validation entirely, so without this the
   * suite would render entities that violate their own bundle's constraints and
   * blame project code for the resulting errors.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The unsaved node to populate.
   *
   * @return bool
   *   TRUE when every required field now holds a value, FALSE when the bundle
   *   requires something this trait cannot invent (an entity reference, a file,
   *   a field type it does not know).
   */
  protected function fillRequiredFields(NodeInterface $node): bool {
    $definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', $node->bundle());

    foreach ($definitions as $name => $definition) {
      if (!$definition->isRequired() || $definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }
      if (!$node->get($name)->isEmpty()) {
        continue;
      }

      switch ($definition->getType()) {
        case 'pagedesigner_item':
          // Populated by the module itself on save.
          break;

        case 'string':
        case 'string_long':
          $node->set($name, 'Pagedesigner regression test');
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $node->set($name, [
            'value' => 'Pagedesigner regression test.',
            'format' => filter_default_format(),
          ]);
          break;

        case 'link':
          $node->set($name, ['uri' => 'route:<front>', 'title' => 'Pagedesigner regression test']);
          break;

        case 'email':
          $node->set($name, 'pagedesigner-test@example.com');
          break;

        case 'integer':
        case 'decimal':
        case 'float':
        case 'boolean':
          $node->set($name, 1);
          break;

        case 'list_string':
        case 'list_integer':
        case 'list_float':
          // A list field accepts only its configured keys. Inventing a value
          // would store an out-of-range entry no form could produce - the very
          // invalid state this trait exists to avoid - and project code that
          // looks the value up in the allowed list would be handed a NULL
          // label for it.
          $allowed = $this->allowedListValues($node, $definition);
          if ($allowed === []) {
            return FALSE;
          }
          $node->set($name, reset($allowed));
          break;

        case 'datetime':
          $node->set($name, date('Y-m-d'));
          break;

        default:
          return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns the keys a list field will accept, in configured order.
   *
   * Resolved through options_allowed_values() rather than read straight off the
   * storage setting, so that a field supplying its values from an
   * allowed_values_function - and the list-of-tuples storage format used since
   * Drupal 10.2 - resolve exactly as they would for a real form.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node the field belongs to, passed on for per-entity value callbacks.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field being populated.
   *
   * @return array
   *   The allowed keys, or an empty array when none can be resolved.
   */
  protected function allowedListValues(NodeInterface $node, FieldDefinitionInterface $definition): array {
    // Provided by the options module, which must be installed for a list field
    // to exist in the first place.
    if (!function_exists('options_allowed_values')) {
      return [];
    }
    $allowed = options_allowed_values($definition->getFieldStorageDefinition(), $node);
    return array_keys($allowed ?? []);
  }

  /**
   * Puts a moderated node into a published moderation state.
   *
   * On a bundle under a content_moderation workflow, `setPublished()` is
   * overwritten in presave by the workflow's default state — which is normally
   * a draft — so the node saves unpublished and anonymous users get a 403 that
   * looks like a permission regression.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The unsaved node.
   *
   * @return bool
   *   TRUE when the node is unmoderated or has been given a published state,
   *   FALSE when its workflow offers no published default-revision state.
   */
  protected function setPublishedModerationState(NodeInterface $node): bool {
    if (!\Drupal::moduleHandler()->moduleExists('content_moderation')) {
      return TRUE;
    }

    /** @var \Drupal\content_moderation\ModerationInformationInterface $moderationInformation */
    $moderationInformation = \Drupal::service('content_moderation.moderation_information');
    if (!$moderationInformation->isModeratedEntity($node)) {
      return TRUE;
    }

    $workflow = $moderationInformation->getWorkflowForEntity($node);
    foreach ($workflow->getTypePlugin()->getStates() as $id => $state) {
      if ($state->isPublishedState() && $state->isDefaultRevisionState()) {
        $node->set('moderation_state', $id);
        return TRUE;
      }
    }

    return FALSE;
  }

}
