<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use CRM_NtfActionNetwork_ExtensionUtil as E;

class NineToFive2022May {

  public function mapLocalToRemote(LocalPerson $localPerson,
      RemotePerson $remotePerson = NULL): RemotePerson {

    $l = $localPerson->loadOnce();
    $remotePerson = $remotePerson ?? new RemotePerson();

    $remotePerson->set('given_name', $l->firstName->get());
    $remotePerson->set('family_name', $l->lastName->get());
    if (!empty($l->individualLanguagesSpoken->get())) {
      $language = implode('&', $l->individualLanguagesSpoken->get());
      $languageMap = ['eng' => 'en', 'eng&spa' => 'en', 'spa&eng' => 'en', 'spa' => 'es'];
      $remotePerson->set('languages_spoken', [$languageMap[$language] ?? '']);
    }

    $noEmails = $l->isOptOut->get() || $l->doNotEmail->get();
    $remotePerson->set('email_addresses', [
      [
        'address' => $l->emailEmail->get(),
        'status' => $noEmails ? 'unsubscribed' : 'subscribed',
      ],
    ]);

    $phoneNumber = $l->smsPhonePhoneNumeric->get();
    $noSms = $l->isOptOut->get() || $l->doNotSms->get() || empty($phoneNumber);
    $remotePerson->set('phone_numbers', [
      [
        'number' => $phoneNumber ?? '',
        'status' => $noSms ? 'unsubscribed' : 'subscribed',
      ],
    ]);

    if (empty($zip = $l->addressPostalCode->get())) {
      $dummyZip = $this->addZipCode($l);
      $zip = $l->addressPostalCode->get();
    }
    if ($zip) {
      $remotePerson->set('postal_addresses', [
        [
          'address_lines' => [$l->addressStreetAddress->get()],
          'locality' => $l->addressCity->get(),
          'region' => $l->addressStateProvinceIdAbbreviation->get(),
          'postal_code' => $zip,
          'country' => $l->addressCountryIdName->get(),
        ],
      ]);
    }
    $remotePerson->set(
      'custom_fields',
      array_merge(
        $remotePerson->get('custom_fields') ?? [],
        ['Dummy ZIP' => $dummyZip ?? 'no']
      )
    );
    return $remotePerson;
  }

  public function mapRemoteToLocal(RemotePerson $remotePerson,
      LocalPerson $localPerson = NULL): LocalPerson {

    $localPerson = $localPerson ?? new LocalPerson();

    $localPerson->firstName->set($remotePerson->get('given_name'));
    $localPerson->lastName->set($remotePerson->get('family_name'));
    $localPerson->individualLanguagesSpoken->set(
      $this->mapLanguageFromActionNetwork($remotePerson, $localPerson));

    if ($rpEmail = $remotePerson->getEmailAddress() ?? NULL) {
      $localPerson->emailEmail->set($rpEmail);
    }

    $rpPhone = $remotePerson->get('phone_numbers')[0] ?? NULL;
    if ($rpPhone['number'] ?? FALSE) {
      if ('subscribed' === $rpPhone['status'] ?? NULL) {
        $localPerson->smsPhonePhone->set($rpPhone['number']);
        $localPerson->smsPhoneIsPrimary->set(TRUE);
      }
      else {
        $localPerson->smsPhonePhone->set(NULL);
        $localPerson->nonSmsMobilePhonePhone->set($rpPhone['number']);
        $localPerson->nonSmsMobilePhoneIsPrimary->set(TRUE);
      }
    }

    if ($rpAddress = $remotePerson->get('postal_addresses')[0] ?? NULL) {
      [$stateId, $countryId]
        = $this->getStateAndCountryIdsFromActNetAddress($rpAddress);
      $dummyZIP = $remotePerson->get('custom_fields')['Dummy ZIP'] ?? '';
      if ($rpAddress['postal_code'] === $dummyZIP) {
        $rpAddress['postal_code'] = $rpAddress['locality'] = NULL;
      }
      $localPerson->addressStreetAddress
        ->set($rpAddress['address_lines'][0] ?? '');
      $localPerson->addressCity->set($rpAddress['locality']);
      $localPerson->addressStateProvinceId->set($stateId);
      $localPerson->addressPostalCode->set($rpAddress['postal_code']);
      $localPerson->addressCountryId->set($countryId);
    }
    return $localPerson;
  }

  private function getStateAndCountryIdsFromActNetAddress(array $actNetAddress): array {
    $countryId = \CRM_Core_Config::singleton()->defaultContactCountry;
    if (isset($actNetAddress['country'])) {
      $countryIdList = \CRM_Core_BAO_Address::buildOptions(
        'country_id',
        'abbreviate'
      );
      $idFromAbbrev = array_search($actNetAddress['country'], $countryIdList);
      if ($idFromAbbrev !== FALSE) {
        $countryId = $idFromAbbrev;
      }
    }
    if (!($stateAbbrev = $actNetAddress['region'] ?? FALSE)) {
      return [NULL, $countryId];
    }
    $stateAbbrevList = \CRM_Core_BAO_Address::buildOptions(
      'state_province_id',
      'abbreviate',
      ['country_id' => $countryId]
    );
    $stateId = array_search($stateAbbrev, $stateAbbrevList);
    if ($stateId === FALSE) {
      $stateId = NULL;
    }
    return [$stateId, $countryId];
  }

  public function mapLanguageFromActionNetwork(RemotePerson $remotePerson,
                                               LocalPerson $localPerson): ?array {
    $rpLanguages = $remotePerson->get('languages_spoken');
    if ('es' === ($rpLanguages[0] ?? '')) {
      if (empty($localPerson->individualLanguagesSpoken->get())) {
        return ['spa'];
      }
    }
    return $localPerson->individualLanguagesSpoken->get();
  }

  /**
   * @return void
   */
  private static function getZipMap(): array {
    static $zipMap;
    if (!isset($zipMap)) {
      $zipMap = include E::path('resources/stateCityZipMap.php');
    }
    return $zipMap;
  }

  /**
   * @return string[]
   */
  public static function getDummyZIPCodes(): array {
    return [
      'AL' => '35187',
      'AK' => '99706',
      'AZ' => '86003',
      'AR' => '72636',
      'CA' => '95375',
      'CO' => '80479',
      'CT' => '06856',
      'DE' => '19735',
      'FL' => '32830',
      'GA' => '31045',
      'HI' => '96863',
      'ID' => '83671',
      'IL' => '62344',
      'IN' => '46183',
      'IA' => '50033',
      'KS' => '67559',
      'KY' => '41760',
      'LA' => '71316',
      'ME' => '04741',
      'MD' => '21705',
      'MA' => '02651',
      'MI' => '49434',
      'MN' => '56210',
      'MS' => '38704',
      'MO' => '63464',
      'MT' => '59333',
      'NE' => '69171',
      'NV' => '89831',
      'NH' => '03754',
      'NJ' => '07881',
      'NM' => '88343',
      'NY' => '12007',
      'NC' => '28672',
      'ND' => '58239',
      'OH' => '45618',
      'OK' => '74068',
      'OR' => '97057',
      'PA' => '18459',
      'RI' => '02836',
      'SC' => '29079',
      'SD' => '57117',
      'TN' => '38021',
      'TX' => '79831',
      'UT' => '84753',
      'VT' => '05901',
      'VA' => '20118',
      'WA' => '98853',
      'WV' => '25853',
      'WI' => '54643',
      'WY' => '82229',
      'AS' => '96799',
      'GU' => '96910',
      'MP' => '96951',
      'PR' => '00694',
      'VI' => '00824',
      'DC' => '20319',
    ];
  }

  private function addZipCode(LocalPerson $l): ?string {
    $country = $l->addressCountryIdName->get();
    if (!empty($country) && $country !== 'US') {
      return NULL;
    }

    if (empty($state = $l->addressStateProvinceIdAbbreviation->get())) {
      return NULL;
    }

    if (empty($city = $l->addressCity->get())) {
      $dummyZip = self::getDummyZIPCodes()[$state];
      $l->addressPostalCode->set($dummyZip);
      return $dummyZip;
    }

    $address = [
      'street_address' => $l->addressStreetAddress->get(),
      'city' => $l->addressCity->get(),
      'state_province_id' => $l->addressStateProvinceId->get(),
      'country' => 'US',
    ];
    $geocoder = \CRM_Utils_GeocodeProvider::getConfiguredProvider();
    $geocoder::format($address);
    if ($zip = $address['zip'] ?? NULL) {
      $l->addressPostalCode->set($zip);
      return NULL;
    }

    $dummyZip = self::getZipMap()[$state][$city] ?? NULL;
    $l->addressPostalCode->set($dummyZip);
    return $dummyZip;
  }

}
