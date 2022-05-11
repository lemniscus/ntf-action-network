<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use Civi\Osdi\RemoteSystemInterface;
use CRM_NtfActionNetwork_ExtensionUtil as E;

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
                            RemotePerson $remotePerson): RemotePerson {

    $l = $localPerson->loadOnce();
    $r = $remotePerson;

    $localIsNewer = ($l->modifiedDate->get() > $r->modifiedDate->get());

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
      $this->mapPhoneFromLocalToRemote($l, $r);
    }
    else {
      $this->mapPhoneFromRemoteToLocal($r, $localPerson);
    }

    if (empty($zip = $l->addressPostalCode->get())) {
      $dummyZip = $this->normalMapper->addZipCode($l);
      $zip = $l->addressPostalCode->get();
    }
    if ($zip) {
      $r->postalStreet->set($l->addressStreetAddress->get());
      $r->postalLocality->set($l->addressCity->get());
      $r->postalRegion->set($l->addressStateProvinceIdAbbreviation->get());
      $r->postalCode->set($zip);
      $r->postalCountry->set($l->addressCountryIdName->get());
    }
    $r->customFields->set(array_merge(
        $r->customFields->get() ?? [],
        ['Dummy ZIP' => $dummyZip ?? 'no']
      ));

    return $r;
  }

  private function mapPhoneFromLocalToRemote(LocalPerson $l, RemotePerson $r): void {
    $phoneNumberLocal = $l->smsPhonePhoneNumeric->get();
    $noSms = $l->isOptOut->get() || $l->doNotSms->get() || empty($phoneNumberLocal);
    $r->phoneNumber->set($phoneNumberLocal);
    $r->phoneStatus->set($noSms ? 'unsubscribed' : 'subscribed');
  }

  private function mapPhoneFromRemoteToLocal(RemotePerson $r, LocalPerson $l): void {
    $phoneNumberRemote = $r->phoneNumber->get();

    if (empty($phoneNumberRemote)) {
      $l->smsPhonePhone->set(NULL);
      return;
    }

    if ('subscribed' === $r->phoneStatus->get()) {
      $l->smsPhonePhone->set($phoneNumberRemote);
      $l->smsPhoneIsPrimary->set(TRUE);
    }
    else {
      $l->smsPhonePhone->set(NULL);
      $l->nonSmsMobilePhonePhone->set($phoneNumberRemote);
      $l->nonSmsMobilePhoneIsPrimary->set(TRUE);
    }

  }

}
