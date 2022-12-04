<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi;
use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use CRM_NtfActionNetwork_ExtensionUtil as E;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;

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
    \Civi\Osdi\Container::register('LocalObject', 'Person',
      Civi\Osdi\LocalObject\PersonN2F::class);

    \Civi\Osdi\Container::register('Mapper', 'Person',
      \Civi\Osdi\ActionNetwork\Mapper\PersonN2F2022June::class);

    \Civi\Osdi\Container::register('Matcher', 'Person',
      \Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail::class);

    \Civi\Osdi\Container::register('SingleSyncer', 'Person',
      Civi\Osdi\ActionNetwork\SingleSyncer\PersonN2F::class);

    require_once E::path('tests/phpunit/CRM/OSDI/ActionNetwork/Fixture.php');

    self::$remoteSystem = \CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    PersonMatchFixture::$personClass = ANPerson::class;
    PersonMatchFixture::$remoteSystem = self::$remoteSystem;

    $syncProfile = \CRM_OSDI_ActionNetwork_TestUtils::createSyncProfile();
    $syncProfile = \Civi\Api4\OsdiSyncProfile::update(FALSE)
      ->addWhere('id', '=', $syncProfile['id'])
      ->addValue(
        'mapper',
        \Civi\Osdi\ActionNetwork\Mapper\PersonN2F2022June::class)
      ->execute()->single();

    self::$syncer = new PersonN2F(self::$remoteSystem);
    self::$syncer->setSyncProfile($syncProfile);

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

  public function testSyncFromLocalIfNeeded_AddedAddress_FailsDueToNoZip() {
    self::markTestSkipped('In this custom extension, we handle missing ZIPs.');
  }

}
