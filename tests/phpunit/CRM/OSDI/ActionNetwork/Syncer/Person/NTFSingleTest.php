<?php

use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_NtfActionNetwork_ExtensionUtil as E;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_Syncer_Person_NTFSingleTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  /**
   * @var array{Contact: array, OptionGroup: array, OptionValue: array, CustomGroup: array, CustomField: array}
   */
  private static $createdEntities = [];

  /**
   * @var \Civi\Osdi\ActionNetwork\RemoteSystem
   */
  public static $system;

  /**
   * @var \Civi\Osdi\ActionNetwork\Mapper\Reconciliation2022May001
   */
  public static $mapper;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('osdi-client')
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass(): void {
    //$osdiClientExtDir = dirname(CRM_Extension_System::singleton()
    //  ->getMapper()->keyToPath('osdi-client'));
    //require_once "$osdiClientExtDir/tests/phpunit/CRM/OSDI/ActionNetwork/TestUtils.php";

    require_once CRM_OSDI_ExtensionUtil::path('tests/phpunit/CRM/OSDI/ActionNetwork/TestUtils.php');
    require_once E::path('tests/phpunit/CRM/OSDI/ActionNetwork/Fixture.php');

    self::setUpCustomConfig();
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    \CRM_OSDI_ActionNetwork_Fixture::setUpGeocoding();

    parent::setUpBeforeClass();
  }

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {
    foreach (self::$createdEntities as $type => $ids) {
      foreach ($ids as $id) {
        civicrm_api4($type, 'delete', [
          'where' => [['id', '=', $id]],
          'checkPermissions' => FALSE,
        ]);
      }
    }

    parent::tearDownAfterClass();
  }

  public static function setUpCustomConfig(): void {
    CRM_OSDI_ActionNetwork_Fixture::setUpCustomPhoneType();
    CRM_OSDI_ActionNetwork_Fixture::setUpCustomField();
    \CRM_Core_Config::singleton()->defaultContactCountry = 1228;
  }

  public static function createMapper(\Civi\Osdi\ActionNetwork\RemoteSystem $system) {
    return new Civi\Osdi\ActionNetwork\Mapper\Reconciliation2022May001($system);
  }

  public function testSyncFromANIfNeeded() {
    $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
    $remotePerson->emailAddress->set('testSyncFromANIfNeeded@null.net');
    $remotePerson->givenName->set('Test');
    $remotePerson->familyName->set('Sync From AN If Needed');
    $remotePerson->postalCode->set('59801');
    $remotePerson->save();

    $syncState = \Civi\Osdi\PersonSyncState::getForRemotePerson($remotePerson, NULL);

    self::assertEmpty($syncState->getId());

    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person\N2FSingle(self::$system);
    $syncer->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail($syncer, NULL));
    $syncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\NineToFive2022June(self::$system));
    $syncResult = $syncer->syncFromRemoteIfNeeded($remotePerson);

    self::assertEquals(\Civi\Osdi\SyncResult::class, get_class($syncResult));

    $localPerson = $syncResult->getLocalObject();
    $syncStateId = $syncResult->getState()->getId();

    self::assertEquals($syncResult::SUCCESS, $syncResult->getStatusCode());
    self::assertEqualsIgnoringCase(
      'testSyncFromANIfNeeded@null.net',
      $localPerson->emailEmail->get());
    self::assertNotEquals(
      '94110',
      $localPerson->addressPostalCode->get());
    self::assertGreaterThan(0, $syncStateId);

    $syncResult = $syncer->syncFromRemoteIfNeeded($remotePerson);

    self::assertEquals($syncResult::NO_SYNC_NEEDED, $syncResult->getStatusCode());

    $remotePerson->postalCode->set('94110');
    $remotePerson->save();
    $syncResult = $syncer->syncFromRemoteIfNeeded($remotePerson);

    self::assertEquals($syncResult::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(
      '94110',
      $syncResult->getLocalObject()->addressPostalCode->get());
  }

  public function testSyncFromCiviIfNeeded() {
    $localPerson = new LocalPerson();
    $localPerson->emailEmail->set('testSyncFromCiviIfNeeded@null.net');
    $localPerson->firstName->set('Test');
    $localPerson->lastName->set('Sync From Civi If Needed');
    $localPerson->addressPostalCode->set('59801');
    $localPerson->save();

    $syncState = \Civi\Osdi\PersonSyncState::getForLocalPerson($localPerson, NULL);

    self::assertEmpty($syncState->getId());

    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person\N2FSingle(self::$system);
    $syncer->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail($syncer, NULL));
    $syncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\NineToFive2022June(self::$system));
    $syncResult = $syncer->syncFromLocalIfNeeded($localPerson);

    self::assertEquals(\Civi\Osdi\SyncResult::class, get_class($syncResult));

    $remotePerson = $syncResult->getRemoteObject();
    $syncStateId = $syncResult->getState()->getId();

    self::assertEquals($syncResult::SUCCESS, $syncResult->getStatusCode());
    self::assertEqualsIgnoringCase(
      'testSyncFromCiviIfNeeded@null.net',
      $remotePerson->emailAddress->get());
    self::assertNotEquals(
      '94110',
      $remotePerson->postalCode->get());
    self::assertGreaterThan(0, $syncStateId);

    $syncResult = $syncer->syncFromLocalIfNeeded($localPerson);

    self::assertEquals($syncResult::NO_SYNC_NEEDED, $syncResult->getStatusCode());

    sleep(1);
    $localPerson->addressPostalCode->set('94110');
    $localPerson->save();
    $syncResult = $syncer->syncFromLocalIfNeeded($localPerson);

    self::assertEquals($syncResult::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(
      '94110',
      $syncResult->getRemoteObject()->postalCode->get());
  }

}
