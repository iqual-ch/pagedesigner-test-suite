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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->useSiteDefaultLanguage();
    $this->failOnLoggedErrors();
  }

  /**
   * Logs in a freshly created administrator.
   *
   * Uses ::drupalLogin(). The login form itself is covered by the browser
   * tests, see PagedesignerJavascriptTestBase::loginViaForm().
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
