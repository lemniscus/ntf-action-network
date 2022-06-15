<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use Civi\Osdi\RemoteSystemInterface;
use CRM_NtfActionNetwork_ExtensionUtil as E;

class NineToFive2022May {

  private RemoteSystemInterface $remoteSystem;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function mapLocalToRemote(LocalPerson $localPerson,
      RemotePerson $remotePerson = NULL): RemotePerson {

    $l = $localPerson->loadOnce();
    $remotePerson = $remotePerson ?? new RemotePerson($this->remoteSystem);

    $remotePerson->givenName->set($l->firstName->get());
    $remotePerson->familyName->set($l->lastName->get());
    if (!empty($l->individualLanguagesSpoken->get())) {
      $language = implode('&', $l->individualLanguagesSpoken->get());
      $languageMap = ['eng' => 'en', 'eng&spa' => 'en', 'spa&eng' => 'en', 'spa' => 'es'];
      $remotePerson->languageSpoken->set($languageMap[$language] ?? '');
    }

    $localEmailAddress = $l->emailEmail->get();
    $remotePerson->emailAddress->set($localEmailAddress);

    $emailIsDummy = 'noemail@' === substr($localEmailAddress, 0, 8);
    $noEmailsLocal = $l->isOptOut->get() || $l->doNotEmail->get()
      || $l->emailOnHold->get() || $emailIsDummy;
    $noEmailsRemote = 'subscribed' !== $remotePerson->emailStatus->get();
    if (empty($remotePerson->emailStatus->get()) || ($noEmailsLocal !== $noEmailsRemote)) {
      $remotePerson->emailStatus->set($noEmailsLocal ? 'unsubscribed' : 'subscribed');
    }

    $this->mapPhoneFromLocalToRemote($l, $remotePerson);

    if (empty($zipForActNet = $l->addressPostalCode->get())) {
      $dummyZip = $this->addRealZipOrReturnDummy($l);
      if ($dummyZip) {
        $zipForActNet = $dummyZip;
      }
      else {
        $zipForActNet = $l->addressPostalCode->get();
      }
    }

    if ($zipForActNet) {
      $remotePerson->postalStreet->set($l->addressStreetAddress->get());
      $remotePerson->postalLocality->set($l->addressCity->get());
      $remotePerson->postalRegion->set($l->addressStateProvinceIdAbbreviation->get());
      $remotePerson->postalCode->set($zipForActNet);
      $remotePerson->postalCountry->set($l->addressCountryIdName->get());
    }
    $remotePerson->customFields->set(array_merge(
        $remotePerson->customFields->get() ?? [],
        ['Dummy ZIP' => $dummyZip ?? 'no']
      ));
    return $remotePerson;
  }

  public function mapRemoteToLocal(RemotePerson $remotePerson,
      LocalPerson $localPerson = NULL): LocalPerson {

    $localPerson = $localPerson ?? new LocalPerson();

    $localPerson->firstName->set($remotePerson->givenName->get());
    $localPerson->lastName->set($remotePerson->familyName->get());
    $localPerson->individualLanguagesSpoken->set(
      $this->mapLanguageFromActionNetwork($remotePerson, $localPerson));

    if ($rpEmail = $remotePerson->emailAddress->get()) {
      $localPerson->emailEmail->set($rpEmail);
      $remoteEmailStatus = $remotePerson->emailStatus->get();
      if ('subscribed' === $remoteEmailStatus) {
        $localPerson->doNotEmail->set(FALSE);
      }
      else {
        $localPerson->doNotEmail->set(TRUE);
      }
    }

    if ($rpPhoneNumber = $remotePerson->phoneNumber->get()) {
      if ('subscribed' === $remotePerson->phoneStatus->get()) {
        $localPerson->smsPhonePhone->set($rpPhoneNumber);
        $localPerson->smsPhoneIsPrimary->set(TRUE);
      }
      else {
        $localPerson->smsPhonePhone->set(NULL);
        $localPerson->nonSmsMobilePhonePhone->set($rpPhoneNumber);
        $localPerson->nonSmsMobilePhoneIsPrimary->set(TRUE);
      }
    }

    if ($zip = $remotePerson->postalCode->get()) {
      [$stateId, $countryId] = $this->getStateAndCountryIds($remotePerson);

      $zipIsDummy = ($zip === ($remotePerson->customFields->get()['Dummy ZIP'] ?? ''));

      $localPerson->addressStreetAddress->set($remotePerson->postalStreet->get());
      if (!$zipIsDummy) {
        $localPerson->addressCity->set($remotePerson->postalLocality->get());
      }
      $localPerson->addressStateProvinceId->set($stateId);
      $localPerson->addressPostalCode->set($zipIsDummy ? NULL : $zip);
      $localPerson->addressCountryId->set($countryId);
    }
    return $localPerson;
  }

  private function getStateAndCountryIds(RemotePerson $person): array {
    $countryId = \CRM_Core_Config::singleton()->defaultContactCountry;
    if (!empty($actNetCountry = $person->postalCountry->get())) {
      $countryIdList = \CRM_Core_BAO_Address::buildOptions(
        'country_id',
        'abbreviate'
      );
      $idFromAbbrev = array_search($actNetCountry, $countryIdList);
      if ($idFromAbbrev !== FALSE) {
        $countryId = $idFromAbbrev;
      }
    }
    if (empty($stateAbbrev = $person->postalRegion->get())) {
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

  public function mapPhoneFromLocalToRemote(LocalPerson $l, RemotePerson $r): ?string {
    $remoteNumberNorm = $this->normalizePhoneNumber($r->phoneNumber->get());
    $localNumberNorm = $this->normalizePhoneNumber($l->smsPhonePhone->get());

    if (empty($localNumberNorm) && empty($remoteNumberNorm)) {
      return NULL;
    }

    if (!empty($localNumberNorm) && ($localNumberNorm !== $remoteNumberNorm)) {
      $r->phoneNumber->set($localNumberNorm);
      $r->phoneStatus->set('subscribed');
      $message = 'changing phone on a.n. can have unexpected results';
    }

    $noSmsLocal = $l->isOptOut->get() || $l->doNotSms->get() || empty($localNumberNorm);
    if ($noSmsLocal && !empty($remoteNumberNorm)) {
      if ($r->phoneStatus->get() !== 'bouncing') {
        $r->phoneStatus->set('unsubscribed');
      }
    }

    return $message ?? NULL;
  }

  private function mapPhoneRemoteToLocalSubscribed(string $remoteNumberNorm, string $localNumberNorm, LocalPerson $l): void {
    if ($remoteNumberNorm !== $localNumberNorm) {
      $l->smsPhonePhone->set($remoteNumberNorm);
    }
    $l->smsPhoneIsPrimary->set(TRUE);
    if ($l->nonSmsMobilePhoneIsPrimary->get()) {
      $l->nonSmsMobilePhoneIsPrimary->set(FALSE);
    }
    if ($remoteNumberNorm === $this->normalizePhoneNumber($l->nonSmsMobilePhonePhoneNumeric->get())) {
      $l->nonSmsMobilePhonePhone->set(NULL);
    }
  }

  private function mapPhoneRemoteToLocalUnsubscribed(LocalPerson $l, string $remoteNumberNorm): void {
    $l->smsPhonePhone->set(NULL);
    if (!empty($remoteNumberNorm)) {
      $l->nonSmsMobilePhonePhone->set($remoteNumberNorm);
      $l->nonSmsMobilePhoneIsPrimary->set(TRUE);
      $noSmsLocal = $l->isOptOut->get() || $l->doNotSms->get();
      if (!$noSmsLocal) {
        $l->doNotSms->set(TRUE);
      }
    }
  }

  public function mapPhoneFromRemoteToLocal(RemotePerson $r, LocalPerson $l): ?string {
    $remoteNumberNorm = $this->normalizePhoneNumber($r->phoneNumber->get());
    $localNumberNorm = $this->normalizePhoneNumber($l->smsPhonePhone->get());

    if ('subscribed' === $r->phoneStatus->get() && !empty($remoteNumberNorm)) {
      $this->mapPhoneRemoteToLocalSubscribed($remoteNumberNorm, $localNumberNorm, $l);
    }
    else {
      $this->mapPhoneRemoteToLocalUnsubscribed($l, $remoteNumberNorm);
    }

    return NULL;
  }

  private function normalizePhoneNumber(?string $phoneNumber = ''): string {
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    $phoneNumber = preg_replace('/^1(\d{10})$/', '$1', $phoneNumber);
    $phoneNumber = preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $phoneNumber);
    return $phoneNumber;
  }

  public function mapLanguageFromActionNetwork(RemotePerson $remotePerson,
                                               LocalPerson $localPerson): ?array {
    $rpLanguage = $remotePerson->languageSpoken->get();
    if ('es' === $rpLanguage) {
      if (empty($localPerson->individualLanguagesSpoken->get())) {
        return ['spa'];
      }
    }
    return $localPerson->individualLanguagesSpoken->get();
  }

  /**
   * @return void
   */
  public static function getZipMap(): array {
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

  public function addRealZipOrReturnDummy(LocalPerson $l): ?string {
    $country = $l->addressCountryIdName->get();
    if (!empty($country) && $country !== 'US') {
      return NULL;
    }

    if (empty($state = $l->addressStateProvinceIdAbbreviation->get())) {
      return NULL;
    }

    if (empty($city = $l->addressCity->get())) {
      $dummyZip = self::getDummyZIPCodes()[$state];
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
    return $dummyZip;
  }

}
