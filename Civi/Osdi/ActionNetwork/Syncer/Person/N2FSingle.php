<?php

namespace Civi\Osdi\ActionNetwork\Syncer\Person;

use Civi\Osdi\ActionNetwork\Logger;
use Civi\Osdi\LocalObject\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\MatchResult;
use Civi\Osdi\PersonSingleSyncerInterface;
use Civi\Osdi\PersonSyncState;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\SyncResult;
use Civi\Osdi\Util;

class N2FSingle implements PersonSingleSyncerInterface {

  use LocalRemotePairTrait;

  private RemoteSystemInterface $remoteSystem;

  private ?MapperInterface $mapper = NULL;

  private ?int $syncProfileId = NULL;

  private ?MatcherInterface $matcher = NULL;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function oneWaySyncRemoteObject(LocalRemotePair $localRemotePair): SyncResult {
    $localPersonClass = $this->getLocalPersonClass();

    $remotePerson = $this->typeCheckRemotePerson(
      $localRemotePair->getRemoteObject());

    $syncState = $localRemotePair->getPersonSyncState() ?? new PersonSyncState();

    if (empty($localPerson = $localRemotePair->getLocalObject())) {
      $localPerson = new $localPersonClass();
      if ($contactId = $syncState->getContactId()) {
        $localPerson->setId($contactId);
        $localPerson->loadOnce();
      }
    }
    else {
      $localPerson->loadOnce();
    }
    $localPerson = $this->typeCheckLocalPerson($localPerson);

    $remoteModifiedTime = $this->modTimeAsUnixTimestamp($remotePerson);
    $localPreSyncModifiedTime = $this->modTimeAsUnixTimestamp($localPerson);

    $localPerson = $this->getMapper()->mapRemoteToLocal(
      $remotePerson, $localPerson);

    try {
      if ($localPerson->isAltered()) {
        $localPerson->save();
        $statusMessage = empty($localPreSyncModifiedTime)
          ? 'created new Civi contact'
          : 'altered existing Civi contact';
        $localPostSyncModifiedTime = $this->modTimeAsUnixTimestamp($localPerson);
      }
      else {
        $statusMessage = 'no changes made to Civi contact';
        $localPostSyncModifiedTime = $localPreSyncModifiedTime;
      }
      $statusCode = SyncResult::SUCCESS;
    }
    catch (\API_Exception $exception) {
      $statusCode = SyncResult::ERROR;
      $statusMessage = 'exception when saving local contact';
      Logger::logError("OSDI Client sync error: $statusMessage", [
        'remote person id' => $remotePerson->getId(),
        'exception' => $exception,
      ]);
    }

    $syncState->setSyncProfileId($this->syncProfileId);
    $syncState->setSyncOrigin(PersonSyncState::ORIGIN_REMOTE);
    $syncState->setSyncTime(time());
    $syncState->setContactId($localPerson->getId());
    $syncState->setRemotePersonId($remotePerson->getId());
    $syncState->setRemotePreSyncModifiedTime($remoteModifiedTime);
    $syncState->setRemotePostSyncModifiedTime($remoteModifiedTime);
    $syncState->setLocalPreSyncModifiedTime($localPreSyncModifiedTime);
    $syncState->setLocalPostSyncModifiedTime($localPostSyncModifiedTime ?? NULL);
    $syncState->setSyncStatus($statusCode);
    $syncState->save();

    return new SyncResult(
      $localPerson,
      $remotePerson,
      $statusCode,
      $statusMessage,
      $syncState
    );
  }

  public function oneWaySyncLocalObject(LocalRemotePair $localRemotePair): SyncResult {
    $remotePersonClass = $this->getRemotePersonClass();

    $localPerson = $this->typeCheckLocalPerson(
      $localRemotePair->getLocalObject()->loadOnce());

    $syncState = $localRemotePair->getPersonSyncState() ?? new PersonSyncState();

    if (empty($remotePerson = $localRemotePair->getRemoteObject())) {
      $remotePerson = new $remotePersonClass($this->getRemoteSystem());
      if ($remotePersonId = $syncState->getRemotePersonId()) {
        $remotePerson->setId($remotePersonId);
        $remotePerson->loadOnce();
      }
    }
    else {
      $remotePerson->loadOnce();
    }
    $remotePerson = $this->typeCheckRemotePerson($remotePerson);

    $localModifiedTime = $this->modTimeAsUnixTimestamp($localPerson);
    $remotePreSyncModifiedTime = $this->modTimeAsUnixTimestamp($remotePerson);

    $remotePerson = $this->getMapper()->mapLocalToRemote(
      $localPerson, $remotePerson);

    if ($remotePerson->isAltered()) {
      $saveResult = $this->getRemoteSystem()->trySave($remotePerson);
      $remotePerson = $saveResult->getReturnedObject();
      if (empty($remotePreSyncModifiedTime)) {
        $statusMessage = 'created new AN person';
      }
      else {
        $statusMessage = 'altered existing AN person';
        $context = $saveResult->getContext();
      }
      $remotePostSyncModifiedTime = $this->modTimeAsUnixTimestamp($remotePerson);
    }
    else {
      $statusMessage = 'no changes made to Action Network person';
      $remotePostSyncModifiedTime = $remotePreSyncModifiedTime;
    }

    $statusCode = SyncResult::SUCCESS;

    if ($saveResult && $saveResult->isError()) {
      $statusCode = SyncResult::ERROR;
      $statusMessage = 'problem when saving Action Network person: '
        . $saveResult->getMessage();

      $context = $saveResult->getContext();
      $context = is_array($context) ? $context : [$context];
      $context['contact id'] = $localPerson->getId();
      Logger::logError("OSDI Client sync error: $statusMessage", $context);
    }

    $syncState->setSyncProfileId($this->syncProfileId);
    $syncState->setSyncOrigin(PersonSyncState::ORIGIN_LOCAL);
    $syncState->setSyncTime(time());
    $syncState->setContactId($localPerson->getId());
    $syncState->setRemotePersonId($remotePerson->getId());
    $syncState->setRemotePreSyncModifiedTime($remotePreSyncModifiedTime);
    $syncState->setRemotePostSyncModifiedTime($remotePostSyncModifiedTime);
    $syncState->setLocalPreSyncModifiedTime($localModifiedTime);
    $syncState->setLocalPostSyncModifiedTime($localModifiedTime);
    $syncState->setSyncStatus($statusCode);
    $syncState->save();

    return new SyncResult(
      $localPerson,
      $remotePerson,
      $statusCode,
      $statusMessage,
      $syncState,
      $context ?? NULL
    );
  }

  public function syncFromRemoteIfNeeded(RemoteObjectInterface $remotePerson): SyncResult {
    $pair = $this->getOrCreateLocalRemotePairFromRemote($remotePerson);
    if ('created matching object' === $pair->getMessage()) {
      return $pair->getSyncResult();
    }

    if ('error finding match' === $pair->getMessage()) {
      return new SyncResult(
        NULL,
        $remotePerson,
        SyncResult::ERROR,
        'Match error: ' . $pair->getMatchResult()->getMessage(),
        NULL,
        $pair->getMatchResult()->getContext()
      );
    }

    if ($pair->isError()) {
      return new SyncResult(
        NULL,
        $remotePerson,
        SyncResult::ERROR,
        'Error: ' . $pair->getMessage(),
        NULL,
        $pair
      );
    }

    if (empty($syncState = $pair->getPersonSyncState())) {
      $syncState = PersonSyncState::getForRemotePerson($remotePerson, $this->syncProfileId);
    }

    $noPreviousSync = empty($postSyncModTime = $syncState->getRemotePostSyncModifiedTime());
    $modifiedSinceLastSync = $postSyncModTime < $this->modTimeAsUnixTimestamp($remotePerson);
    if ($noPreviousSync || $modifiedSinceLastSync) {
      return $this->oneWaySyncRemoteObject($pair);
    }

    return new SyncResult(
      NULL,
      $remotePerson,
      SyncResult::NO_SYNC_NEEDED,
      'Sync is already up to date',
      $syncState
    );
  }

  public function syncFromLocalIfNeeded(LocalObjectInterface $localPerson): SyncResult {
    $localPersonClass = $this->getLocalPersonClass();
    $remotePersonClass = $this->getRemotePersonClass();

    \Civi\Osdi\Util::assertClass($localPerson, $localPersonClass);
    /** @var \Civi\Osdi\LocalObject\Person\N2F $localPerson */

    $pair = $this->getOrCreateLocalRemotePairFromLocal($localPerson);

    if ('person has no qualifying email or phone' == $pair->getMessage()) {
      return $pair->getSyncResult();
    }

    if ('created matching object' == $pair->getMessage()) {
      return $pair->getSyncResult();
    }

    if (empty($syncState = $pair->getPersonSyncState())) {
      $syncState = PersonSyncState::getForLocalPerson($localPerson, $this->syncProfileId);
    }
    $pair->setPersonSyncState($syncState);

    $noPreviousSync = empty($postSyncModTime = $syncState->getLocalPostSyncModifiedTime());
    $modifiedSinceLastSync = $postSyncModTime < $this->modTimeAsUnixTimestamp($localPerson->loadOnce());
    if ($noPreviousSync || $modifiedSinceLastSync) {
      return $this->oneWaySyncLocalObject($pair);
    }

    return new SyncResult(
      $localPerson,
      NULL,
      SyncResult::NO_SYNC_NEEDED,
      'Sync is already up to date',
      $syncState
    );
  }

  protected function getOrCreateLocalRemotePairFromLocal(LocalObjectInterface $localPerson): LocalRemotePair {
    $pair = (new LocalRemotePair())
      ->setLocalObject($localPerson)
      ->setLocalPersonClass($this->getLocalPersonClass())
      ->setRemotePersonClass($this->getRemotePersonClass());

    $syncState = PersonSyncState::getForLocalPerson($localPerson, $this->syncProfileId);
    if ($this->fillLocalRemotePairFromSyncState($pair, $syncState)) {
      return $pair;
    }

    $pair->setMatchResult($matchResult = $this->getMatcher()
      ->tryToFindMatchForLocalContact($localPerson));

    if ($matchResult->isError()) {
      return $pair->setIsError(TRUE)->setMessage('error finding match');
    }

    elseif (MatchResult::NO_MATCH == $matchResult->getStatus()) {
      if (!$this->newRemoteShouldBeCreatedForLocal($pair)) {
        return $pair;
      }
      $syncResult = $this->oneWaySyncLocalObject($pair);
      return $this->fillLocalRemotePairFromSyncResult($syncResult, $pair);
    }

    else {
      return $this->fillLocalRemotePairFromNewfoundMatch($matchResult, $pair);
    }
  }

  protected function getOrCreateLocalRemotePairFromRemote(RemoteObjectInterface $remotePerson): LocalRemotePair {
    $pair = (new LocalRemotePair())
      ->setRemoteObject($remotePerson)
      ->setLocalPersonClass($this->getLocalPersonClass())
      ->setRemotePersonClass($this->getRemotePersonClass());

    $syncState = PersonSyncState::getForRemotePerson($remotePerson, $this->syncProfileId);
    if ($this->fillLocalRemotePairFromSyncState($pair, $syncState)) {
      return $pair;
    }

    $pair->setMatchResult($matchResult = $this->getMatcher()
      ->tryToFindMatchForRemotePerson($remotePerson));

    if ($matchResult->isError()) {
      return $pair->setIsError(TRUE)->setMessage('error finding match');
    }

    elseif (MatchResult::NO_MATCH == $matchResult->getStatus()) {
      $syncResult = $this->oneWaySyncRemoteObject($pair);
      return $this->fillLocalRemotePairFromSyncResult($syncResult, $pair);
    }

    else {
      return $this->fillLocalRemotePairFromNewfoundMatch($matchResult, $pair);
    }
  }

  /**
   * @param \Civi\Osdi\RemoteObjectInterface|\Civi\Osdi\LocalObject\LocalObjectInterface $person
   */
  protected function modTimeAsUnixTimestamp($person): ?int {
    if ($m = $person->modifiedDate->get()) {
      return strtotime($m);
    }
    return NULL;
  }

  protected function newRemoteShouldBeCreatedForLocal(LocalRemotePair $pair): bool {
    $localPerson = $pair->getLocalObject();
    $localPerson->loadOnce();
    $doNotEmail = $localPerson->doNotEmail->get();
    $emailOnHold = $localPerson->emailOnHold->get();
    $emailIsDummy = 'noemail@' === substr($localPerson->emailEmail->get(), 0, 8);
    $doNotSms = $localPerson->doNotSms->get();

    if ($doNotEmail || $emailIsDummy || $emailOnHold) {
      if ($doNotSms || empty($localPerson->smsPhonePhone->get())) {
        return TRUE;
      }
    }

    return FALSE;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    return $this->remoteSystem;
  }

  public function setMapper(MapperInterface $mapper): void {
    $this->mapper = $mapper;
  }

  public function getMapper(): MapperInterface {
    return $this->mapper;
  }

  public function setMatcher(MatcherInterface $matcher): void {
    $this->matcher = $matcher;
  }

  public function getMatcher(): MatcherInterface {
    return $this->matcher;
  }

  protected function getRemotePersonClass() {
    return \Civi\Osdi\ActionNetwork\Object\Person::class;
  }

  protected function typeCheckRemotePerson(RemoteObjectInterface $object): \Civi\Osdi\ActionNetwork\Object\Person {
    Util::assertClass($object, \Civi\Osdi\ActionNetwork\Object\Person::class);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $object */
    return $object;
  }

  protected function getLocalPersonClass(): string {
    return \Civi\Osdi\LocalObject\Person\N2F::class;
  }

  private function typeCheckLocalPerson(LocalObjectInterface $object): \Civi\Osdi\LocalObject\Person\N2F {
    Util::assertClass($object, \Civi\Osdi\LocalObject\Person\N2F::class);
    /** @var \Civi\Osdi\LocalObject\Person\N2F $object */
    return $object;
  }

}
