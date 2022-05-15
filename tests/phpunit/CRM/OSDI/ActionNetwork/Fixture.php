<?php

class CRM_OSDI_ActionNetwork_Fixture {

  public static function setUpCustomPhoneType(): int {
    $existingPhoneTypeId = \Civi\Api4\OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', 'phone_type')
      ->addWhere('name', '=', 'SMS Permission - Mobile')
      ->execute()->first()['id'] ?? FALSE;

    if ($existingPhoneTypeId) {
      return $existingPhoneTypeId;
    }

    $currentMaxValue = \Civi\Api4\OptionValue::get()
      ->addSelect('MAX(value)')
      ->addGroupBy('option_group_id')
      ->addWhere('option_group_id:name', '=', 'phone_type')
      ->execute()->single()['MAX:value'];
    $create = \Civi\Api4\OptionValue::create(FALSE)
      ->setValues([
        'option_group_id:name' => 'phone_type',
        'label' => 'SMS Opt In',
        'value' => $currentMaxValue + 1,
        'name' => 'SMS Permission - Mobile',
        'filter' => 0,
        'is_default' => FALSE,
        'is_optgroup' => FALSE,
        'is_reserved' => FALSE,
        'is_active' => TRUE,
      ])->execute()->single();

    return $create['id'];
  }

  public static function setUpCustomField(): void {
    $customGroupExists = \Civi\Api4\CustomGroup::get(FALSE)
      ->addWhere('name', '=', 'Individual')
      ->selectRowCount()->execute()->count();
    if (!$customGroupExists) {
      \Civi\Api4\CustomGroup::create(FALSE)
        ->setValues([
          'name' => 'Individual',
          'title' => 'More about this individual',
          'extends' => 'Individual',
          'style' => 'Inline',
          'collapse_display' => FALSE,
          'weight' => 7,
          'is_active' => TRUE,
          'table_name' => 'civicrm_value_individual',
          'is_multiple' => FALSE,
          'collapse_adv_display' => FALSE,
          'is_reserved' => FALSE,
          'is_public' => TRUE,
        ])->execute();
    }

    $optionGroupExists = \Civi\Api4\OptionGroup::get(FALSE)
      ->addWhere('name', '=', 'languages_spoken')
      ->selectRowCount()->execute()->count();
    if (!$optionGroupExists) {
      \Civi\Api4\OptionGroup::create(FALSE)
        ->setValues([
          'name' => 'languages_spoken',
          'title' => 'Languages Spoken',
          'data_type' => 'String',
          'is_reserved' => FALSE,
          'is_active' => TRUE,
          'is_locked' => FALSE,
        ])->execute();
      \Civi\Api4\OptionValue::save(FALSE)
        ->setRecords([
          [
            'option_group_id:name' => 'languages_spoken',
            'label' => 'English',
            'value' => 'eng',
            'name' => 'English',
            'filter' => 0,
            'is_default' => FALSE,
            'weight' => 1,
            'description' => '',
            'is_optgroup' => FALSE,
            'is_reserved' => FALSE,
            'is_active' => TRUE,
          ],
          [
            'option_group_id:name' => 'languages_spoken',
            'label' => 'Español',
            'value' => 'spa',
            'name' => 'Espa_ol',
            'filter' => 0,
            'is_default' => FALSE,
            'weight' => 2,
            'description' => '',
            'is_optgroup' => FALSE,
            'is_reserved' => FALSE,
            'is_active' => TRUE,
          ],
          [
            'option_group_id:name' => 'languages_spoken',
            'label' => 'English y Español',
            'value' => 'eng&spa',
            'name' => 'I_speak_English_and_Spanish_Hab',
            'is_default' => FALSE,
            'weight' => 3,
            'description' => '',
            'is_optgroup' => FALSE,
            'is_reserved' => FALSE,
            'is_active' => TRUE,
          ],
        ])->execute();
    }

    $customFieldExists = \Civi\Api4\CustomField::get(FALSE)
      ->addWhere('name', '=', 'Languages_spoken')
      ->selectRowCount()->execute()->count();
    if (!$customFieldExists) {
      \Civi\Api4\CustomField::create(FALSE)
        ->setValues([
          'custom_group_id:name' => 'Individual',
          'name' => 'Languages_spoken',
          'label' => 'Languages/ Idiomas',
          'data_type' => 'String',
          'html_type' => 'Select',
          'is_searchable' => TRUE,
          'is_search_range' => FALSE,
          'weight' => 164,
          'is_active' => TRUE,
          'text_length' => 255,
          'note_columns' => 60,
          'note_rows' => 4,
          'column_name' => 'languages_spoken',
          'option_group_id:name' => 'languages_spoken',
          'serialize' => 1,
          'in_selector' => FALSE,
        ])->execute();
    }
  }

  public static function setUpGeocoding(): void {
    $googleAPIToken = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'GoogleAPIToken');
    \Civi\Api4\Setting::set()
      ->addValue('geoProvider', 'Google')
      ->addValue('geoAPIKey', $googleAPIToken)
      ->addValue('mapProvider', 'Google')
      ->addValue('mapAPIKey', $googleAPIToken)
      ->execute();
    CRM_Utils_GeocodeProvider::reset();
  }

  public static function disableGeocoding(): void {
    \Civi\Api4\Setting::revert()
      ->addSelect('geoProvider', 'mapProvider', 'geoAPIKey', 'mapAPIKey')
      ->execute();
    CRM_Utils_GeocodeProvider::reset();
  }

}
