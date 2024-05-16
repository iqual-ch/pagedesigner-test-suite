<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Url;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * A model test case using traits from Drupal Test Traits.
 */
class NodeCreationTest extends ExistingSiteBase {

  protected function setUp(): void {
    parent::setUp();

    // Cause tests to fail if an error is sent to Drupal logs.
    $this->failOnLoggedErrors();
  }

  /**
   * An example test method; note that Drupal API's and Mink are available.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testLlama() {
    // Creates a user. Will be automatically cleaned up at the end of the test.
    $author = $this->createUser([], NULL, TRUE);

    // Create a "Llama" article.
    // Will be automatically cleaned up at end of test.
    $node = $this->createNode([
      'title' => 'Llama',
      'type' => 'page',
      'uid' => $author->id(),
    ]);
    $node->setPublished()->save();
    $this->assertEquals($author->id(), $node->getOwnerId());

    // We can browse pages.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

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

    $this->drupalGet($node->toUrl('edit-form'));
  }

}
