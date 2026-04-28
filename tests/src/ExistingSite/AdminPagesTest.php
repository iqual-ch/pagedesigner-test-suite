<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * A model test case using traits from Drupal Test Traits.
 */
class AdminPagesTest extends ExistingSiteBase {

  protected function setUp(): void {
    parent::setUp();

    // Cause tests to fail if an error is sent to Drupal logs.
    $this->failOnLoggedErrors();
  }

  /**
   * An example test method; note that Drupal API's and Mink are available.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testAdminPages() {
    // Creates a user. Will be automatically cleaned up at the end of the test.
    $author = $this->createUser([], NULL, TRUE);

    // We can login and browse admin pages.
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $this->drupalGet(Url::fromRoute('user.login'));
    $this->submitForm([
      'name' => $author->getAccountName(),
      'pass' => $author->passRaw,
    ], t('Log in')->__toString());

    // @see ::drupalUserIsLoggedIn()
    $author->sessionId = $this->getSession()->getCookie(\Drupal::service('session_configuration')->getOptions(\Drupal::request())['name']);
    $this->assertTrue($this->drupalUserIsLoggedIn($author), new FormattableMarkup('User %name successfully logged in.', ['%name' => $author->getAccountName()]));

    $this->loggedInUser = $author;
    $this->container->get('current_user')->setAccount($author);

    // Get the front page node ID from the site configuration.
    $front_page_path = \Drupal::config('system.site')->get('page.front');
    $front_page_node = \Drupal::service('path.validator')->getUrlIfValid($front_page_path);
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
    else {
      $this->fail('Could not determine the front page node.');
    }

    // We can browse admin pages.
    $this->drupalGet(Url::fromRoute('system.admin_content'));
    $this->assertSession()->statusCodeEquals(200);

    // --- Pagedesigner-specific admin routes ---
    // These routes are provided by the pagedesigner module and must remain
    // accessible after core or contrib updates.
    $pdAdminRoutes = [
      'pagedesigner.admin',
      'pagedesigner.settings',
      'entity.pagedesigner_content.collection',
      'entity.pagedesigner_type.collection',
    ];

    foreach ($pdAdminRoutes as $routeName) {
      $this->drupalGet(Url::fromRoute($routeName));
      $this->assertSession()->statusCodeEquals(200, "Pagedesigner admin route '$routeName' must return 200.");
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
