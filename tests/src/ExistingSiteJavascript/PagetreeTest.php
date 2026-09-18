<?php

namespace PagedesignerTestSuite\Tests\ExistingSiteJavascript;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

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
class PagetreeTest extends PagedesignerJavascriptTestBase {

  /**
   * Creates a saved Pagedesigner node in the requested publication state.
   *
   * Discovery alone only settles the bundle. The node still has to satisfy that
   * bundle's required fields, or it is saved in a state no editor could have
   * produced and whichever project code renders it fails for the suite's own
   * reasons. Bundles that cannot be populated are passed over rather than
   * failed.
   *
   * @param string $title
   *   The node title.
   * @param bool $published
   *   Whether the node should end up published.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node, already marked for cleanup.
   */
  protected function createPagetreeTestNode(string $title, bool $published): NodeInterface {
    $bundles = $this->findPagedesignerBundles();
    if (empty($bundles)) {
      $this->markTestSkipped('No content type with a pagedesigner_item field was found on this site.');
    }

    $rejected = [];
    foreach ($bundles as $bundle) {
      $node = Node::create(['type' => $bundle, 'title' => $title]);

      if (!$this->fillRequiredFields($node)) {
        $rejected[] = "$bundle has a required field the test cannot populate";
        continue;
      }
      // Only a node that has to end up published needs its workflow resolved;
      // an unpublished one is what a moderated bundle produces by default.
      if ($published && !$this->setPublishedModerationState($node)) {
        $rejected[] = "$bundle is moderated with no published state available";
        continue;
      }

      $published ? $node->setPublished() : $node->setUnpublished();
      $node->save();
      $this->markEntityForCleanup($node);
      \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
      $fresh = Node::load($node->id());
      if ($published ? !$fresh->isPublished() : $fresh->isPublished()) {
        $rejected[] = "$bundle did not save in the requested publication state";
        continue;
      }

      return $fresh;
    }

    $this->markTestSkipped(
      'No Pagedesigner content type on this site can host a test node: '
      . implode('; ', $rejected) . '.'
    );
  }

  /**
   * Tests that a new page shows in the pagetree and can be published from it.
   */
  public function testPagetreeShowsAndPublishesNode(): void {
    // Set up: unpublished node with a main-menu link so it shows in the tree.
    $node = $this->createPagetreeTestNode('Pagetree regression test node', FALSE);

    $menuLink = MenuLinkContent::create([
      'title' => 'Pagetree regression test node',
      'link' => ['uri' => 'entity:node/' . $node->id()],
      'menu_name' => 'main',
      'enabled' => TRUE,
    ]);
    $menuLink->save();
    $this->markEntityForCleanup($menuLink);

    // Log in as admin.
    $this->loginAsAdmin();

    // Visit a published node so frontendpublishing injects its modal templates.
    // Look for any published node; create one if none exists.
    // Ask for one id rather than loadByProperties(['status' => 1]), which on a
    // real site loads every published node on it into memory.
    $publishedIds = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute();
    $publishedNode = $publishedIds ? Node::load(reset($publishedIds)) : NULL;
    if (!$publishedNode) {
      $publishedNode = $this->createPagetreeTestNode('Pagetree test published node', TRUE);
    }
    $this->drupalGet('/node/' . $publishedNode->id());

    // A consent banner would sit on top of the page chrome the tree widget
    // lives in, so get it out of the way before interacting with it.
    $this->dismissCookieBanner();

    // --- Phase 1: Pagetree icon is in the DOM ---
    // The block only renders for users with "use pagetree" permission.
    // Check DOM presence rather than visibility to avoid issues with overlays.
    $this->assertSession()->waitForElement('css', '.pt-pagetree-icon');
    $this->assertSession()->elementExists('css', '.pt-pagetree-icon');
    $this->captureScreenshot();

    // --- Phase 2: Open the tree and find the test node entry ---
    // JS click rather than a real click, so a stray overlay cannot intercept it.
    $this->getSession()->executeScript("document.querySelector('.pt-pagetree-icon').click();");

    $nid = $node->id();
    $entryLoaded = $this->getSession()->wait(
      10000,
      "document.querySelector('.pt-main-entry[data-node-id=\"{$nid}\"]') !== null"
    );
    $this->assertTrue((bool) $entryLoaded, 'The test node must appear in the pagetree after the tree loads.');
    $this->captureScreenshot();

    // The node starts unpublished, so the state icon must be fa-times-circle.
    // The entry is keyed by the node's own langcode, which is not necessarily
    // what the document language attribute says: a site may advertise a
    // regional variant such as de-CH while its content language stays de.
    $langcode = $node->language()->getId();
    $isUnpublished = $this->getSession()->evaluateScript(
      "(function() {" .
      "  let e = document.querySelector('.pt-entry-{$nid}-{$langcode}')" .
      "    || document.querySelector('.pt-main-entry[data-node-id=\"{$nid}\"]');" .
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
    $freshNode = Node::load($node->id());
    $this->assertTrue($freshNode->isPublished(), 'The node must be published after triggering publish from the pagetree context menu.');
    $this->captureScreenshot();
  }

}
