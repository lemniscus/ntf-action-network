<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi;
use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use OsdiClient\ActionNetwork\PersonMatchingFixture as PersonMatchFixture;
use OsdiClient\ActionNetwork\TestUtils;

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
    TestUtils::createSyncProfile();
    $container = Civi\OsdiClient::container();
    $container->register('LocalObject', 'Person', Civi\Osdi\LocalObject\PersonN2F::class);
    $container->register('Mapper', 'Person', \Civi\Osdi\ActionNetwork\Mapper\PersonN2F2022June::class);
    $container->register('Matcher', 'Person', \Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail::class);
    $container->register('SingleSyncer', 'Person', Civi\Osdi\ActionNetwork\SingleSyncer\PersonN2F::class);

    self::$remoteSystem = $container
      ->getSingle('RemoteSystem', 'ActionNetwork');

    self::$syncer = $container->getSingle('SingleSyncer', 'Person', self::$remoteSystem);
    self::assertEquals(PersonN2F::class, get_class(self::$syncer));

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
