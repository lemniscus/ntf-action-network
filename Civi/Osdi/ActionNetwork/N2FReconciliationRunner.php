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

  private array $processedActNetIds;

  private array $processedCiviIds;

  private array $columnOffsets;

  private AbstractCsv $out;

  private Reconciliation2022May001 $mapper;

  /**
   * @var false|resource
   */
  private $statusFile;

  public function __construct() {
    $this->system = \CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
  }

  public function setInput(string $path) {
    $this->inputFilePath = $path;
  }

  public function setOutput(string $path) {
    $this->outputFilePath = $path;
  }

  public function run() {
    $this->mapper = new Reconciliation2022May001($this->system);
    \CRM_OSDI_ActionNetwork_Fixture::setUpGeocoding();

    $header = $this->getHeader();
    $this->columnOffsets = $columnOffsets = array_flip($header);
    $emptyOutRow = array_fill(0, count($header), NULL);
    $this->processedActNetIds = $this->processedCiviIds = [];

    $this->statusFile = fopen(sys_get_temp_dir() . '/n2f_reconciliation_status', 'w+');

    $this->out = Writer::createFromPath($this->outputFilePath, 'w+');
    $this->out->insertOne($header);

    $this->in = Reader::createFromPath($this->inputFilePath);
    $this->in->setHeaderOffset(0);

    $this->processInputCsv($emptyOutRow, $columnOffsets);
    rewind($this->statusFile);
    $csvInputFinalStatus = fgets($this->statusFile) . "\n";

    $civiEmails = $this->civiApi4EmailGet();
    $totalEmails = $civiEmails->count();

    foreach ($civiEmails as $i => $emailRecord) {
      if ($i % 100 === 0) {
        rewind($this->statusFile);
        fwrite($this->statusFile, $csvInputFinalStatus . ($i + 1) .
          " of $totalEmails Civi records processed");
      }

      if (in_array($emailRecord['contact_id'], $this->processedCiviIds)) {
        continue;
      }

      $outRow = $emptyOutRow;
      $localPerson = new LocalPerson($emailRecord['contact_id']);
      $this->processCiviBefore($localPerson->loadOnce(), $outRow);

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
      $this->out->insertOne($outRow);
    }

    rewind($this->statusFile);
    fwrite($this->statusFile, $csvInputFinalStatus . ($i + 1) .
      " of $totalEmails Civi records processed");
    fclose($this->statusFile);
  }

  private function processInputCsv(array $emptyOutRow, array $columnOffsets) {
    foreach ($this->in as $i => $actNetRecord) {
      if (($i % 100) === 0) {
        rewind($this->statusFile);
        fwrite($this->statusFile, "$i CSV rows processed");
      }

      $outRow = $emptyOutRow;

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

        $namesMatch = $localPerson->firstName->get() === $remotePerson->givenName->get()
          && $localPerson->lastName->get() === $remotePerson->familyName->get();

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

      $this->out->insertOne($outRow);
    }

    rewind($this->statusFile);
    fwrite($this->statusFile, "$i CSV rows processed");
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
      $outRow[$columnOffsets['may need review']] = 'yes';
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
      'may need review',
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
      ->addWhere('contact.contact_type', '=', 'Individual');

    if ($email) {
      $emailGet->addWhere('email', '=', $email);
    }

    return $emailGet->execute();
  }

}