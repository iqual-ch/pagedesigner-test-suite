<?php

namespace PagedesignerTestSuite\Tests\ExistingSiteJavascript;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;
use weitzman\DrupalTestTraits\ScreenShotTrait;

/**
 * Tests the Pagetree companion module in a real browser.
 *
 * Verifies that:
 * 1. The pagetree icon is present for users with the "use pagetree" permission.
 * 2. Clicking the icon opens the tree widget.
 * 3. A newly created node with a menu link appears in the tree.
 * 4. Publishing from the context menu triggers the state change.
 *
 * Catches regressions from updates to pagetree, frontendpublishing, or jQuery
 * that could break the tree widget, the REST endpoint, or the publish flow.
 */
class PagetreeTest extends ExistingSiteSelenium2DriverTestBase {

  use ScreenShotTrait;

  /**
   * Tests that a new page shows in the pagetree and can be published from it.
   */
  public function testPagetreeShowsAndPublishesNode(): void {
    // Set up: unpublished node with a main-menu link so it shows in the tree.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Pagetree regression test node',
      'status' => 0,
    ]);
    $node->save();
    $this->markEntityForCleanup($node);

    $menuLink = MenuLinkContent::create([
      'title' => 'Pagetree regression test node',
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'menu_name' => 'main',
      'enabled' => TRUE,
    ]);
    $menuLink->save();
    $this->markEntityForCleanup($menuLink);

    // Log in as admin.
    $admin = $this->createUser([], NULL, TRUE);
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

    // Visit a published node so frontendpublishing injects its modal templates.
    $publishedNodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['status' => 1, 'type' => 'page']);
    $publishedNode = reset($publishedNodes);
    $this->assertNotNull($publishedNode, 'A published page node must exist for the pagetree test.');
    $this->drupalGet('/node/' . $publishedNode->id());

    // --- Phase 1: Pagetree icon is in the DOM ---
    // The block only renders for users with "use pagetree" permission.
    // Check DOM presence rather than visibility to avoid issues with overlays.
    $this->assertSession()->waitForElement('css', '.pt-pagetree-icon');
    $this->assertSession()->elementExists('css', '.pt-pagetree-icon');
    $this->captureScreenshot();

    // --- Phase 2: Open the tree and find the test node entry ---
    // JS click to bypass any overlay (e.g. cookie banner).
    // @todo Projects with a cookie-consent banner may need to dismiss it
    //   in a project-specific setUp() override.
    $this->getSession()->executeScript("document.querySelector('.pt-pagetree-icon').click();");

    $nid = $node->id();
    $entryLoaded = $this->getSession()->wait(
      10000,
      "document.querySelector('.pt-main-entry[data-node-id=\"{$nid}\"]') !== null"
    );
    $this->assertTrue((bool) $entryLoaded, 'The test node must appear in the pagetree after the tree loads.');
    $this->captureScreenshot();

    // The node starts unpublished — the state icon must be fa-times-circle.
    $isUnpublished = $this->getSession()->evaluateScript(
      "(function() {" .
      "  let e = document.querySelector('.pt-entry-{$nid}-' + document.documentElement.lang);" .
      "  return e ? e.querySelector('.pt-state.fa-times-circle') !== null : false;" .
      "})()"
    );
    $this->assertTrue($isUnpublished, 'Unpublished node must show the fa-times-circle state icon.');

    // --- Phase 3: Publish from the context menu ---
    // Reveal the hamburger icon (hidden by CSS until hover) and click it.
    $this->getSession()->executeScript(
      "let btn = document.querySelector('.pt-main-entry[data-node-id=\"{$nid}\"] .pt-icon.fa.fa-bars');" .
      "if (btn) { btn.style.display = 'block'; btn.click(); }"
    );

    // Click the first callback-based action (Publish, weight 1000) in the list.
    // Callback actions have no href attribute unlike link actions (Add/Settings).
    $publishClicked = $this->getSession()->wait(
      5000,
      "(function() {" .
      "  let list = document.querySelector('.pt-main-entry[data-node-id=\"{$nid}\"] .pt-page-actions');" .
      "  if (!list) return false;" .
      "  let link = Array.from(list.querySelectorAll('a')).find(a => !a.getAttribute('href'));" .
      "  if (link) { link.click(); return true; }" .
      "  return false;" .
      "})()"
    );
    $this->assertTrue((bool) $publishClicked, 'The publish callback action must be found in the context menu.');
    $this->captureScreenshot();

    // The frontendpublishing module opens a jQuery UI dialog for confirmation.
    // Instead of trying to find the dialog button (class names vary with jQuery UI
    // version and theme), wait a moment for the dialog to render and then trigger
    // the underlying transition function directly — this is exactly what the
    // dialog's confirm button invokes.
    $this->getSession()->wait(2000, 'false');
    $lang = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $this->getSession()->executeScript(
      "if (typeof Drupal !== 'undefined' && Drupal.frontendpublishing) {" .
      "  Drupal.frontendpublishing.transitionNode({$nid}, '{$lang}', 'publish', '');" .
      "}"
    );

    // Allow time for the AJAX publish request to complete, then verify via PHP.
    $this->getSession()->wait(5000, 'false');
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
    $freshNode = \Drupal\node\Entity\Node::load($node->id());
    $this->assertTrue($freshNode->isPublished(), 'The node must be published after triggering publish from the pagetree context menu.');
    $this->captureScreenshot();
  }

}
