<?php

namespace Civi\Osdi\LocalObject;

use Civi\Api4\Contact;
use Civi\Api4\Phone;

class PersonN2F extends PersonBasic {

  public Field $emailOnHold;
  public Field $individualLanguagesSpoken;
  public Field $nonSmsMobilePhoneId;
  public Field $nonSmsMobilePhoneIsPrimary;
  public Field $nonSmsMobilePhonePhone;
  public Field $nonSmsMobilePhonePhoneNumeric;
  public Field $smsPhoneId;
  public Field $smsPhoneIsPrimary;
  public Field $smsPhonePhone;
  public Field $smsPhonePhoneNumeric;

  protected static function getContactFieldMetadata(): array {
    return [
      'id' => ['select' => 'id'],
      'createdDate' => ['select' => 'created_date', 'readOnly' => TRUE],
      'modifiedDate' => ['select' => 'modified_date', 'readOnly' => TRUE],
      'firstName' => ['select' => 'first_name'],
      'lastName' => ['select' => 'last_name'],
      'isOptOut' => ['select' => 'is_opt_out'],
      'doNotEmail' => ['select' => 'do_not_email'],
      'doNotSms' => ['select' => 'do_not_sms'],
      'isDeleted' => ['select' => 'is_deleted'],
      'individualLanguagesSpoken' => ['select' => 'Individual.Languages_spoken'],
    ];
  }

  protected static function getEmailFieldMetadata(): array {
    return parent::getEmailFieldMetadata()
      + ['emailOnHold' => ['select' => 'email.on_hold']];
  }

  protected static function getPhoneFieldMetadata(): array {
    return [
      'nonSmsMobilePhoneId' => ['select' => 'non_sms_mobile_phone.id'],
      'nonSmsMobilePhoneIsPrimary' => ['select' => 'non_sms_mobile_phone.is_primary'],
      'nonSmsMobilePhonePhone' => ['select' => 'non_sms_mobile_phone.phone'],
      'nonSmsMobilePhonePhoneNumeric' => [
        'select' => 'non_sms_mobile_phone.phone_numeric',
        'readOnly' => TRUE,
      ],
      'smsPhoneId' => ['select' => 'sms_phone.id'],
      'smsPhoneIsPrimary' => ['select' => 'sms_phone.is_primary'],
      'smsPhonePhone' => ['select' => 'sms_phone.phone'],
      'smsPhonePhoneNumeric' => [
        'select' => 'sms_phone.phone_numeric',
        'readOnly' => TRUE,
      ],
    ];
  }

  public static function getJoins(): array {
    return [
      ['Email AS email', 'LEFT', NULL, ['email.is_primary', '=', 1]],
      ['Phone AS sms_phone', 'LEFT', NULL,
        ['sms_phone.phone_type_id:name', '=', '"SMS Permission - Mobile"'],
      ],
      ['Phone AS non_sms_mobile_phone', 'LEFT', NULL,
        ['non_sms_mobile_phone.phone_type_id:name', '=', '"Mobile"'],
      ],
      ['Address AS address', FALSE, NULL,
        ['address.is_primary', '=', 1],
      ],
    ];
  }

  public static function getOrderBys(): array {
    return [
      'sms_phone.id' => 'ASC',
      'non_sms_mobile_phone.id' => 'ASC',
    ];
  }

  protected function saveCoreContactFields() {
    $record = $this->getSaveableFieldContents('')
      + $this->getSaveableFieldContents('Individual', TRUE);
    $record['contact_type'] = 'Individual';
    $record['id'] = $this->getId();

    $postSaveContactArray = Contact::save(FALSE)
      ->addRecord($record)->execute()->first();
    return $postSaveContactArray['id'];
  }

  protected function savePhone($cid): void {
    $this->saveOrDeleteSmsPhone($cid);
    $this->saveNonSmsMobilePhone($cid);
  }

  protected function saveOrDeleteSmsPhone($cid): void {
    $phoneId = $this->smsPhoneId->get();
    if (empty($phone = $this->smsPhonePhone->get())) {
      if (!empty($phoneId)) {
        Phone::delete(FALSE)
          ->addWhere('id', '=', $phoneId)
          ->execute();
      }
      return;
    }

    $isPrimary = $this->smsPhoneIsPrimary->get();
    $this->savePhoneFinalStep($phoneId, $cid, $phone, 'SMS Permission - Mobile', $isPrimary);
  }

  protected function saveNonSmsMobilePhone($cid): void {
    if (empty($phone = $this->nonSmsMobilePhonePhone->get())) {
      return;
    }
    $phoneId = $this->nonSmsMobilePhoneId->get();
    $isPrimary = $this->nonSmsMobilePhoneIsPrimary->get();
    $this->savePhoneFinalStep($phoneId, $cid, $phone, 'Mobile', $isPrimary);
  }

  private function savePhoneFinalStep($phoneId, $cid, $phone, $phoneType, $isPrimary): void {
    Phone::save(FALSE)
      ->setMatch([
        'contact_id',
        'phone',
      ])
      ->addRecord([
        'id' => $phoneId,
        'contact_id' => $cid,
        'phone' => $phone,
        'phone_type_id:name' => $phoneType,
      ])->execute();
  }

}
