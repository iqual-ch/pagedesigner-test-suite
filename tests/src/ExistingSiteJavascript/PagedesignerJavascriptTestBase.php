<?php

namespace PagedesignerTestSuite\Tests\ExistingSiteJavascript;

use Drupal\user\UserInterface;
use PagedesignerTestSuite\Tests\Traits\PagedesignerTestNodeTrait;
use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;
use weitzman\DrupalTestTraits\ScreenShotTrait;

/**
 * Base class for the Pagedesigner browser tests.
 *
 * The browser counterpart of PagedesignerTestBase. Note it deliberately does
 * not call ::failOnLoggedErrors(): these tests drive a real browser against a
 * live client database, where an unrelated request can log an error inside the
 * test's window. The ExistingSite tests carry that guard instead.
 */
abstract class PagedesignerJavascriptTestBase extends ExistingSiteSelenium2DriverTestBase {

  use PagedesignerTestNodeTrait;
  use ScreenShotTrait;

  /**
   * Do not fail a test because the site logged a PHP notice or warning.
   *
   * @see \PagedesignerTestSuite\Tests\ExistingSite\PagedesignerTestBase::$failOnPhpWatchdogMessages
   */
  protected $failOnPhpWatchdogMessages = FALSE;

  /**
   * Logs in a freshly created administrator.
   *
   * Uses ::drupalLogin(), which authenticates through a one-time login URL. In
   * a real browser that is the only reliable option: submitting the login form
   * requires clicking a button whose label depends on the negotiated interface
   * language and which a cookie-consent overlay will happily intercept.
   *
   * @return \Drupal\user\UserInterface
   *   The administrator that is now logged in.
   */
  protected function loginAsAdmin(): UserInterface {
    $admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    return $admin;
  }

  /**
   * Dismisses a cookie-consent banner if one is covering the page.
   *
   * Only needed for tests that click something in the page chrome; logging in
   * no longer goes through the form, so it is not needed for authentication.
   */
  protected function dismissCookieBanner(): void {
    $this->getSession()->executeScript(
      <<<'JS'
      (function () {
        var selectors = [
          '.cc-banner .cc-dismiss', '.cc-banner .cc-allow',
          '[aria-label="dismiss cookie message"]',
          '.cookieconsent .agree', '#cookieconsent .agree'
        ];
        for (var i = 0; i < selectors.length; i++) {
          var el = document.querySelector(selectors[i]);
          if (el) {
            el.click();
            return;
          }
        }
        var banner = document.querySelector('[aria-label="cookieconsent"], .cc-window, .cookieconsent');
        if (banner && banner.parentNode) {
          banner.parentNode.removeChild(banner);
        }
      })();
      JS
    );
  }

}
