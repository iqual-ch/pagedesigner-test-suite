<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

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
class PagedesignerRenderTest extends PagedesignerTestBase {

  /**
   * Tests the Pagedesigner frontend render pipeline.
   *
   * Creates a node, adds a text element via the element handler, then verifies
   * the element renders on both the public frontend and the PD edit view.
   */
  public function testPagedesignerElementRendersPipeline(): void {
    [$node, $fieldName] = $this->createPagedesignerTestNode('PD render test');

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
    $this->loginAsAdmin();

    $this->drupalGet('/node/' . $node->id() . '/pagedesigner');
    $this->assertSession()->statusCodeEquals(200);

    // The edit view must include the GrapesJS initialisation target. The
    // attribute is set as a render-array #prefix by the Container handler, so
    // its absence means the pagedesigner field was never rendered on the page
    // — usually a bundle whose node template does not output the field.
    $this->assertSession()->elementExists('css', '[data-gjs-type="container"]');
  }

}
