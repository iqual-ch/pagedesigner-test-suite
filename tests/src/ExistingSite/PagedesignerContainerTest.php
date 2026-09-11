<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

use Drupal\node\Entity\Node;
use Drupal\pagedesigner\Entity\ElementInterface;

/**
 * Tests the Pagedesigner container lifecycle on the live database.
 *
 * Verifies that saving nodes with pagedesigner_item fields automatically
 * creates and correctly associates container elements. This is the most
 * fundamental Pagedesigner contract and is tested against all content types
 * that have a pagedesigner_item field on the site.
 *
 * Catches regressions in PagedesignerService::addContainer, the
 * pagedesigner_item field type, and the element entity that could be
 * introduced by Drupal core or contrib updates.
 */
class PagedesignerContainerTest extends PagedesignerTestBase {

  /**
   * Tests that every PD-enabled content type auto-creates a container element.
   *
   * Dynamically discovers all content types with pagedesigner_item fields,
   * making this test portable across sites without hardcoding
   * any type names.
   */
  public function testContainerCreatedForAllPagedesignerTypes(): void {
    /** @var \Drupal\pagedesigner\PagedesignerServiceInterface $pdService */
    $pdService = \Drupal::service('pagedesigner.service');

    $nodeTypes = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
    $testedTypes = 0;
    $unfillable = [];

    foreach ($nodeTypes as $nodeType) {
      // Check if this content type has any pagedesigner_item fields.
      $tempNode = Node::create(['type' => $nodeType->id(), 'title' => 'PD container test']);
      $pdFields = $pdService->getPagedesignerFields($tempNode);

      if (empty($pdFields)) {
        continue;
      }

      // The probe already has the right bundle; give it the values that bundle
      // requires before saving it. Saving bypasses validation, so without this
      // a project's presave hooks are handed an entity state no editor could
      // have produced, and the inherited error guard then fails this test for
      // what is really the suite's own doing.
      //
      // Moderation is deliberately not resolved here: this test asserts only
      // that saving creates a container, never that the node is published or
      // reachable, and rejecting moderated bundles would drop them from a
      // contract that applies to them just as much.
      $node = $tempNode;
      if (!$this->fillRequiredFields($node)) {
        $unfillable[$nodeType->id()] = 'has a required field the test cannot populate';
        continue;
      }

      $testedTypes++;
      $node->setPublished()->save();
      $this->markEntityForCleanup($node);

      foreach ($pdFields as $fieldName => $field) {
        $this->assertFalse(
          $node->get($fieldName)->isEmpty(),
          "Field '$fieldName' on content type '{$nodeType->id()}' must not be empty after node save."
        );

        $container = $node->get($fieldName)->entity;
        $this->assertInstanceOf(
          ElementInterface::class,
          $container,
          "The entity referenced by '$fieldName' on '{$nodeType->id()}' must be a Pagedesigner Element."
        );
        $this->assertEquals(
          'container',
          $container->bundle(),
          "The auto-created element for '$fieldName' on '{$nodeType->id()}' must be of bundle 'container'."
        );
        $this->assertEquals(
          (int) $node->id(),
          (int) $container->entity->target_id,
          "The container for '$fieldName' on '{$nodeType->id()}' must reference the parent node ID."
        );
        $this->assertEquals(
          $node->language()->getId(),
          $container->langcode->value,
          "The container langcode for '$fieldName' on '{$nodeType->id()}' must match the node langcode."
        );
      }
    }

    if ($testedTypes === 0 && $unfillable !== []) {
      $reasons = [];
      foreach ($unfillable as $bundle => $reason) {
        $reasons[] = "$bundle $reason";
      }
      $this->markTestSkipped(
        'Every Pagedesigner content type on this site requires a field the test '
        . 'cannot populate: ' . implode('; ', $reasons) . '.'
      );
    }

    $this->assertGreaterThan(0, $testedTypes, 'No content types with pagedesigner_item fields were found. The test suite may be misconfigured.');
  }

}
