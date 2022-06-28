<?php

use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use \League\Csv\Reader;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_SyncJobTest extends \PHPUnit\Framework\TestCase implements
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
    $osdiClientExtDir = dirname(CRM_Extension_System::singleton()
      ->getMapper()->keyToPath('osdi-client'));
    require_once "$osdiClientExtDir/tests/phpunit/CRM/OSDI/ActionNetwork/TestUtils.php";

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

  public function testGetMultiplePagesOfPeople() {
    $startTime = time();
/*    for ($i = 1; $i < 27; $i++) {
      $person = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
      $person->emailAddress->set("syncjobtest$i@null.org");
      $person->givenName->set('Sync Job Test');
      $person->familyName->set("$i $startTime");
      $person->save();
    }*/

    $result = self::$system->find('osdi:people', [['given_name', 'eq', 'Sync Job Test']]);

    $count = 0;
    $previousPersonId = NULL;

    foreach ($result as $fetchedPerson) {
      self::assertNotEquals($previousPersonId, $fetchedPerson->getId());
      $previousPersonId = $fetchedPerson->getId();
      $count++;
    }

    self::assertGreaterThan(25, $count);
  }

  public function testLoadAll() {
    $result = self::$system->find('osdi:people', [['given_name', 'eq', 'Sync Job Test']]);
    $result->loadAll();
    self::assertGreaterThan(25, $result->rawCurrentCount());
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

    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person\N2F(self::$system);
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

    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person\N2F(self::$system);
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

  public function testBatchSyncFromANDoesNotRunConcurrently() {
    $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
    $remotePerson->emailAddress->set($email = "syncjobtest-no-concurrent@null.org");
    $remotePerson->save();

    Civi::settings()->add([
      'ntfActionNetwork.syncJobProcessId' => getmypid(),
      'ntfActionNetwork.syncJobActNetModTimeCutoff'
      => \Civi\Osdi\ActionNetwork\RemoteSystem::formatDateTime(strtotime($remotePerson->modifiedDate->get()) - 1),
      'ntfActionNetwork.syncJobEndTime' => NULL,
    ]);

    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person\N2F(self::$system);
    $syncer->setMatcher(
      new \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail(
        $syncer, LocalPerson::class));
    $syncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\NineToFive2022June(self::$system));
    $syncer->batchSyncFromRemote();
    $syncedContactCount = \Civi\Api4\Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->execute()->count();

    self::assertEquals(0, $syncedContactCount);

    Civi::settings()->set('ntfActionNetwork.syncJobProcessId', 9999999999999);
    sleep(1);
    $syncer->batchSyncFromRemote();
    $syncedContactCount = \Civi\Api4\Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->execute()->count();

    self::assertEquals(1, $syncedContactCount);
  }

  public function testBatchSyncFromAN() {
    $localPeople = $this->setUpBatchSyncFromAN();

    $syncStartTime = time();

    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person\N2F(self::$system);
    $syncer->setMatcher(
      new \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail(
        $syncer, LocalPerson::class));
    $syncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\NineToFive2022June(self::$system));
    $syncer->batchSyncFromRemote();

    $this->assertBatchSyncFromAN($localPeople, $syncStartTime);
  }

  public function testBatchSyncFromCivi() {
    [$remotePeople, $maxRemoteModTimeBeforeSync] = $this->setUpBatchSyncFromCivi();

    $syncStartTime = time();

    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person\N2F(self::$system);
    $syncer->setMatcher(
      new \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail(
        $syncer, LocalPerson::class));
    $syncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\NineToFive2022June(self::$system));
    $syncer->batchSyncFromLocal();

    $this->assertBatchSyncFromCivi($remotePeople, $syncStartTime, $maxRemoteModTimeBeforeSync);
  }

  public function testBatchSyncViaApiCall() {
    $localPeople = $this->setUpBatchSyncFromAN();
    [$remotePeople, $maxRemoteModTimeBeforeSync] = $this->setUpBatchSyncFromCivi();

    $syncStartTime = time();

    sleep(1);
    $result = civicrm_api3('Contact', 'actionnetworkbatchsync');
    sleep(1);

    self::assertEquals(0, $result['is_error']);
    $this->assertBatchSyncFromAN($localPeople, $syncStartTime);
    $this->assertBatchSyncFromCivi($remotePeople, $syncStartTime, $maxRemoteModTimeBeforeSync);
  }

  private function setUpBatchSyncFromAN(): array {
    Civi::settings()->add([
      'ntfActionNetwork.syncJobProcessId' => 99999999999999,
      'ntfActionNetwork.syncJobActNetModTimeCutoff' => "2000-01-01T00:00:00Z",
      'ntfActionNetwork.syncJobStartTime' => strtotime("2000-11-11 00:00:00"),
      'ntfActionNetwork.syncJobEndTime' => strtotime("2000-11-11 00:00:11"),
    ]);

    $testTime = time();

    for ($i = 1; $i < 5; $i++) {
      $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
      $remotePerson->emailAddress->set($email = "syncJobFromANTest$i@null.org");
      $remotePerson->givenName->set('Sync Job Test');
      $remotePerson->familyName->set($lastName = "$i $testTime");
      $remotePeople[$i] = $remotePerson->save();
      $modTime = strtotime($remotePerson->modifiedDate->get());

      $localPerson = new LocalPerson();
      $localPerson->emailEmail->set($email);
      $localPerson->lastName->set($lastName);
      $localPeople[$i] = $localPerson->save();

      $syncState = new \Civi\Osdi\PersonSyncState();
      $syncState->setSyncOrigin(\Civi\Osdi\PersonSyncState::ORIGIN_REMOTE);
      $syncState->setRemotePersonId($remotePerson->getId());
      $syncState->setContactId($localPerson->getId());
      $syncState->setRemotePreSyncModifiedTime($modTime - 10);
      $syncState->setRemotePostSyncModifiedTime($modTime);
      $syncState->setLocalPreSyncModifiedTime($modTime - 10);
      $syncState->setLocalPostSyncModifiedTime($modTime);
      $syncState->save();

      usleep(400000);
    }

    usleep(700000);
    $remotePeople[3]->languageSpoken->set('es');
    $remotePeople[3]->save();

    return $localPeople;
  }

  private function assertBatchSyncFromAN(array $localPeople, int $syncStartTime): void {
    foreach ($localPeople as $i => $localPerson) {
      /** @var \Civi\Osdi\LocalObject\Person\N2F $localPerson */
      $localPerson->load();
      if ($i === 3) {
        self::assertEquals('Sync Job Test', $localPerson->firstName->get());
        self::assertGreaterThanOrEqual($syncStartTime, strtotime($localPerson->modifiedDate->get()));
        self::assertLessThan($syncStartTime + 60, strtotime($localPerson->modifiedDate->get()));
      }
      else {
        self::assertEmpty($localPerson->firstName->get());
        self::assertLessThan($syncStartTime, strtotime($localPerson->modifiedDate->get()));
      }
    }
  }

  private function setUpBatchSyncFromCivi(): array {
    Civi::settings()->add([
      'ntfActionNetwork.syncJobProcessId' => 99999999999999,
      'ntfActionNetwork.syncJobActNetModTimeCutoff' => "2000-01-01T00:00:00Z",
      'ntfActionNetwork.syncJobStartTime' => strtotime("2000-11-11 00:00:00"),
      'ntfActionNetwork.syncJobEndTime' => strtotime("2000-11-11 00:00:11"),
    ]);

    $testTime = time();

    for ($i = 1; $i < 5; $i++) {
      $localPerson = new \Civi\Osdi\LocalObject\Person\N2F();
      $localPerson->emailEmail->set($email = "syncJobFromCiviTest$i@null.org");
      $localPerson->firstName->set('Sync Job Test');
      $localPerson->lastName->set($lastName = "$i $testTime");
      $localPeople[$i] = $localPerson->save();
      $modTime = strtotime($localPerson->modifiedDate->get());

      $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
      $remotePerson->emailAddress->set($email);
      $remotePerson->givenName->set('test (not yet synced)');
      $remotePerson->familyName->set($lastName);
      $remotePeople[$i] = $remotePerson->save();

      $syncState = new \Civi\Osdi\PersonSyncState();
      $syncState->setSyncOrigin(\Civi\Osdi\PersonSyncState::ORIGIN_REMOTE);
      $syncState->setRemotePersonId($remotePerson->getId());
      $syncState->setContactId($localPerson->getId());
      $syncState->setLocalPreSyncModifiedTime($modTime - 10);
      $syncState->setLocalPostSyncModifiedTime($modTime);
      $syncState->setRemotePreSyncModifiedTime($modTime - 10);
      $syncState->setRemotePostSyncModifiedTime($modTime);
      $syncState->save();

      usleep(400000);
    }

    usleep(700000);
    $localPeople[3]->individualLanguagesSpoken->set(['es']);
    $localPeople[3]->save();

    return array($remotePeople, strtotime($remotePeople[4]->modifiedDate->get()));
  }

  private function assertBatchSyncFromCivi($remotePeople, int $syncStartTime, $maxRemoteModTimeBeforeSync): void {
    foreach ($remotePeople as $i => $remotePerson) {
      /** @var \Civi\Osdi\ActionNetwork\Object\Person $remotePerson */
      $remotePerson->load();
      if ($i == 3) {
        self::assertEquals('Sync Job Test', $remotePerson->givenName->get());
        self::assertGreaterThanOrEqual($syncStartTime, strtotime($remotePerson->modifiedDate->get()));
        self::assertLessThan($syncStartTime + 60, strtotime($remotePerson->modifiedDate->get()));
      }
      else {
        self::assertEquals('test (not yet synced)', $remotePerson->givenName->get());
        self::assertLessThanOrEqual($maxRemoteModTimeBeforeSync, strtotime($remotePerson->modifiedDate->get()));
      }
    }
  }

}
