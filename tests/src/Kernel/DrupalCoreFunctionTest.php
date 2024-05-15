<?php

namespace PagedesignerTestSuite\Tests\Unit;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Config\Schema\SchemaCheckTrait;

/**
 * Tests the drupal_get_path core function.
 *
 * @group app
 */
class DrupalCoreFunctionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'config_test', 'user', 'node', 'iq_barrio_helper', 'pagedesigner_responsive_images'];


  /**
   * The extension path resolver service.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected $extensionPathResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->extensionPathResolver = $this->container->get('extension.path.resolver');
  }

  /**
   * Tests the drupal_get_path function for a module.
   */
  public function testModulePath() {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->assertEquals('core/modules/node', $this->extensionPathResolver->getPath('module', 'node'));
  }

}
