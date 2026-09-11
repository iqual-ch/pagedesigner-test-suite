<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

use Drupal\user\UserInterface;
use PagedesignerTestSuite\Tests\Traits\PagedesignerTestNodeTrait;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Base class for the Pagedesigner ExistingSite tests.
 *
 * Carries the shared setUp, the login helper and the node-creation helper so
 * that all tests in the suite make the same assumptions about the site they are
 * pointed at.
 */
abstract class PagedesignerTestBase extends ExistingSiteBase {

  use PagedesignerTestNodeTrait;

  /**
   * Do not fail a test because the site logged a PHP notice or warning.
   *
   * DTT's watchdog sweep truncates every `type = 'PHP'` watchdog row in setUp
   * and throws in tearDown if any row reappeared — at any severity, from any
   * request, with no way to attribute a row to the test that caused it. On a
   * live database that turns every pre-existing deprecation or notice
   * into a failure of whichever test happened to run, and on shared CI it picks
   * up concurrent requests too.
   *
   * ::failOnLoggedErrors() below is the guard that is actually wanted: it fails
   * the test on anything logged at ERROR or above, through the logger channel,
   * while the test is running.
   *
   * @var bool
   *
   * @see \weitzman\DrupalTestTraits\ExistingSiteBase::$failOnPhpWatchdogMessages
   */
  protected $failOnPhpWatchdogMessages = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->failOnLoggedErrors();
  }

  /**
   * Logs in a freshly created administrator.
   *
   * Uses ::drupalLogin(), which in Drupal 11 authenticates through a one-time
   * login URL rather than the login form. That matters on an existing site: driving
   * the form makes the test depend on the rendered submit button's label, which
   * varies with the negotiated interface language, and — in a real browser — on
   * the submit button not being covered by a cookie-consent banner.
   *
   * @return \Drupal\user\UserInterface
   *   The administrator that is now logged in.
   */
  protected function loginAsAdmin(): UserInterface {
    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    return $admin;
  }

}
