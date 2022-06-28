<?php

namespace Civi\Osdi\ActionNetwork\Syncer\Person;

use Civi\Api4\OsdiPersonSyncState;
use Civi\Core\Lock\NullLock;
use Civi\Osdi\ActionNetwork\Logger;
use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\LocalObject\LocalObjectInterface;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\MatchResult;
use Civi\Osdi\PersonSyncState;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\PersonSyncerInterface;
use Civi\Osdi\SyncResult;

class N2F implements PersonSyncerInterface {

  const inputTypeLocal = 'Local';
  const inputTypeRemote = 'Remote';

  private RemoteSystemInterface $remoteSystem;
  private ?MapperInterface $mapper = NULL;
  private ?MatcherInterface $matcher = NULL;

  private ?int $syncProfileId = NULL;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    return $this->remoteSystem;
  }

  public function getMapper(): MapperInterface {
    return $this->mapper;
  }

  public function setMapper(MapperInterface $mapper): void {
    $this->mapper = $mapper;
  }

  public function getMatcher(): MatcherInterface {
    return $this->matcher;
  }

  public function setMatcher(MatcherInterface $matcher): void {
    $this->matcher = $matcher;
  }

  public function batchSyncFromRemote(): ?int {
    Logger::logDebug('Batch AN->Civi sync requested');
    $lastJobPid = \Civi::settings()->get('ntfActionNetwork.syncJobProcessId');
    if ($lastJobPid && posix_getsid($lastJobPid) !== FALSE) {
      Logger::logDebug("Sync process ID $lastJobPid is still running; quitting new process");
      return NULL;
    }
    Logger::logDebug('Batch AN->Civi sync process ID is ' . getmypid());

    $lastJobEndTime = \Civi::settings()->get('ntfActionNetwork.syncJobEndTime');
    if (empty($lastJobEndTime)) {
      $cutoff = \Civi::settings()->get('ntfActionNetwork.syncJobActNetModTimeCutoff');
    }

    if (empty($cutoff)) {
      $cutoffUnixTime = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
        ->addSelect('MAX(remote_pre_sync_modified_time) AS maximum')
        ->addWhere('sync_origin', '=', PersonSyncState::ORIGIN_REMOTE)
        ->execute()->single()['maximum'] ?? time() - 60;
      $cutoffUnixTime--;
      $cutoff = RemoteSystem::formatDateTime($cutoffUnixTime);
    }

    Logger::logDebug("Horizon for AN->Civi sync set to $cutoff");

    \Civi::settings()->add([
      'ntfActionNetwork.syncJobProcessId' => getmypid(),
      'ntfActionNetwork.syncJobStartTime' => time(),
      'ntfActionNetwork.syncJobEndTime' => NULL,
      'ntfActionNetwork.syncJobActNetModTimeCutoff' => $cutoff,
    ]);

    $searchResults = $this->getRemoteSystem()->find('osdi:people', [
      [
        'modified_date',
        'gt',
        $cutoff,
      ],
    ]);

    foreach ($searchResults as $remotePerson) {
      Logger::logDebug('Considering AN id ' . $remotePerson->getId() .
        ', mod ' . $remotePerson->modifiedDate->get() .
        ', ' . $remotePerson->emailAddress->get());
      $syncResult = $this->syncFromRemoteIfNeeded($remotePerson);
      Logger::logDebug('Result for  AN id ' . $remotePerson->getId() .
      ': ' . $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage());
    }

    Logger::logDebug('Finished batch AN->Civi sync; count: ' . $searchResults->rawCurrentCount());

    \Civi::settings()->add([
      'ntfActionNetwork.syncJobProcessId' => NULL,
      'ntfActionNetwork.syncJobEndTime' => time(),
    ]);

    return $searchResults->rawCurrentCount();
  }

  public function batchSyncFromLocal(): ?int {
    Logger::logDebug('Batch Civi->AN sync requested');
    $lastJobPid = \Civi::settings()->get('ntfActionNetwork.syncJobProcessId');
    if ($lastJobPid && posix_getsid($lastJobPid) !== FALSE) {
      Logger::logDebug("Sync process ID $lastJobPid is still running; quitting new process");
      return NULL;
    }

    $cutoff = \Civi::settings()->get('ntfActionNetwork.syncJobCiviModTimeCutoff');

    if (empty($cutoff)) {
      $cutoffUnixTime = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
          ->addSelect('MAX(local_pre_sync_modified_time) AS maximum')
          ->addWhere('sync_origin', '=', PersonSyncState::ORIGIN_LOCAL)
          ->execute()->single()['maximum'] ?? time() - 60;
      $cutoff = date('Y-m-d H:i:s', $cutoffUnixTime);
    }

    Logger::logDebug("Horizon for Civi->AN sync set to $cutoff");

    \Civi::settings()->add([
      'ntfActionNetwork.syncJobProcessId' => getmypid(),
      'ntfActionNetwork.syncJobStartTime' => time(),
      'ntfActionNetwork.syncJobEndTime' => NULL,
    ]);

    $civiEmails = \Civi\Api4\Email::get(FALSE)
      ->addSelect(
        'contact_id',
        //'COUNT(DISTINCT contact_id) AS count_contact_id',
        'contact.modified_date',
        'sync_state.local_pre_sync_modified_time',
        'sync_state.local_post_sync_modified_time')
      ->addJoin('Contact AS contact', 'INNER')
      ->addJoin(
        'OsdiPersonSyncState AS sync_state',
        'LEFT',
        ['contact_id', '=', 'sync_state.contact_id'])
      ->addGroupBy('email')
      ->addOrderBy('contact.modified_date')
      ->addWhere('contact.modified_date', '>=', $cutoff)
      ->addWhere('is_primary', '=', TRUE)
      ->addWhere('contact.is_deleted', '=', FALSE)
      ->addWhere('contact.is_opt_out', '=', FALSE)
      ->addWhere('contact.contact_type', '=', 'Individual')
      ->execute();

    Logger::logDebug('Civi->AN sync: ' . $civiEmails->count() . ' to consider');

    foreach ($civiEmails as $i => $emailRecord) {
      if (strtotime($emailRecord['contact.modified_date']) ===
        $emailRecord['sync_state.local_post_sync_modified_time']
      ) {
        $upToDate[] = $emailRecord['contact_id'];
        continue;
      }

      if ($upToDate ?? FALSE) {
        Logger::logDebug('Civi Ids already up to date: ' . implode(', ', $upToDate));
        $upToDate = [];
      }

      $localPerson = (new LocalPerson($emailRecord['contact_id']))->loadOnce();

      Logger::logDebug('Considering Civi id ' . $localPerson->getId() .
        ', mod ' . $localPerson->modifiedDate->get() .
        ', ' . $localPerson->emailEmail->get());

      $doNotEmail = $localPerson->doNotEmail->get();
      $emailOnHold = $localPerson->emailOnHold->get();
      $emailIsDummy = 'noemail@' === substr($localPerson->emailEmail->get(), 0, 8);
      $doNotSms = $localPerson->doNotSms->get();

      if ($doNotEmail || $emailIsDummy || $emailOnHold) {
        if ($doNotSms || empty($localPerson->smsPhonePhone->get())) {
          Logger::logDebug('Skipping Civi id ' . $localPerson->getId() .
            ' because they cannot be emailed or texted');
          continue;
        }
      }

      //if ($emailRecord['count_contact_id'] > 1) {
      //  $outRow[$columnOffsets['match status']] = 'match error';
      //  $outRow[$columnOffsets['message']] = 'email is not unique in Civi';
      //  $this->out->insertOne($outRow);
      //  continue;
      //}

      $syncResult = $this->syncFromLocalIfNeeded($localPerson);
      Logger::logDebug('Result for  Civi id ' . $localPerson->getId() .
        ': ' . $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage());
    }

    if ($upToDate ?? FALSE) {
      Logger::logDebug('Civi Ids already up to date: ' . implode(', ', $upToDate));
      $upToDate = [];
    }

    $count = ($i ?? -1) + 1;
    Logger::logDebug('Finished batch Civi->AN sync; count: ' . $count);

    \Civi::settings()->add([
      'ntfActionNetwork.syncJobProcessId' => NULL,
      'ntfActionNetwork.syncJobEndTime' => time(),
      'ntfActionNetwork.syncJobCiviModTimeCutoff' =>
        $emailRecord['sync_state.local_pre_sync_modified_time'] ?? $cutoff,
    ]);

    return $count;
  }

  public function syncFromRemoteIfNeeded(RemotePerson $remotePerson): SyncResult {
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
        $pair->getMatchResult()
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

    if (empty($postSyncModTime = $syncState->getRemotePostSyncModifiedTime())) {
      return $this->oneWaySyncRemoteObject($remotePerson, $pair->getLocalObject(), $syncState);
    }

    if ($postSyncModTime < $this->modTimeAsUnixTimestamp($remotePerson)) {
      return $this->oneWaySyncRemoteObject($remotePerson, $pair->getLocalObject(), $syncState);
    }

    return new SyncResult(
      NULL,
      $remotePerson,
      SyncResult::NO_SYNC_NEEDED,
      'Sync is already up to date',
      $syncState
    );
  }

  public function syncFromLocalIfNeeded(LocalPerson $localPerson): SyncResult {
    $pair = $this->getOrCreateLocalRemotePairFromLocal($localPerson);
    if ('created matching object' == $pair->getMessage()) {
      return $pair->getSyncResult();
    }

    if (empty($syncState = $pair->getPersonSyncState())) {
      $syncState = PersonSyncState::getForLocalPerson($localPerson, $this->syncProfileId);
    }

    if (empty($postSyncModTime = $syncState->getLocalPostSyncModifiedTime())) {
      return $this->oneWaySyncLocalObject($localPerson, $pair->getRemoteObject(), $syncState);
    }

    if ($postSyncModTime < $this->modTimeAsUnixTimestamp($localPerson)) {
      return $this->oneWaySyncLocalObject($localPerson, $pair->getRemoteObject(), $syncState);
    }

    return new SyncResult(
      $localPerson,
      NULL,
      SyncResult::NO_SYNC_NEEDED,
      'Sync is already up to date',
      $syncState
    );
  }

  public function getOrCreateLocalRemotePairFromRemote(RemotePerson $remotePerson): LocalRemotePair {
    $syncState = PersonSyncState::getForRemotePerson($remotePerson, $this->syncProfileId);
    if ($syncState->getId()) {
      if ($pair = $this->getLocalRemotePairFromSyncState($syncState, NULL, NULL)) {
        return $pair;
      }
    }

    $matchResult = $this->getMatcher()->tryToFindMatchForRemotePerson($remotePerson);

    if ($matchResult->isError()) {
      return new LocalRemotePair(
        NULL,
        NULL,
        TRUE,
        'error finding match',
        NULL,
        $matchResult);
    }

    elseif (MatchResult::NO_MATCH == $matchResult->getStatus()) {
      $syncResult = $this->oneWaySyncRemoteObject($remotePerson, NULL, $syncState);
      return $this->createLocalRemotePairFromSyncResult($syncResult);
    }

    else {
      return $this->saveNewMatchAndCreateLocalRemotePair($matchResult);
    }
  }

  public function getOrCreateLocalRemotePairFromLocal(LocalPerson $localPerson): LocalRemotePair {
    $syncState = PersonSyncState::getForLocalPerson($localPerson, $this->syncProfileId);
    if ($syncState->getId()) {
      if ($pair = $this->getLocalRemotePairFromSyncState($syncState, $localPerson)) {
        return $pair;
      }
    }

    $matchResult = $this->getMatcher()->tryToFindMatchForLocalContact($localPerson);

    if ($matchResult->isError()) {
      return new LocalRemotePair(
        NULL,
        NULL,
        TRUE,
        'error finding match',
        NULL,
        $matchResult);
    }

    elseif (MatchResult::NO_MATCH == $matchResult->getStatus()) {
      $syncResult = $this->oneWaySyncLocalObject($localPerson, NULL, $syncState);
      return $this->createLocalRemotePairFromSyncResult($syncResult);
    }

    else {
      return $this->saveNewMatchAndCreateLocalRemotePair($matchResult);
    }
  }

  private function getLocalRemotePairFromSyncState(
    PersonSyncState $syncState,
    LocalPerson $localPerson = NULL,
    RemotePerson $remotePerson = NULL
  ): ?LocalRemotePair {
    if (empty($syncState->getContactId()) || empty($syncState->getRemotePersonId())) {
      return NULL;
    }
    try {
      $localObject = $localPerson ??
        (new LocalPerson($syncState->getContactId()))->load();
      $remoteObject = $remotePerson ??
        RemotePerson::loadFromId($syncState->getRemotePersonId(), $this->remoteSystem);
    }
    catch (InvalidArgumentException | EmptyResultException $e) {
      $syncState->delete();
    }

    if (isset($localObject)) {
      return new LocalRemotePair(
        $localObject,
        $remoteObject,
        FALSE,
        'fetched saved match',
        $syncState
      );
    }

    return NULL;
  }

  private function saveNewMatchAndCreateLocalRemotePair(MatchResult $matchResult): LocalRemotePair {
    if (MatchResult::ORIGIN_LOCAL === $matchResult->getOrigin()) {
      $localObject = $matchResult->getOriginObject();
      $remoteObject = $matchResult->getMatch();
    }
    else {
      $localObject = $matchResult->getMatch();
      $remoteObject = $matchResult->getOriginObject();
    }

    $syncState = new PersonSyncState();
    $syncState->setContactId($localObject->loadOnce()->getId());
    $syncState->setRemotePersonId($remoteObject->getId());
    $syncState->setSyncProfileId($this->syncProfileId);

    return new LocalRemotePair(
      $localObject,
      $remoteObject,
      FALSE,
      'found new match with existing object',
      $syncState,
      $matchResult);
  }

  private function oneWaySyncLocalObject(
    LocalPerson $localPerson,
    RemotePerson $remotePerson = NULL,
    PersonSyncState $syncState = NULL
  ): SyncResult {

    $syncState = $syncState ?? new PersonSyncState();

    if (empty($remotePerson)) {
      $remotePerson = new RemotePerson($this->getRemoteSystem());
      if ($remotePersonId = $syncState->getRemotePersonId()) {
        $remotePerson->setId($remotePersonId);
        $remotePerson->loadOnce();
      }
    }
    else {
      $remotePerson->loadOnce();
    }

    $localPerson->loadOnce();

    $localModifiedTime = $this->modTimeAsUnixTimestamp($localPerson);
    $remotePreSyncModifiedTime = $this->modTimeAsUnixTimestamp($remotePerson);

    $remotePerson = $this->getMapper()->mapLocalToRemote(
      $localPerson, $remotePerson);

    if ($remotePerson->isAltered()) {
      $saveResult = $this->getRemoteSystem()->trySave($remotePerson);
      /** @var \Civi\Osdi\ActionNetwork\Object\Person $remotePerson */
      $remotePerson = $saveResult->getReturnedObject();
      $statusMessage = empty($remotePreSyncModifiedTime)
        ? 'created new AN person'
        : 'altered existing AN person';
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
      $context = ['contact id' => $localPerson->getId()];
      if ($exception = $saveResult->getContext()['exception'] ?? NULL) {
        $context['exception'] = $exception;
      }
      else {
        $context['context'] = $context;
      }
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
      $saveResult->isError() ? $saveResult->getContext() : NULL
    );
  }

  private function oneWaySyncRemoteObject
  (
    RemotePerson $remotePerson,
    LocalObjectInterface $localPerson = NULL,
    PersonSyncState $syncState = NULL
  ): SyncResult {

    $syncState = $syncState ?? new PersonSyncState();

    if (empty($localPerson)) {
      $localPerson = new LocalPerson();
      if ($contactId = $syncState->getContactId()) {
        $localPerson->setId($contactId);
      }
    }

    $remoteModifiedTime = $this->modTimeAsUnixTimestamp($remotePerson);
    $localPreSyncModifiedTime = $this->modTimeAsUnixTimestamp($localPerson);

    /** @var \Civi\Osdi\LocalObject\Person\N2F $localPerson */
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

  private function createLocalRemotePairFromSyncResult(SyncResult $syncResult): LocalRemotePair {
    $isError = $syncResult->isError();
    $pair = new LocalRemotePair(
      $syncResult->getLocalObject(),
      $syncResult->getRemoteObject(),
      $isError,
      $isError ? 'error creating matching object' : 'created matching object',
      $syncResult->getState(),
      NULL,
      $syncResult);
    return $pair;
  }

  /**
   * @param \Civi\Osdi\ActionNetwork\Object\Person|\Civi\Osdi\LocalObject\Person\N2F $person
   */
  private function modTimeAsUnixTimestamp($person): ?int {
    if ($m = $person->modifiedDate->get()) {
      return strtotime($m);
    }
    return NULL;
  }

}
