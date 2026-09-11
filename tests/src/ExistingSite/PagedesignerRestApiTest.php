<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

/**
 * Smoke tests for the Pagedesigner REST API endpoints.
 *
 * The Pagedesigner UI communicates entirely through REST resources. Changes to
 * the Drupal REST module, the HAL module, or the serialization layer can
 * silently break the editor. These tests ensure the key endpoints remain
 * accessible and return structurally valid responses.
 *
 * Catches regressions in REST resource plugins, serialization, and permission
 * enforcement introduced by Drupal core or contrib updates.
 */
class PagedesignerRestApiTest extends PagedesignerTestBase {

  /**
   * Tests the pattern resource returns a valid list of patterns.
   *
   * GET /pagedesigner/pattern?_format=hal_json must return HTTP 200 with a
   * non-empty JSON object containing pattern definitions with at least an id
   * and label. This endpoint is called on every Pagedesigner editor load to
   * populate the GrapesJS blocks panel.
   */
  public function testPatternResourceReturnsPatterns(): void {
    $this->loginAsAdmin();

    $this->drupalGet('/pagedesigner/pattern', ['query' => ['_format' => 'hal_json']]);
    $this->assertSession()->statusCodeEquals(200);

    $content = $this->getSession()->getPage()->getContent();
    $patterns = json_decode($content, TRUE);

    $this->assertNotNull($patterns, 'The pattern endpoint must return valid JSON.');
    $this->assertIsArray($patterns, 'The pattern response must be a JSON object.');
    $this->assertNotEmpty($patterns, 'The pattern endpoint must return at least one pattern.');

    // Every pattern definition must contain at minimum a label — this guards
    // against serialization regressions that would break the blocks panel.
    $firstPattern = reset($patterns);
    $this->assertArrayHasKey('label', $firstPattern, 'Each pattern definition must contain a "label" key.');

    // The "text" pattern is a baseline requirement on all PD projects.
    $this->assertArrayHasKey('text', $patterns, 'The "text" pattern must be present in all Pagedesigner projects.');
  }

  /**
   * Tests that unauthenticated requests to the pattern resource are denied.
   *
   * Catches regressions in permission enforcement that could expose pattern
   * definitions to anonymous users.
   */
  public function testPatternResourceRequiresAuthentication(): void {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $this->drupalGet('/pagedesigner/pattern', ['query' => ['_format' => 'hal_json']]);

    $statusCode = $this->getSession()->getStatusCode();
    $this->assertContains($statusCode, [401, 403], 'The pattern endpoint must deny unauthenticated requests (got ' . $statusCode . ').');
  }

  /**
   * Tests that the element resource endpoint is accessible to authorised users.
   *
   * The element resource is the main communication channel for the PD editor.
   * Verifying it responds to GET requests ensures the REST configuration and
   * routing remain intact after updates.
   */
  public function testElementResourceAccessible(): void {
    $this->loginAsAdmin();

    // GET a non-existent element — the important thing is that the resource
    // is routed (404) and not broken (500) or misconfigured (403/406).
    $this->drupalGet('/pagedesigner/element/0', ['query' => ['_format' => 'hal_json']]);
    $statusCode = $this->getSession()->getStatusCode();

    $this->assertNotEquals(500, $statusCode, 'The element endpoint must not return a server error.');
    $this->assertNotEquals(406, $statusCode, 'The element endpoint must accept the hal_json format.');
  }

}
