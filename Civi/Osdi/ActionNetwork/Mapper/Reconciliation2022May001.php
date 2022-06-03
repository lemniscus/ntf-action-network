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

    $emailIsDummy = 'noemail@' === substr($l->emailEmail->get(), 0, 8);
    $noEmailsLocal = $l->isOptOut->get() || $l->doNotEmail->get() || $emailIsDummy;
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

    $noSmsRemote = $r->phoneStatus->get() === 'unsubscribed' && !empty($r->phoneNumber->get());
    $noSmsLocal = $l->isOptOut->get() || $l->doNotSms->get();
    if ($noSmsRemote && !$noSmsLocal) {
      $l->doNotSms->set(TRUE);
    }
    if ($noSmsLocal && !empty($r->phoneNumber->get()) && $r->phoneStatus->get() !== 'bouncing') {
      $r->phoneStatus->set('unsubscribed');
    }

    $streetAddressesMatch = $this->streetAddressesMatch($l, $r);
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

      if (!$streetAddressesMatch && !empty($r->postalStreet->getOriginal())) {
        $messages[] = 'street address was changed';
      }

    }

    return new LocalRemotePair($l, $r, FALSE, implode('; ', $messages));
  }

  private function mapPhoneFromLocalToRemote(LocalPerson $l, RemotePerson $r): ?string {
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

    if (empty($localNumberNorm) && !empty($remoteNumberNorm)) {
      if ($r->phoneStatus->get() !== 'bouncing') {
        $r->phoneStatus->set('unsubscribed');
      }
    }

    return $message ?? NULL;
  }

  private function mapPhoneFromRemoteToLocal(RemotePerson $r, LocalPerson $l): ?string {
    $remoteNumberNorm = $this->normalizePhoneNumber($r->phoneNumber->get());
    $localNumberNorm = $this->normalizePhoneNumber($l->smsPhonePhone->get());

    if ('subscribed' === $r->phoneStatus->get() && !empty($remoteNumberNorm)) {
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
    else {
      $l->smsPhonePhone->set(NULL);
      if (!empty($remoteNumberNorm)) {
        $l->nonSmsMobilePhonePhone->set($remoteNumberNorm);
        $l->nonSmsMobilePhoneIsPrimary->set(TRUE);
      }
    }

    return NULL;
  }

  private function normalizePhoneNumber(?string $phoneNumber = ''): string {
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    $phoneNumber = preg_replace('/^1(\d{10})$/', '$1', $phoneNumber);
    $phoneNumber = preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $phoneNumber);
    return $phoneNumber;
  }

  private function streetAddressesMatch(LocalPerson $l, RemotePerson $r): bool {
    $norm = function ($subject) {
      $patterns = [
        '/\\bN\\b\\.?/i',
        '/\\bS\\b\\.?/i',
        '/\\bE\\b\\.?/i',
        '/\\bW\\b\\.?/i',
        '/\\bAve\\b\\.?/i',
        '/\\bBlvd\\b\\.?/i',
        '/\\bCir\\b\\.?/i',
        '/\\bCt\\b\\.?/i',
        '/\\bDr\\b\\.?/i',
        '/\\bHwy\\b\\.?/i',
        '/\\bLn\\b\\.?/i',
        '/\\bRd\\b\\.?/i',
        '/\\bRte\\b\\.?/i',
        '/\\bSt\\b\\.?/i',
      ];
      $replacements = [
        'North',
        'South',
        'East',
        'West',
        'Avenue',
        'Boulevard',
        'Circle',
        'Court',
        'Drive',
        'Highway',
        'Lane',
        'Road',
        'Route',
        'Street',
      ];
      return strtolower(preg_replace($patterns, $replacements, $subject));
    };
    $normLocal = $norm($l->addressStreetAddress->get());
    $normRemote = $norm($r->postalStreet->get());
    return $normLocal === $normRemote;
  }

}
