<?php

namespace PagedesignerTestSuite\Tests\ExistingSiteJavascript;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Url;
use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;
use weitzman\DrupalTestTraits\ScreenShotTrait;

/**
 * A browser test suitable for testing Ajax and client-side interactions.
 */
class PagedesignerLoadingTest extends ExistingSiteSelenium2DriverTestBase {

  use ScreenShotTrait;

  /**
   * Tests if the Pagedesigner UI loads properly.
   */
  public function testPagedesignerLoadingOnHomepage() {
    $web_assert = $this->assertSession();

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

    // Go to homepage.
    $this->drupalGet(Url::fromRoute('<front>'));

    // Test if the page can be edited with the Pagedesigner.
    $result = $web_assert->waitForElementVisible('css', '.pd-edit-icon');
    $this->assertNotNull($result, "Page cannot be edited with the Pagedesigner.");
    $this->captureScreenshot();

    // Test if pagedesigner can be opened.
    $result->click();
    $this->assertTrue((bool) preg_match('/.+node\/(?P<nid>\d+)\/pagedesigner/', $this->getUrl(), $matches), "Pagedesigner path in the URL not correct.");

    // Test if loading screen disappears.
    $web_assert->waitForElementRemoved('css', '.loading-screen-overlay');
    $web_assert->assertNoElementAfterWait('css', '.loading-screen-overlay');

    // Test if GrapesJS iframe becomes accessible.
    $this->captureScreenshot();
    $result = $web_assert->waitForElementVisible('css', 'iframe.gjs-frame');
    $this->assertNotNull($result, "Pagedesigner did not load.");
    $web_assert->assertVisibleInViewport('css', 'iframe.gjs-frame');

    // Test if iframe content loads.
    $this->getSession()->wait(15000, "document.querySelector('iframe.gjs-frame').contentDocument.readyState === 'complete'");

    // Test if the pagedesigner can be closed.
    $result = $this->getSession()->getPage()->find('css', '.gjs-pn-btn.fas.fa-times');
    $this->assertNotNull($result);
    $this->captureScreenshot();

    // Close the pagedesigner.
    $result->click();
  }

}
