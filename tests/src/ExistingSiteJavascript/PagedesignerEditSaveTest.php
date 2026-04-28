<?php

namespace PagedesignerTestSuite\Tests\ExistingSiteJavascript;

use Drupal\node\Entity\Node;
use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;
use weitzman\DrupalTestTraits\ScreenShotTrait;

/**
 * Tests the Pagedesigner editor edit → render round-trip in a real browser.
 *
 * Creates a node with a text element and verifies that:
 * 1. The Pagedesigner editor loads the element in the GrapesJS canvas.
 * 2. The frontend renders the element with the expected data attributes.
 *
 * This is the most comprehensive regression test: it exercises the full
 * GrapesJS integration, the render-for-edit pipeline, and the public render
 * pipeline together, catching issues that only surface in a browser.
 *
 * Catches regressions introduced by updates to the pagedesigner module,
 * GrapesJS, ui_patterns, or Drupal core rendering.
 */
class PagedesignerEditSaveTest extends ExistingSiteSelenium2DriverTestBase {

  use ScreenShotTrait;

  /**
   * Tests that elements added to a node render in the editor and on the frontend.
   *
   * Uses the PHP API to add a text element (bypassing the editor UI drag-and-drop),
   * then verifies both the edit and public render paths work correctly.
   * This tests what would happen after a user saves content via the PD editor.
   */
  public function testElementRendersInEditorAndFrontend(): void {
    $admin = $this->createUser([], NULL, TRUE);

    // Log in using form submission (compatible with all auth setups).
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }
    $this->drupalGet('/user/login');
    $this->submitForm([
      'name' => $admin->getAccountName(),
      'pass' => $admin->passRaw,
    ], t('Log in')->__toString());
    $admin->sessionId = $this->getSession()->getCookie(
      \Drupal::service('session_configuration')->getOptions(\Drupal::request())['name']
    );
    $this->assertTrue($this->drupalUserIsLoggedIn($admin));
    $this->loggedInUser = $admin;
    $this->container->get('current_user')->setAccount($admin);

    // Set up a test node with a text element via the PHP API.
    // This simulates content that a user would have saved via the PD editor.
    [$node, $fieldName, $textElementId] = $this->createNodeWithTextElement();

    // --- Phase 1: Verify the PD editor renders the element in the canvas ---
    $editorUrl = '/node/' . $node->id() . '/pagedesigner';
    $this->drupalGet($editorUrl);

    // Wait for the loading screen to disappear.
    $this->assertSession()->waitForElementRemoved('css', '.loading-screen-overlay');
    $this->assertSession()->assertNoElementAfterWait('css', '.loading-screen-overlay');

    // Wait for the GrapesJS iframe to appear.
    $gjsFrame = $this->assertSession()->waitForElementVisible('css', 'iframe.gjs-frame');
    $this->assertNotNull($gjsFrame, 'GrapesJS iframe must become visible in the PD editor.');

    // Wait for the iframe document to be fully loaded.
    $this->getSession()->wait(
      15000,
      "document.querySelector('iframe.gjs-frame') && document.querySelector('iframe.gjs-frame').contentDocument.readyState === 'complete'"
    );

    $this->captureScreenshot();

    // The text element must appear inside the GrapesJS canvas iframe.
    // data-entity-id is set by ElementViewBuilder on every rendered element.
    $textInCanvas = $this->getSession()->wait(
      10000,
      "document.querySelector('iframe.gjs-frame') && document.querySelector('iframe.gjs-frame').contentDocument.querySelector('[data-entity-id=\"" . $textElementId . "\"]') !== null"
    );
    $this->assertTrue((bool) $textInCanvas, 'The text element must appear in the GrapesJS canvas with its data-entity-id attribute.');

    // The container must be present in the canvas with the correct type.
    $containerInCanvas = $this->getSession()->evaluateScript(
      "document.querySelector('iframe.gjs-frame') ? document.querySelector('iframe.gjs-frame').contentDocument.querySelectorAll('[data-gjs-type=\"container\"]').length : 0"
    );
    $this->assertGreaterThan(0, (int) $containerInCanvas, 'The container element must appear in the GrapesJS canvas.');

    // --- Phase 2: Test the close/navigation and verify frontend render ---
    // The close button navigates back to the node canonical URL.
    $closeButton = $this->assertSession()->waitForElementVisible('css', '.gjs-pn-btn.fas.fa-times');
    $this->assertNotNull($closeButton, 'The PD editor close button must be visible.');
    $this->captureScreenshot();
    $closeButton->click();

    // After closing we should be on the canonical node URL.
    $this->assertSession()->waitForElementRemoved('css', 'iframe.gjs-frame');

    $this->captureScreenshot();

    // The text element must render on the public-facing node page.
    // In public render mode ElementViewBuilder sets id="pd-cp-{id}" on every
    // element, and the container gets class="pd-content pd-live".
    $this->assertSession()->elementExists('css', 'section.pd-content.pd-live');
    $this->assertSession()->elementExists('css', '#pd-cp-' . $textElementId);
  }

  /**
   * Creates a test node with a text element and returns the relevant IDs.
   *
   * Uses the PHP API directly to set up content — this avoids flaky drag-and-drop
   * in tests while still exercising the same data structures the editor creates.
   *
   * @return array{0: \Drupal\node\Entity\Node, 1: string, 2: int}
   *   The node, field name, and text element entity ID.
   */
  protected function createNodeWithTextElement(): array {
    /** @var \Drupal\pagedesigner\PagedesignerServiceInterface $pdService */
    $pdService = \Drupal::service('pagedesigner.service');
    /** @var \Drupal\pagedesigner\Service\ElementHandler $elementHandler */
    $elementHandler = \Drupal::service('pagedesigner.service.element_handler');
    /** @var \Drupal\ui_patterns\UiPatternsManager $patternManager */
    $patternManager = \Drupal::service('plugin.manager.ui_patterns');

    $patterns = $patternManager->getDefinitions();
    $this->assertArrayHasKey('text', $patterns, 'The "text" pattern must exist on all Pagedesigner projects.');

    // Find the first PD-enabled content type.
    $nodeTypes = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
    $node = NULL;
    $fieldName = NULL;

    foreach ($nodeTypes as $nodeType) {
      $tempNode = Node::create(['type' => $nodeType->id(), 'title' => 'PD edit-save test']);
      $pdFields = $pdService->getPagedesignerFields($tempNode);
      if (!empty($pdFields)) {
        $tempNode->setPublished()->save();
        $this->markEntityForCleanup($tempNode);
        $node = $tempNode;
        $fieldName = array_key_first($pdFields);
        break;
      }
    }

    $this->assertNotNull($node, 'A PD-enabled content type must exist on this site.');

    $container = $pdService->getContainer($node, $fieldName);
    $this->assertNotNull($container, 'A container must exist for the test node.');

    // Add a text element — this matches what the PD editor creates via REST POST.
    // Populate field_content so the text is visible in screenshots.
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
          'value' => '<p>Pagedesigner edit/save test — automated regression check.</p>',
          'format' => filter_default_format(),
        ]);
        $child->save();
        break;
      }
    }
    $textElement->save();
    $container->children->appendItem($textElement);
    $container->save();

    return [$node, $fieldName, (int) $textElement->id()];
  }

}
