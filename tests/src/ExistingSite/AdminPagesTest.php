<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;

/**
 * A model test case using traits from Drupal Test Traits.
 */
class AdminPagesTest extends PagedesignerTestBase {

  /**
   * An example test method; note that Drupal API's and Mink are available.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testAdminPages() {
    $this->loginAsAdmin();

    // Get the front page node ID from the site configuration.
    $front_page_path = \Drupal::config('system.site')->get('page.front');
    $front_page_node = \Drupal::service('path.validator')->getUrlIfValid($front_page_path);
    // A front page that is a view or a custom route is a legitimate
    // configuration, not a regression, so its absence only drops this one
    // assertion. The admin routes below do not depend on it and are checked
    // either way.
    if ($front_page_node && $front_page_node->isRouted() && $front_page_node->getRouteName() == 'entity.node.canonical') {
      $node_id = $front_page_node->getRouteParameters()['node'];

      // Load the node.
      $node = Node::load($node_id);
      $this->assertNotNull($node, 'Front page node loaded successfully.');

      // Generate the edit form URL and visit it.
      $edit_url = $node->toUrl('edit-form');
      $this->drupalGet($edit_url);
      $this->assertSession()->statusCodeEquals(200);
    }

    // We can browse admin pages, and the Pagedesigner admin routes must remain
    // accessible after core or contrib updates. The account is a full
    // administrator, so anything but a 200 here - including a route that no
    // longer exists - is a regression, not a site-specific permission model.
    $routes = [
      'system.admin_content',
      'pagedesigner.admin',
      'pagedesigner.settings',
      'entity.pagedesigner_content.collection',
      'entity.pagedesigner_type.collection',
    ];

    foreach ($routes as $routeName) {
      $this->drupalGet(Url::fromRoute($routeName));
      $this->assertSession()->statusCodeEquals(200, "Route '$routeName' must return 200.");
    }
  }

  /**
   * Tests that anonymous users are denied access to the Pagedesigner editor.
   *
   * Catches regressions in the permission check on pagedesigner.node.edit_mode
   * that could allow unauthenticated access to the page builder.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testAnonymousCannotAccessPagedesignerEditor(): void {
    // Ensure no session is active.
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    // Find the front-page node to use as the test target.
    $frontPath = \Drupal::config('system.site')->get('page.front');
    $frontUrl = \Drupal::service('path.validator')->getUrlIfValid($frontPath);
    if (!$frontUrl || !$frontUrl->isRouted() || $frontUrl->getRouteName() !== 'entity.node.canonical') {
      $this->markTestSkipped('Front page is not a node; skipping anonymous PD access test.');
    }

    $nid = $frontUrl->getRouteParameters()['node'];
    $this->drupalGet('/node/' . $nid . '/pagedesigner');

    $statusCode = $this->getSession()->getStatusCode();
    $this->assertContains(
      $statusCode,
      [302, 403],
      'Anonymous users must not access /node/{nid}/pagedesigner (got ' . $statusCode . ').'
    );
  }

}
