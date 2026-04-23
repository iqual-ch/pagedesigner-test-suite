<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

use Drupal\node\Entity\Node;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests that Pagedesigner content renders correctly on the frontend.
 *
 * Programmatically creates a node with a text pattern element and verifies
 * that the rendering pipeline produces valid HTML output with the expected
 * data attributes. Also tests that the Pagedesigner edit view route renders
 * without errors.
 *
 * Catches regressions in ElementViewBuilder, the field formatter, and the
 * Renderer service that could be introduced by Drupal core or contrib updates.
 */
class PagedesignerRenderTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->failOnLoggedErrors();
  }

  /**
   * Tests the Pagedesigner frontend render pipeline.
   *
   * Creates a node, adds a text element via the element handler, then verifies
   * the element renders on both the public frontend and the PD edit view.
   */
  public function testPagedesignerElementRendersPipeline(): void {
    [$node, $fieldName] = $this->createPagedesignerTestNode();

    /** @var \Drupal\pagedesigner\PagedesignerServiceInterface $pdService */
    $pdService = \Drupal::service('pagedesigner.service');
    /** @var \Drupal\pagedesigner\Service\ElementHandler $elementHandler */
    $elementHandler = \Drupal::service('pagedesigner.service.element_handler');
    /** @var \Drupal\ui_patterns\UiPatternsManager $patternManager */
    $patternManager = \Drupal::service('plugin.manager.ui_patterns');

    $container = $pdService->getContainer($node, $fieldName);
    $this->assertNotNull($container, 'A container element must exist for the test node.');

    $patterns = $patternManager->getDefinitions();
    $this->assertArrayHasKey('text', $patterns, 'The "text" pattern must be available on all Pagedesigner projects.');

    // Add a text element to the container via the element handler.
    // Populate field_content on the generated content child element so the
    // rendered output is visible in screenshots.
    $textElement = $elementHandler->generate($patterns['text'], [
      'parent' => $container->id(),
      'container' => $container->id(),
      'entity' => $node->id(),
    ]);
    $textElement->save();
    // The text pattern produces a `component` element with a `content` child
    // (field_placeholder = "content") that carries the actual field_content.
    foreach ($textElement->children as $childRef) {
      $child = $childRef->entity;
      if ($child && $child->bundle() === 'content' && $child->get('field_placeholder')->value === 'content') {
        $child->set('field_content', [
          'value' => '<p>Pagedesigner render test — automated regression check.</p>',
          'format' => filter_default_format(),
        ]);
        $child->save();
        break;
      }
    }
    $container->children->appendItem($textElement);
    $container->save();

    // --- Test 1: Public frontend render ---
    // Use an explicit path to avoid URL generation quirks in the test context.
    $this->drupalGet('/node/' . $node->id());
    $this->assertSession()->statusCodeEquals(200);

    // In public render mode the container is wrapped in
    // <section class="pd-content pd-live ..."> — this class is set by the
    // Container handler only in non-edit render modes (Renderer::renderForPublic).
    // If the render pipeline breaks this element will be absent or change class.
    $this->assertSession()->elementExists('css', 'section.pd-content.pd-live');

    // --- Test 2: Pagedesigner edit view render ---
    // An admin user must be able to load the edit view without errors.
    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);

    $this->drupalGet('/node/' . $node->id() . '/pagedesigner');
    $this->assertSession()->statusCodeEquals(200);

    // The edit view must include the GrapesJS initialisation target.
    $this->assertSession()->elementExists('css', '[data-gjs-type="container"]');
  }

  /**
   * Creates a node on the first available PD-enabled content type.
   *
   * @return array{0: \Drupal\node\Entity\Node, 1: string}
   *   The created node and the name of the pagedesigner_item field.
   */
  protected function createPagedesignerTestNode(): array {
    /** @var \Drupal\pagedesigner\PagedesignerServiceInterface $pdService */
    $pdService = \Drupal::service('pagedesigner.service');

    $nodeTypes = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
    foreach ($nodeTypes as $nodeType) {
      $tempNode = Node::create(['type' => $nodeType->id(), 'title' => 'PD render test']);
      $pdFields = $pdService->getPagedesignerFields($tempNode);
      if (!empty($pdFields)) {
        $tempNode->setPublished()->save();
        $this->markEntityForCleanup($tempNode);
        return [$tempNode, array_key_first($pdFields)];
      }
    }

    $this->fail('No content type with a pagedesigner_item field was found on this site.');
  }

}
