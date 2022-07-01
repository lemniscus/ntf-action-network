<?php

namespace Civi\Osdi\ActionNetwork;

use Civi\Api4\Generic\Result as CiviApi4Result;
use Civi\Osdi\ActionNetwork\Mapper\Reconciliation2022May001;
use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use League\Csv\AbstractCsv;
use League\Csv\Reader;
use League\Csv\Writer;

class N2FReconciliationRunner {

  private string $inputFilePath;
  private string $outputFilePath;

  private RemoteSystem $system;

  private array $processedActNetIds = [];

  private array $processedCiviIds = [];

  private array $columnOffsets;

  private array $emptyOutRow;

  private AbstractCsv $out;

  private Reconciliation2022May001 $mapper;

  /**
   * @var false|resource
   */
  private $statusFile;

  private bool $isFinishedProcessingCSVInput = FALSE;

  private int $csvRowsPreviouslyProcessed = 0;

  private bool $dryRun;

  public function __construct($system = NULL) {
    if (is_null($system)) {
      $osdiClientExtDir = dirname(\CRM_Extension_System::singleton()
        ->getMapper()->keyToPath('osdi-client'));
      require_once "$osdiClientExtDir/tests/phpunit/CRM/OSDI/ActionNetwork/TestUtils.php";
      $system = \CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    }
    $this->system = $system;
    $this->mapper = new Reconciliation2022May001($this->system);
    $header = $this->getHeader();
    $this->columnOffsets = $columnOffsets = array_flip($header);
    $this->emptyOutRow = array_fill(0, count($header), NULL);
    $this->processedActNetIds = $this->processedCiviIds = [];
    $this->dryRun = FALSE;
  }

  public function setInput(string $path) {
    $this->inputFilePath = $path;
  }

  public function setOutput(string $path) {
    $this->outputFilePath = $path;
  }

  public function dryrun($reset = FALSE) {
    $this->dryRun = TRUE;
    $this->run($reset);
  }

  public function run($reset = FALSE) {
    fwrite(STDERR, date(DATE_ATOM));

    $this->setUpInputAndOutputFiles($reset);

    if ($reset || !$this->isFinishedProcessingCSVInput) {
      $this->processInputCsv();
    }

    $this->processUnmatchedCiviEmails();

    fclose($this->statusFile);
  }

  private function setUpInputAndOutputFiles(bool $reset): void {
    $statusFilePath = sys_get_temp_dir() . '/n2f_reconciliation_status';
    touch($statusFilePath);
    $this->statusFile = fopen($statusFilePath, 'r+');

    if ($reset) {
      $this->out = Writer::createFromPath($this->outputFilePath, 'w+');
      $this->out->insertOne($this->getHeader());
    }
    else {
      $this->out = Writer::createFromPath($this->outputFilePath, 'a+');

      $previouslyWritten = Reader::createFromPath($this->outputFilePath);
      $previouslyWritten->setHeaderOffset(0);

      foreach ($previouslyWritten as $i => $outRow) {
        if ($beforeANId = $outRow['Before AN id']) {
          $this->processedActNetIds[] = $beforeANId;
        }
        if ($afterCiviId = $outRow['After Civi id']) {
          $this->processedCiviIds[] = $afterCiviId;
        }
      }
    }

    $this->in = Reader::createFromPath($this->inputFilePath);
    $this->in->setHeaderOffset(0);
    if (!$reset) {
      while ($line = fgets($this->statusFile)) {
        if (!preg_match('/(\d+) of (\d+) CSV rows processed/', $line, $matches)) {
          continue;
        }
        $prevTotalCSVCount = $matches[2];
        if ((int) $prevTotalCSVCount !== $this->in->count()) {
          throw new \Exception("Can't resume: CSV input has different number of rows than before");
        }
        $this->csvRowsPreviouslyProcessed = $matches[1];
        $this->isFinishedProcessingCSVInput =
          $this->csvRowsPreviouslyProcessed === $prevTotalCSVCount;
      }
    }
  }

  private function processInputCsv() {
    $totalRows = $this->in->count();

    foreach ($this->in as $i => $actNetRecord) {
      if ($i <= $this->csvRowsPreviouslyProcessed) {
        continue;
      }

      if (($i % 100) === 0) {
        ftruncate($this->statusFile, 0);
        fwrite($this->statusFile, "$i of $totalRows CSV rows processed");
      }

      $outRow = $this->emptyOutRow;
      $columnOffsets = $this->columnOffsets;

      $remotePerson = $this->processANBefore($actNetRecord, $outRow);

      $email = $remotePerson->emailAddress->get();

      if (empty($email)) {
        $outRow[$columnOffsets['match status']] = 'no email';
        $this->out->insertOne($outRow);
        continue;
      }

      $matchingCiviEmails = $this->civiApi4EmailGet($email);

      if ($matchingCiviEmails->count() == 0) {
        $localPerson = $this->mapper->mapRemoteToLocal($remotePerson);
        $outRow[$columnOffsets['match status']] = 'create new Civi';
      }

      elseif ($matchingCiviEmails->single()['count_contact_id'] > 1) {
        $outRow[$columnOffsets['match status']] = 'match error';
        $outRow[$columnOffsets['message']] = 'email is not unique in Civi';
        $this->out->insertOne($outRow);
        continue;
      }

      elseif ($matchingCiviEmails->single()['count_contact_id'] == 1) {
        $civiContactId = $matchingCiviEmails->single()['contact_id'];
        $this->processedCiviIds[] = $civiContactId;
        $localPerson = new LocalPerson($civiContactId);
        $this->processCiviBefore($localPerson->loadOnce(), $outRow);

        $namesMatch =
          strtolower(trim($localPerson->firstName->get()))
              === strtolower(trim($remotePerson->givenName->get()))
          && strtolower(trim($localPerson->lastName->get()))
              === strtolower(trim($remotePerson->familyName->get()));

        if (!$namesMatch) {
          $outRow[$columnOffsets['match status']] = 'match error';
          $outRow[$columnOffsets['message']] = 'matched by unique email but not by name';
          $this->out->insertOne($outRow);
          continue;
        }

        $this->processMatch($localPerson, $remotePerson, $outRow);
      }

      $this->processANAfter($remotePerson, $outRow);
      $this->processCiviAfter($localPerson, $outRow);

      $this->saveRecordsAndWriteToCsv($remotePerson, $localPerson, $outRow);
    }

    rewind($this->statusFile);
    fwrite($this->statusFile, "$i CSV rows processed");
  }

  private function processUnmatchedCiviEmails(): void {
    rewind($this->statusFile);
    $csvInputFinalStatus = fgets($this->statusFile) . "\n";

    $civiEmails = $this->civiApi4EmailGet();
    $totalEmails = $civiEmails->count();

    foreach ($civiEmails as $i => $emailRecord) {
      if ($i % 100 === 0) {
        ftruncate($this->statusFile, 0);
        fwrite($this->statusFile, $csvInputFinalStatus . ($i + 1) .
          " of $totalEmails Civi records processed");
      }

      if (in_array($emailRecord['contact_id'], $this->processedCiviIds)) {
        continue;
      }

      $localPerson = (new LocalPerson($emailRecord['contact_id']))->loadOnce();

      if ($localPerson->isOptOut->get()) {
        continue;
      }

      $doNotEmail = $localPerson->doNotEmail->get();
      $emailOnHold = $localPerson->emailOnHold->get();
      $emailIsDummy = 'noemail@' === substr($localPerson->emailEmail->get(), 0, 8);
      $doNotSms = $localPerson->doNotSms->get();

      if ($doNotEmail || $emailIsDummy || $emailOnHold) {
        if ($doNotSms || empty($localPerson->smsPhonePhone->get())) {
          continue;
        }
      }

      $outRow = $this->emptyOutRow;
      $columnOffsets = $this->columnOffsets;

      $this->processCiviBefore($localPerson, $outRow);

      if ($emailRecord['count_contact_id'] > 1) {
        $outRow[$columnOffsets['match status']] = 'match error';
        $outRow[$columnOffsets['message']] = 'email is not unique in Civi';
        $this->out->insertOne($outRow);
        continue;
      }

      $outRow[$columnOffsets['match status']] = 'create new AN';
      $remotePerson = $this->mapper->mapLocalToRemote($localPerson);
      $this->processANAfter($remotePerson, $outRow);
      $this->processCiviAfter($localPerson, $outRow);
      $this->saveRecordsAndWriteToCsv($remotePerson, $localPerson, $outRow);
    }

    rewind($this->statusFile);
    fwrite($this->statusFile, $csvInputFinalStatus . ($i + 1) .
      " of $totalEmails Civi records processed");
  }

  private function processANBefore($actNetRecord, array &$outRow): RemotePerson {
    $remotePerson = new RemotePerson($this->system);

    foreach ($this->getRemotePersonFieldNames() as $fieldName) {
      if ('customFields' === $fieldName) {
        continue;
      }

      $value = $actNetRecord[$fieldName];
      $outRow[$this->columnOffsets["Before AN $fieldName"]] = $value;

      if ('id' === $fieldName) {
        $remotePerson->setId($value);
        $this->processedActNetIds[] = $value;
      }

      elseif (!empty($value)) {
        $flatFieldArray[$fieldName] = $value;
      }
    }

    return $remotePerson->loadFromArray($flatFieldArray);
  }

  private function processCiviBefore(LocalPerson $localPerson, array &$outRow) {
    foreach ($this->getLocalPersonfieldNames() as $fieldName) {
      $value = $localPerson->$fieldName->get();
      if ('individualLanguagesSpoken' === $fieldName) {
        $value = empty($value) ? NULL : json_encode($value);
      }
      $outRow[$this->columnOffsets["Before Civi $fieldName"]] = $value;
    }
  }

  private function processANAfter(RemotePerson $remotePerson, array &$outRow) {
    foreach ($this->getRemotePersonFieldNames() as $fieldName) {
      if ('id' === $fieldName) {
        $value = $remotePerson->getId();
      }
      else {
        $value = $remotePerson->$fieldName->get();
      }
      if ('customFields' === $fieldName) {
        $value = empty($value) ? NULL : json_encode($value);
      }
      $outRow[$this->columnOffsets["After AN $fieldName"]] = $value;
    }
  }

  private function processCiviAfter(LocalPerson $localPerson, array &$outRow) {
    foreach ($this->getLocalPersonFieldNames() as $fieldName) {
      $value = $localPerson->$fieldName->get();
      if ('individualLanguagesSpoken' === $fieldName) {
        $value = empty($value) ? NULL : json_encode($value);
      }
      $outRow[$this->columnOffsets["After Civi $fieldName"]] = $value;
    }
  }

  private function processMatch(LocalPerson $localPerson, RemotePerson $remotePerson, array &$outRow) {
    $columnOffsets = $this->columnOffsets;

    $outRow[$columnOffsets['match status']] = 'matched';

    $pair = $this->mapper->reconcile($localPerson, $remotePerson);

    if ($message = $pair->getMessage()) {
      $outRow[$columnOffsets['message']] = $message;
    }

    $outRow[$columnOffsets['update AN']] = $remotePerson->isAltered() ? 'yes' : 'no';
    $outRow[$columnOffsets['update Civi']] = $localPerson->isAltered() ? 'yes' : 'no';
  }

  private function getHeader(): array {
    $header = [
      'match status',
      'message',
      'update AN',
      'update Civi',
      'sync status',
    ];

    $beforeAfterFields = [
      'Civi id',
      'Civi modifiedDate',
      'Civi firstName',
      'Civi lastName',
      'Civi isOptOut',
      'Civi doNotEmail',
      'Civi doNotSms',
      'Civi individualLanguagesSpoken',
      'Civi emailId',
      'Civi emailEmail',
      'Civi nonSmsMobilePhoneId',
      'Civi nonSmsMobilePhoneIsPrimary',
      'Civi nonSmsMobilePhonePhone',
      'Civi nonSmsMobilePhonePhoneNumeric',
      'Civi smsPhoneId',
      'Civi smsPhoneIsPrimary',
      'Civi smsPhonePhone',
      'Civi smsPhonePhoneNumeric',
      'Civi addressId',
      'Civi addressStreetAddress',
      'Civi addressCity',
      'Civi addressStateProvinceId',
      'Civi addressStateProvinceIdAbbreviation',
      'Civi addressPostalCode',
      'Civi addressCountryId',
      'Civi addressCountryIdName',
      'AN id',
      'AN modifiedDate',
      'AN givenName',
      'AN familyName',
      'AN emailAddress',
      'AN emailStatus',
      'AN phoneNumber',
      'AN phoneStatus',
      'AN postalStreet',
      'AN postalLocality',
      'AN postalRegion',
      'AN postalCode',
      'AN postalCountry',
      'AN languageSpoken',
      'AN customFields',
    ];

    foreach ($beforeAfterFields as $f) {
      $header[] = "Before $f";
    }
    foreach ($beforeAfterFields as $f) {
      $header[] = "After $f";
    }
    return $header;
  }

  /**
   * @return string[]
   */
  private function getRemotePersonFieldNames(): array {
    return [
      'id',
      'modifiedDate',
      'givenName',
      'familyName',
      'emailAddress',
      'emailStatus',
      'phoneNumber',
      'phoneStatus',
      'postalStreet',
      'postalLocality',
      'postalRegion',
      'postalCode',
      'postalCountry',
      'languageSpoken',
      'customFields',
    ];
  }

  private function getLocalPersonFieldNames() {
    return [
      'id',
      'modifiedDate',
      'firstName',
      'lastName',
      'isOptOut',
      'doNotEmail',
      'doNotSms',
      'individualLanguagesSpoken',
      'emailId',
      'emailEmail',
      'nonSmsMobilePhoneId',
      'nonSmsMobilePhoneIsPrimary',
      'nonSmsMobilePhonePhone',
      'nonSmsMobilePhonePhoneNumeric',
      'smsPhoneId',
      'smsPhoneIsPrimary',
      'smsPhonePhone',
      'smsPhonePhoneNumeric',
      'addressId',
      'addressStreetAddress',
      'addressCity',
      'addressStateProvinceId',
      'addressStateProvinceIdAbbreviation',
      'addressPostalCode',
      'addressCountryId',
      'addressCountryIdName',
    ];
  }

  private function civiApi4EmailGet($email = NULL): CiviApi4Result {
    $emailGet = \Civi\Api4\Email::get(FALSE)
      ->addSelect('contact_id', 'COUNT(DISTINCT contact_id) AS count_contact_id')
      ->addJoin('Contact AS contact', 'INNER')
      ->addGroupBy('email')
      ->addWhere('is_primary', '=', TRUE)
      ->addWhere('contact.is_deleted', '=', FALSE)
      ->addWhere('contact.is_opt_out', '=', FALSE)
      ->addWhere('contact.contact_type', '=', 'Individual');

    if ($email) {
      $emailGet->addWhere('email', '=', $email);
    }

    return $emailGet->execute();
  }

  private function saveRecordsAndWriteToCsv(
    RemotePerson $remotePerson,
    LocalPerson $localPerson,
    array $outRow): array {
    $errorMessage = $errorContext = NULL;
    if (!$this->dryRun && $remotePerson->isAltered()) {
      try {
        $actNetResult = $this->system->trySave($remotePerson);
        if ($actNetResult->isError()) {
          $errorMessage = $actNetResult->getStatus() . ': ' . $actNetResult->getMessage();
          $errorContext = $actNetResult->getContext();
        }
      }
      catch (\Throwable $e) {
        $errorMessage = $e->getMessage();
        $errorContext = $e->getTrace();
      }
    }

    if (!$this->dryRun && $localPerson->isAltered() && is_null($errorMessage) && is_null($errorContext)) {
      try {
        $civiRecordIsNew = empty($localPerson->getId());
        $localPerson->save();
        if ($civiRecordIsNew) {
          $this->processedCiviIds[] = $localPerson->getId();
        }
      }
      catch (\Throwable $e) {
        $errorMessage = $e->getMessage();
        $errorContext = $e->getTrace();
      }
    }

    if (is_null($errorMessage) && is_null($errorContext)) {
      $outRow[$this->columnOffsets['sync status']] = 'success';
    }
    else {
      $outRow[$this->columnOffsets['sync status']] = "error: $errorMessage";
      Logger::logError($errorMessage, $errorContext);
    }

    $this->out->insertOne($outRow);
    return $outRow;
  }

}