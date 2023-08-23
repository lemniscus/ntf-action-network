<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi;
use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use OsdiClient\ActionNetwork\PersonMatchingFixture as PersonMatchFixture;

/**
 * @group headless
 *
 * osdi-client classes should be autoloaded --
 * see code in <extension-root>/tests/phpunit/bootstrap.php
 */
class TaggingN2FTest extends \PHPUnit\Framework\TestCase implements
  \Civi\Test\HeadlessInterface,
  \Civi\Test\TransactionalInterface {

  private static Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  private static TaggingN2F $syncer;

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
        'SingleSyncer' => [
          'Person' => Civi\Osdi\ActionNetwork\SingleSyncer\PersonN2F::class,
          'Tagging' => \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingN2F::class,
        ],
      ])
      ->execute()->single();

    $container = Civi\OsdiClient::containerWithDefaultSyncProfile(TRUE);

    self::$remoteSystem = $container
      ->getSingle('RemoteSystem', 'ActionNetwork');

    self::$syncer = $container->getSingle('SingleSyncer', 'Tagging', self::$remoteSystem);
    self::assertEquals(TaggingN2F::class, get_class(self::$syncer));
    self::$syncer->setSyncProfile($syncProfile);

    PersonMatchFixture::$personClass = ANPerson::class;
    PersonMatchFixture::$remoteSystem = self::$remoteSystem;
  }

  public function testOnlyCertainTagsCanBeSynced() {
    $syncer = self::$syncer;

    $badRemoteTag = new Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $badRemoteTag->name->set('Bad Tag');
    $badRemoteTag->save();

    $goodRemoteTag = new Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $goodRemoteTag->name->set('Campaign: Test');
    $goodRemoteTag->save();

    foreach ([$badRemoteTag, $goodRemoteTag] as $remoteTag) {
      $localTag = new Civi\Osdi\LocalObject\TagBasic();
      $localTag->name->set($remoteTag->name->get());
      $localTag->save();
    }

    [$remotePerson, $cid] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName();

    $badTagging = new Civi\Osdi\ActionNetwork\Object\Tagging(self::$remoteSystem);
    $badTagging->setPerson($remotePerson);
    $badTagging->setTag($badRemoteTag);
    $badTagging->save();

    $badPair = $syncer->toLocalRemotePair(NULL, $badTagging);
    $badPair->setOrigin($badPair::ORIGIN_REMOTE);
    $badMapAndWriteResult = $syncer->oneWayMapAndWrite($badPair);

    self::assertNotEmpty($badTagging->getId());
    self::assertTrue($badMapAndWriteResult->isError());

    $goodTagging = new Civi\Osdi\ActionNetwork\Object\Tagging(self::$remoteSystem);
    $goodTagging->setPerson($remotePerson);
    $goodTagging->setTag($goodRemoteTag);
    $goodTagging->save();

    $goodPair = $syncer->toLocalRemotePair(NULL, $goodTagging);
    $goodPair->setOrigin($goodPair::ORIGIN_REMOTE);
    $goodMapAndWriteResult = $syncer->oneWayMapAndWrite($goodPair);

    self::assertFalse($goodMapAndWriteResult->isError());

    $entityTags = Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_id', '=', $cid)
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addSelect('tag_id.name')
      ->execute();

    self::assertCount(1, $entityTags);
    self::assertEquals($goodRemoteTag->name->get(), $entityTags->single()['tag_id.name']);
  }

}
