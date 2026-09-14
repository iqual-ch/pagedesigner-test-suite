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
   * Submit button labels of the login form, per interface language.
   *
   * The form is driven in a real browser, so the label is whatever the site
   * rendered for the negotiated language. Add a translation here if a client
   * site uses one that is missing.
   *
   * @var string[]
   */
  protected const LOGIN_BUTTON_LABELS = [
    'Login',
    'Log in',
    'Anmelden',
    'Se connecter',
    'Accedi',
  ];

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
    // A consent banner would cover the submit button.
    $this->dismissCookieBanner();

    $page = $this->getSession()->getPage();
    $page->fillField('name', $account->getAccountName());
    $page->fillField('pass', $account->passRaw);
    $button = NULL;
    foreach (static::LOGIN_BUTTON_LABELS as $label) {
      if ($button = $page->findButton($label)) {
        break;
      }
    }
    $this->assertNotNull($button, sprintf(
      'The login form must render a submit button labelled one of "%s". Add the missing translation to LOGIN_BUTTON_LABELS.',
      implode('", "', static::LOGIN_BUTTON_LABELS)
    ));
    $button->press();

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
