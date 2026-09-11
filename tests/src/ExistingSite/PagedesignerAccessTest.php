<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

/**
 * Tests access control for Pagedesigner routes.
 *
 * Catches regressions in the permission system and routing that could be
 * introduced by Drupal core or contrib module updates.
 */
class PagedesignerAccessTest extends PagedesignerTestBase {

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
    [$node] = $this->createPagedesignerTestNode('PD access test');
    $this->testNodeId = (int) $node->id();
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

}
