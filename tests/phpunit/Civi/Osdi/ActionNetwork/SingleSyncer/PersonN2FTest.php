<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi;
use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use CRM_NtfActionNetwork_ExtensionUtil as E;
use OsdiClient\ActionNetwork\PersonMatchingFixture as PersonMatchFixture;

/**
 * @group headless
 *
 * osdi-client classes should be autoloaded --
 * see code in <extension-root>/tests/phpunit/bootstrap.php
 */
class PersonN2FTest extends Civi\Osdi\ActionNetwork\SingleSyncer\PersonTestAbstract implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('osdi-client')
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass(): void {
    $apiToken = \OsdiClient\ActionNetwork\TestUtils::defineActionNetworkApiToken();

    $syncProfile = \Civi\Api4\OsdiSyncProfile::create(FALSE)
      ->addValue('is_default', TRUE)
      ->addValue('entry_point', 'https://actionnetwork.org/api/v2/')
      ->addValue('api_token', $apiToken)
      ->addValue('classes', [
        'LocalObject' => ['Person' => Civi\Osdi\LocalObject\PersonN2F::class],
        'Mapper' => ['Person' => \Civi\Osdi\ActionNetwork\Mapper\PersonN2F2022June::class],
        'Matcher' => ['Person' => \Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail::class],
        'SingleSyncer' => ['Person' => Civi\Osdi\ActionNetwork\SingleSyncer\PersonN2F::class],
      ])
      ->execute()->single();

    $container = Civi\OsdiClient::containerWithDefaultSyncProfile(TRUE);

    self::$remoteSystem = $container
      ->getSingle('RemoteSystem', 'ActionNetwork');

    self::$syncer = $container->getSingle('SingleSyncer', 'Person', self::$remoteSystem);
    self::assertEquals(PersonN2F::class, get_class(self::$syncer));
    self::$syncer->setSyncProfile($syncProfile);

    PersonMatchFixture::$personClass = ANPerson::class;
    PersonMatchFixture::$remoteSystem = self::$remoteSystem;

    self::setUpCustomConfig();

    \CRM_OSDI_ActionNetwork_Fixture::setUpGeocoding();

    \Civi\Api4\OsdiPersonSyncState::delete(FALSE)
      ->addWhere('id', '>', 0)
      ->execute();
  }

  public static function setUpCustomConfig(): void {
    \CRM_OSDI_ActionNetwork_Fixture::setUpCustomPhoneType();
    \CRM_OSDI_ActionNetwork_Fixture::setUpCustomField();
    \CRM_Core_Config::singleton()->defaultContactCountry = 1228;
  }

  public function testMatchAndSyncIfEligible_FromLocal_AddedAddress_FailsDueToNoZip() {
    self::markTestSkipped('In this custom extension, we handle missing ZIPs.');
  }

}
