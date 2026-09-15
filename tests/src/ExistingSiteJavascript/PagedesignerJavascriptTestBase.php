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
 * live database, where an unrelated request can log an error inside the
 * test's window. The ExistingSite tests carry that guard instead.
 */
abstract class PagedesignerJavascriptTestBase extends ExistingSiteSelenium2DriverTestBase {

  use PagedesignerTestNodeTrait;
  use ScreenShotTrait;

  /**
   * Logs in a freshly created administrator through the login form.
   *
   * Deliberately drives the real login form instead of ::drupalLogin(), which
   * on current Drupal cores authenticates through a one-time login URL. Going
   * through the form keeps the full login process covered by the suite.
   *
   * @return \Drupal\user\UserInterface
   *   The administrator that is now logged in.
   */
  protected function loginAsAdmin(): UserInterface {
    $admin = $this->createUser([], NULL, TRUE);
    $this->loginViaForm($admin);
    return $admin;
  }

  /**
   * Logs a user in by submitting the login form in the browser.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account to log in. Its raw password must be set in ::$passRaw, as
   *   ::createUser() does.
   */
  protected function loginViaForm(UserInterface $account): void {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }
    $this->drupalGet('/user/login');
    // Best effort: a consent banner is what usually sits on top of the form.
    $this->dismissCookieBanner();

    $form = $this->assertSession()->elementExists('css', 'form#user-login-form');
    $form->fillField('name', $account->getAccountName());
    $form->fillField('pass', $account->passRaw);

    // Locate the submit button by its name rather than its label: the label is
    // rendered in whatever interface language the browser negotiated, the name
    // is always "op". Core's own ::drupalLogout() does the same. Click it from
    // JS so that an overlay the dismissal above did not know about cannot
    // intercept the click; the form is still submitted by the browser.
    $button = $this->assertSession()->buttonExists('op', $form);
    // Bot protection unlocks a form only on real interaction, which a
    // synthetic click is not.
    $button->mouseOver();
    $this->getSession()->executeScript(
      "document.querySelector('form#user-login-form [name=\"op\"]').click();"
    );
    // A JS click does not block until the resulting navigation has finished.
    $this->assertSession()->waitForElementRemoved('css', 'form#user-login-form');

    $account->sessionId = $this->getSession()->getCookie(
      \Drupal::service('session_configuration')->getOptions(\Drupal::request())['name']
    );
    $this->assertTrue($this->drupalUserIsLoggedIn($account), 'Submitting the login form must log the user in.');
    $this->loggedInUser = $account;
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Dismisses a cookie-consent banner if one is covering the page.
   *
   * Called before submitting the login form and before tests click something
   * in the page chrome.
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
