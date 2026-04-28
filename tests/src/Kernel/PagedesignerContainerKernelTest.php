<?php

namespace PagedesignerTestSuite\Tests\Kernel;

use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\pagedesigner\Entity\ElementInterface;
use Drupal\pagedesigner\PagedesignerServiceInterface;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the core Pagedesigner container lifecycle.
 *
 * Verifies that saving a node with a pagedesigner_item field automatically
 * creates and associates a container element. This catches regressions in the
 * entity API, the pagedesigner field type, and the PagedesignerService that
 * could be introduced by Drupal core or contrib updates.
 *
 * @group pagedesigner
 */
class PagedesignerContainerKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'rest',
    'serialization',
    'taxonomy',
    'editor',
    'datetime',
    'hal',
    'ui_patterns',
    'ui_patterns_library',
    'restconsumer',
    'pagedesigner',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('node_type');
    $this->installEntitySchema('pagedesigner_element');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['pagedesigner']);
    $this->installEntitySchema('date_format');

    DateFormat::create([
      'id' => 'fallback',
      'pattern' => 'D, m/d/Y - H:i',
      'label' => 'Fallback',
    ])->save();

    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Tests that a container element is auto-created on node save.
   *
   * Covers PagedesignerService::addContainer, the pagedesigner_item field type,
   * and the element entity schema.
   */
  public function testContainerCreatedOnNodeSave(): void {
    $bundle = 'pd_kernel_test';
    // Use a unique name to avoid conflict with the field storage shipped in
    // pagedesigner's config/install (field.storage.node.field_pagedesigner_content).
    $fieldName = 'field_pd_kernel_test';

    NodeType::create(['type' => $bundle, 'name' => 'PD Kernel Test'])->save();

    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'type' => 'pagedesigner_item',
      'settings' => ['target_type' => 'pagedesigner_element'],
    ])->save();

    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('node', $fieldName),
      'field_type' => 'pagedesigner_item',
      'bundle' => $bundle,
      'settings' => [
        'title' => DRUPAL_DISABLED,
        'target_type' => 'pagedesigner_element',
        'handler' => 'default:pagedesigner_element',
        'handler_settings' => ['target_bundles' => ['container' => 'container']],
      ],
    ])->save();

    $node = $this->createNode(['type' => $bundle, 'title' => 'PD kernel test node']);
    $node->save();

    // A container element must be auto-created and referenced by the field.
    $this->assertFalse($node->get($fieldName)->isEmpty(), 'The pagedesigner_item field must not be empty after saving the node.');

    $container = $node->get($fieldName)->entity;
    $this->assertInstanceOf(ElementInterface::class, $container, 'The referenced entity must be a Pagedesigner Element.');
    $this->assertEquals('container', $container->bundle(), 'The auto-created element must be a container bundle.');
    $this->assertEquals($node->id(), $container->entity->target_id, 'The container must reference the parent node.');
    $this->assertEquals($node->language()->getId(), $container->langcode->value, 'The container langcode must match the node langcode.');
  }

  /**
   * Tests PagedesignerServiceInterface::getPagedesignerFields() discovery.
   *
   * Verifies the service correctly reports fields of type pagedesigner_item,
   * and ignores unrelated fields. Catches regressions in field type detection.
   */
  public function testGetPagedesignerFieldsDiscovery(): void {
    $bundle = 'pd_field_discovery_test';
    $fieldName = 'field_pd_discovery';

    NodeType::create(['type' => $bundle, 'name' => 'PD Discovery Test'])->save();

    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'type' => 'pagedesigner_item',
      'settings' => ['target_type' => 'pagedesigner_element'],
    ])->save();

    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('node', $fieldName),
      'field_type' => 'pagedesigner_item',
      'bundle' => $bundle,
      'settings' => [
        'title' => DRUPAL_DISABLED,
        'target_type' => 'pagedesigner_element',
        'handler' => 'default:pagedesigner_element',
        'handler_settings' => ['target_bundles' => ['container' => 'container']],
      ],
    ])->save();

    $node = $this->createNode(['type' => $bundle, 'title' => 'Discovery test node']);
    $node->save();

    /** @var \Drupal\pagedesigner\PagedesignerServiceInterface $service */
    $service = $this->container->get('pagedesigner.service');
    $this->assertInstanceOf(PagedesignerServiceInterface::class, $service);

    $pdFields = $service->getPagedesignerFields($node);

    $this->assertArrayHasKey($fieldName, $pdFields, 'getPagedesignerFields() must return the pagedesigner_item field.');
    $this->assertCount(1, $pdFields, 'getPagedesignerFields() must only return pagedesigner_item fields.');
  }

}
