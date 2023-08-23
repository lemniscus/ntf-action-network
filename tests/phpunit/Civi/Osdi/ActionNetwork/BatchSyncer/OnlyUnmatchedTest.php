<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi;
use OsdiClient\ActionNetwork\PersonMatchingFixture as PersonMatchFixture;
use OsdiClient\ActionNetwork\TestUtils;

/**
 * @group headless
 *
 * osdi-client classes should be autoloaded --
 * see code in <extension-root>/tests/phpunit/bootstrap.php
 */
class OnlyUnmatchedTest extends \PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('osdi-client')
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass(): void {
    static::$remoteSystem = TestUtils::createRemoteSystem();
    $container = Civi\OsdiClient::containerWithDefaultSyncProfile(TRUE);
    $container->register('BatchSyncer', 'Person',
      PersonAllTimeUnmatched::class);
    $container->register('LocalObject', 'Person',
      Civi\Osdi\LocalObject\PersonN2F::class);
    $container->register('Mapper', 'Person',
      Civi\Osdi\ActionNetwork\Mapper\PersonN2F2022June::class);
    $container->register('SingleSyncer', 'Person',
      Civi\Osdi\ActionNetwork\SingleSyncer\PersonUnmatchedOnly::class);

    PersonMatchFixture::$personClass = Civi\Osdi\ActionNetwork\Object\Person::class;
    PersonMatchFixture::$remoteSystem = self::$remoteSystem;
  }

  public function testOnlyUnmatchedPeopleAreSynced() {
    $container = Civi\OsdiClient::container();
    
    [$matchedRemotePerson, $idOfMatchingContact] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(1);
    /** @var \Civi\Osdi\LocalObject\PersonBasic $matchedLocalPerson */
    $matchedLocalPerson = $container
      ->make('LocalObject', 'Person', $idOfMatchingContact);

    /** @var \Civi\Osdi\LocalObject\PersonBasic $syncedLocalPerson */
    $syncedLocalPerson = PersonMatchFixture::makeUnsavedLocalPersonWithFirstLastEmail(2);
    $basicSingleSyncer = new Civi\Osdi\ActionNetwork\SingleSyncer\PersonBasic();
    $basicSingleSyncer->matchAndSyncIfEligible($syncedLocalPerson->save());
    $syncState = Civi\Osdi\PersonSyncState::getForLocalPerson(
      $syncedLocalPerson, $container->getSyncProfileId());
    $localPostSyncModifiedTime = $syncState->getLocalPostSyncModifiedTime();
    self::assertNotEmpty($localPostSyncModifiedTime);

    // We want 3 local contacts, all with unsynced changes, 2 with remote
    // matches, 1 unmatched.
    $unmatchedLocalPerson = PersonMatchFixture::saveNewUniqueLocalPerson(__FUNCTION__);

    $matchedLocalPerson->addressCity->set('Whoville');
    $matchedLocalPerson->save();

    $syncedLocalPerson->addressCity->set('Hogwarts');
    // The already-synced contact will have a mod time after its sync time
    time_sleep_until(max(microtime(true) + .1,
      (float) strtotime($localPostSyncModifiedTime) + 1));
    $syncedLocalPerson->save();

    /** @var \Civi\Osdi\ActionNetwork\BatchSyncer\PersonAllTimeUnmatched $batchSyncer */
    $batchSyncer = $container->getSingle('BatchSyncer', 'Person');
    $stats = $batchSyncer->batchSyncFromLocal();

    self::assertEquals('total: 2; success: 1; error: 0; skipped: 0; did not qualify: 1', $stats);
  }


}
