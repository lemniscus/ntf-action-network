<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\SingleSyncerInterface;
use Civi\Osdi\Util;

class PersonN2F extends PersonBasic implements SingleSyncerInterface {

  protected function getRemoteObjectClass(): string {
    return \Civi\Osdi\ActionNetwork\Object\Person::class;
  }

  protected function newRemoteShouldBeCreatedForLocal(LocalRemotePair $pair): bool {
    $localPerson = $pair->getLocalObject();
    $localPerson->loadOnce();
    $doNotEmail = $localPerson->doNotEmail->get();
    $emailOnHold = $localPerson->emailOnHold->get();
    $emailIsDummy = 'noemail@' === substr($localPerson->emailEmail->get(), 0, 8);
    $doNotSms = $localPerson->doNotSms->get();

    if ($doNotEmail || $emailIsDummy || $emailOnHold) {
      if ($doNotSms || empty($localPerson->smsPhonePhone->get())) {
        return TRUE;
      }
    }

    return FALSE;
  }

  protected function typeCheckRemotePerson(RemoteObjectInterface $object): \Civi\Osdi\ActionNetwork\Object\Person {
    Util::assertClass($object, \Civi\Osdi\ActionNetwork\Object\Person::class);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $object */
    return $object;
  }

  protected function getLocalObjectClass(): string {
    return \Civi\Osdi\LocalObject\PersonN2F::class;
  }

  private function typeCheckLocalPerson(LocalObjectInterface $object): \Civi\Osdi\LocalObject\PersonN2F {
    Util::assertClass($object, \Civi\Osdi\LocalObject\PersonN2F::class);
    /** @var \Civi\Osdi\LocalObject\PersonN2F $object */
    return $object;
  }

}
