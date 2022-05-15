<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\RemoteSystemInterface;

class Reconciliation2022May001 {

  private RemoteSystemInterface $remoteSystem;
  private NineToFive2022May $normalMapper;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
    $this->normalMapper = new NineToFive2022May($remoteSystem);
  }

  public function mapLocalToRemote(LocalPerson $localPerson,
                                   RemotePerson $remotePerson = NULL): RemotePerson {
    return $this->normalMapper->mapLocalToRemote($localPerson, $remotePerson);
  }

  public function mapRemoteToLocal(RemotePerson $remotePerson,
                                   LocalPerson $localPerson = NULL): LocalPerson {
    return $this->normalMapper->mapRemoteToLocal($remotePerson, $localPerson);
  }

  public function reconcile(LocalPerson $localPerson,
                            RemotePerson $remotePerson): LocalRemotePair {

    $l = $localPerson->getId() ? $localPerson->loadOnce() : $localPerson;
    $r = $remotePerson;
    $messages = [];

    $localModTime = strtotime($l->modifiedDate->get());
    $remoteModTime = strtotime($r->modifiedDate->get());
    $localIsNewer = ($localModTime > $remoteModTime);

    // emails should already match.
    // names should already match if matcher is working according to spec.
    // so do nothing with names or emails.

    if (empty($l->individualLanguagesSpoken->get())) {
      if ('es' === $r->languageSpoken->get()) {
        $l->individualLanguagesSpoken->set(['spa']);
      }
    }
    else {
      $language = implode('&', $l->individualLanguagesSpoken->get());
      $languageMap = ['eng' => 'en', 'eng&spa' => 'en', 'spa&eng' => 'en', 'spa' => 'es'];
      $r->languageSpoken->set($languageMap[$language] ?? '');
    }

    $noEmailsLocal = $l->isOptOut->get() || $l->doNotEmail->get();
    $noEmailsRemote = ('unsubscribed' === $r->emailStatus->get());

    $r->emailStatus->set($noEmailsLocal ? 'unsubscribed' : $r->emailStatus->get());
    $l->doNotEmail->set($noEmailsRemote ? TRUE : $l->doNotEmail->get());

    if ($localIsNewer) {
      $message = $this->mapPhoneFromLocalToRemote($l, $r);
    }
    else {
      $message = $this->mapPhoneFromRemoteToLocal($r, $localPerson);
    }
    if ($message) {
      $messages[] = $message;
    }

    $streetAddressesMatch = $l->addressStreetAddress->get() == $r->postalStreet->get();
    $addressesMatch = $streetAddressesMatch
      && ($l->addressCity->get() == $r->postalLocality->get())
      && ($l->addressStateProvinceIdAbbreviation->get() == $r->postalRegion->get());

    if (empty($zipForActNet = $l->addressPostalCode->get()) && !$addressesMatch) {
      $dummyZip = $this->normalMapper->addRealZipOrReturnDummy($l);
      if ($dummyZip) {
        $zipForActNet = $dummyZip;
      }
      else {
        $zipForActNet = $l->addressPostalCode->get();
      }
    }

    if ($zipForActNet) {
      if ($zipForActNet !== $r->postalCode->get()) {
        $r->customFields->set(array_merge(
          $r->customFields->get() ?? [],
          ['Dummy ZIP' => $dummyZip ?? 'no']
        ));
      }

      $r->postalStreet->set($l->addressStreetAddress->get());
      $r->postalLocality->set($l->addressCity->get());
      $r->postalRegion->set($l->addressStateProvinceIdAbbreviation->get());
      $r->postalCode->set($zipForActNet);
      $r->postalCountry->set($l->addressCountryIdName->get());

      if (!$streetAddressesMatch) {
        $messages[] = 'street address was changed';
      }

    }

    return new LocalRemotePair($l, $r, FALSE, implode('; ', $messages));
  }

  private function mapPhoneFromLocalToRemote(LocalPerson $l, RemotePerson $r): ?string {
    $phoneNumberLocal = $l->smsPhonePhoneNumeric->get();
    if (empty($phoneNumberLocal) && empty($r->phoneNumber->get())) {
      return NULL;
    }
    $noSms = $l->isOptOut->get() || $l->doNotSms->get() || empty($phoneNumberLocal);
    $r->phoneNumber->set($phoneNumberLocal);
    if ($r->phoneNumber->isAltered()) {
      $message = 'changing phone on a.n. can have unexpected results';
    }
    $r->phoneStatus->set($noSms ? 'unsubscribed' : 'subscribed');
    return $message ?? NULL;
  }

  private function mapPhoneFromRemoteToLocal(RemotePerson $r, LocalPerson $l): ?string {
    $phoneNumberRemote = $r->phoneNumber->get();

    if (empty($phoneNumberRemote)) {
      $l->smsPhonePhone->set(NULL);
      return NULL;
    }

    $phoneNumberRemote = preg_replace('/[^0-9]/', '', $phoneNumberRemote);
    $phoneNumberRemote = preg_replace('/^1(\d{10})$/', '$1', $phoneNumberRemote);

    if ('subscribed' === $r->phoneStatus->get()) {
      $l->smsPhonePhone->set($phoneNumberRemote);
      $l->smsPhoneIsPrimary->set(TRUE);
      if ($l->nonSmsMobilePhoneIsPrimary->get()) {
        $l->nonSmsMobilePhoneIsPrimary->set(FALSE);
      }
      if ($phoneNumberRemote === $l->nonSmsMobilePhonePhoneNumeric->get()) {
        $l->nonSmsMobilePhonePhone->set(NULL);
      }
    }
    else {
      $l->smsPhonePhone->set(NULL);
      $l->nonSmsMobilePhonePhone->set($phoneNumberRemote);
      $l->nonSmsMobilePhoneIsPrimary->set(TRUE);
    }
    return NULL;
  }

}
