<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests access control for Pagedesigner routes.
 *
 * Catches regressions in the permission system and routing that could be
 * introduced by Drupal core or contrib module updates.
 */
class PagedesignerAccessTest extends ExistingSiteBase {

  /**
   * The node ID used for access testing.
   *
   * @var int
   */
  protected int $testNodeId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->failOnLoggedErrors();
    $this->testNodeId = $this->getPagedesignerNodeId();
  }

  /**
   * Tests that anonymous users cannot access the Pagedesigner editor.
   */
  public function testAnonymousAccessDenied(): void {
    // Ensure no user is logged in.
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $this->drupalGet('/node/' . $this->testNodeId . '/pagedesigner');

    // Anonymous users must be redirected to login or receive a 403.
    $statusCode = $this->getSession()->getStatusCode();
    $this->assertContains($statusCode, [302, 403], 'Anonymous users must not access /node/{nid}/pagedesigner (got ' . $statusCode . ').');
  }

  /**
   * Tests that authenticated users without the PD permission are denied.
   */
  public function testAuthenticatedWithoutPermissionDenied(): void {
    // Create a user with no special permissions.
    $user = $this->createUser(['access content']);
    $this->drupalLogin($user);

    $this->drupalGet('/node/' . $this->testNodeId . '/pagedesigner');

    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that users with the PD edit permission can access the editor.
   */
  public function testAuthenticatedWithPermissionGranted(): void {
    $user = $this->createUser(['edit pagedesigner element entities', 'access content']);
    $this->drupalLogin($user);

    $this->drupalGet('/node/' . $this->testNodeId . '/pagedesigner');

    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Returns the ID of a node with a pagedesigner_item field.
   *
   * Dynamically finds the first suitable node rather than hardcoding a type,
   * making the test portable across all client projects.
   *
   * @return int
   *   The node ID.
   */
  protected function getPagedesignerNodeId(): int {
    /** @var \Drupal\pagedesigner\PagedesignerServiceInterface $pdService */
    $pdService = \Drupal::service('pagedesigner.service');

    $nodeTypes = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
    foreach ($nodeTypes as $nodeType) {
      $tempNode = Node::create(['type' => $nodeType->id(), 'title' => 'PD access test']);
      $pdFields = $pdService->getPagedesignerFields($tempNode);
      if (!empty($pdFields)) {
        $tempNode->setPublished()->save();
        $this->markEntityForCleanup($tempNode);
        return (int) $tempNode->id();
      }
    }

    $this->fail('No content type with a pagedesigner_item field was found on this site.');
  }

}
