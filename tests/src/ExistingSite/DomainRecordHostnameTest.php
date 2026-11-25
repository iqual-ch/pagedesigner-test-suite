<?php

namespace PagedesignerTestSuite\Tests\ExistingSite;

/**
 * Tests that the exported domain records carry no local hostname.
 *
 * Development environments override the hostname of a domain record in their
 * settings. When such a record is saved with the override applied, for example
 * by an update hook, the local hostname ends up in the config export and breaks
 * the domain handling on production.
 */
class DomainRecordHostnameTest extends PagedesignerTestBase {

  /**
   * Tests the hostnames of the domain records in the config sync directory.
   *
   * Reads the sync storage, as the active configuration legitimately carries
   * the local hostname through the settings override.
   */
  public function testExportedDomainRecordsHaveNoLocalHostname(): void {
    $storage = \Drupal::service('config.storage.sync');
    $names = $storage->listAll('domain.record.');
    if (!$names) {
      $this->markTestSkipped('The site exports no domain records.');
    }

    foreach ($storage->readMultiple($names) as $name => $record) {
      $hostname = $record['hostname'] ?? '';
      $this->assertDoesNotMatchRegularExpression(
        '/(^|\.)(ddev\.site|localhost)(:\d+)?$/i',
        $hostname,
        "The exported '$name' must not have the local hostname '$hostname'."
      );
    }
  }

}
