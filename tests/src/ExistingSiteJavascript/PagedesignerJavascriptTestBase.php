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
 *
 * drupal-test-traits still fails a test when any PHP watchdog entry appears
 * during it. A real browser triggers a few that are not errors of the site
 * under test; ::$ignoredPhpWatchdogMessages lists them and ::tearDown() drops
 * them before the check runs.
 */
abstract class PagedesignerJavascriptTestBase extends ExistingSiteSelenium2DriverTestBase {

  use PagedesignerTestNodeTrait;
  use ScreenShotTrait;

  /**
   * Substrings of PHP watchdog messages that do not fail a browser test.
   *
   * - "Image generation in progress": the image module answers 503 while
   *   another request holds the lock for the same derivative. A page with many
   *   uncached image styles makes the browser race for them, and the 503 is
   *   logged as a PHP exception although the derivative is served on retry.
   *
   * @var string[]
   */
  protected array $ignoredPhpWatchdogMessages = [
    'Image generation in progress',
  ];

  /**
   * The highest watchdog id before the test ran; only later rows are dropped.
   *
   * @var int
   */
  protected int $watchdogStartWid = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->useSiteDefaultLanguage();
    $database = \Drupal::database();
    if ($database->schema()->tableExists('watchdog')) {
      $this->watchdogStartWid = (int) $database->select('watchdog', 'w')
        ->fields('w', ['wid'])
        ->orderBy('wid', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->dropIgnoredPhpWatchdogMessages();
    parent::tearDown();
  }

  /**
   * Deletes the PHP watchdog entries listed in ::$ignoredPhpWatchdogMessages.
   *
   * The parent's tearDown fails the test on any PHP entry logged during the
   * test. Only rows written after ::setUp() are touched, so entries that were
   * in the log before the test keep their history. The message text lives in
   * the serialized variables column (the message column only holds the
   * "%type: @message in %function" template), so the match runs on that
   * column. The channel is matched as "php" and "PHP": dblog writes the
   * former, the parent check reads the latter.
   */
  protected function dropIgnoredPhpWatchdogMessages(): void {
    if (!$this->failOnPhpWatchdogMessages || empty($this->ignoredPhpWatchdogMessages)) {
      return;
    }
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('watchdog')) {
      return;
    }
    foreach ($this->ignoredPhpWatchdogMessages as $needle) {
      $like = '%' . $database->escapeLike($needle) . '%';
      $database->delete('watchdog')
        ->condition('wid', $this->watchdogStartWid, '>')
        ->condition('type', ['php', 'PHP'], 'IN')
        ->condition($database->condition('OR')
          ->condition('message', $like, 'LIKE')
          ->condition('variables', $like, 'LIKE'))
        ->execute();
    }
  }

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
    $admin = $this->createSiteAdmin();
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
