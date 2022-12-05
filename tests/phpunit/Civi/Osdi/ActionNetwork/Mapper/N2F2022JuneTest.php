<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi;
use Civi\Osdi\ActionNetwork\Mapper\PersonN2F2022June;
use Civi\Osdi\LocalObject\PersonN2F as LocalPerson;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_OSDI_ActionNetwork_Fixture as Fixture;
use CRM_OSDI_ActionNetwork_TestUtils;

/**
 * @group headless
 */
class N2F2022JuneTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  /**
   * @var array{Contact: array, OptionGroup: array, OptionValue: array,
   *   CustomGroup: array, CustomField: array}
   */
  private static $createdEntities = [];

  /**
   * @var \Civi\Osdi\ActionNetwork\RemoteSystem
   */
  public static $system;

  /**
   * @var \Civi\Osdi\ActionNetwork\Mapper\PersonN2F2022June
   */
  public static $mapper;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('osdi-client')
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass(): void {
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    self::$mapper = self::createMapper(self::$system);
    self::setUpCustomConfig();

    parent::setUpBeforeClass();
  }

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {
    foreach (self::$createdEntities as $type => $ids) {
      foreach ($ids as $id) {
        civicrm_api4($type, 'delete', [
          'where' => [['id', '=', $id]],
          'checkPermissions' => FALSE,
        ]);
      }
    }

    parent::tearDownAfterClass();
  }

  public static function setUpCustomConfig(): void {
    Fixture::setUpCustomPhoneType();
    Fixture::setUpCustomField();
    \CRM_Core_Config::singleton()->defaultContactCountry = 1228;
  }

  public static function createMapper(\Civi\Osdi\ActionNetwork\RemoteSystem $system) {
    return new PersonN2F2022June($system);
  }

  public function makeBlankOsdiPerson(): \Civi\Osdi\ActionNetwork\Object\Person {
    return new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
  }

  /**
   * @return \Civi\Osdi\ActionNetwork\Object\Person
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  private function getCookieCutterOsdiPerson(): \Civi\Osdi\ActionNetwork\Object\Person {
    $person = $this->makeBlankOsdiPerson();
    $person->givenName->set('Cookie');
    $person->familyName->set('Cutter');
    $person->emailAddress->set('cookie@yum.net');
    $person->phoneNumber->set('12023334444');
    $person->postalStreet->set('202 N Main St');
    $person->postalLocality->set('Licking');
    $person->postalRegion->set('MO');
    $person->postalCode->set('65542');
    $person->postalCountry->set('US');
    $person->languageSpoken->set('es');
    return $person;
  }

  private function getCookieCutterCiviContact(): array {
    $createContact = Civi\Api4\Contact::create()->setValues(
      [
        'first_name' => 'Cookie',
        'last_name' => 'Cutter',
        'Individual.Languages_spoken' => ['spa'],
      ]
    )->addChain('email', \Civi\Api4\Email::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'email' => 'cookie@yum.net',
        ]
      )
    )->addChain('phone', \Civi\Api4\Phone::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'phone' => '12023334444',
          'phone_type_id:name' => 'SMS Permission - Mobile',
        ]
      )
    )->addChain('address', \Civi\Api4\Address::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'street_address' => '123 Test St',
          'city' => 'Licking',
          'state_province_id:name' => 'Missouri',
          'postal_code' => 65542,
          'country_id:name' => 'US',
        ]
      )
    )->execute();
    $cid = $createContact->single()['id'];
    return Civi\Api4\Contact::get(0)
      ->addWhere('id', '=', $cid)
      ->addJoin('Address')->addJoin('Email')->addJoin('Phone')
      ->addSelect('*', 'Individual.Languages_spoken', 'address.*', 'address.state_province_id:name', 'address.country_id:name', 'email.*', 'phone.*')
      ->execute()
      ->single();
  }

  /**
   *
   * LOCAL ===> REMOTE
   *
   */
  public function testMapLocalToNewRemote() {
    $civiContact = $this->getCookieCutterCiviContact();
    $this->assertEquals('Missouri', $civiContact['address.state_province_id:name']);
    $stateAbbreviation = 'MO';

    $result = self::$mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']));
    $this->assertEquals('Civi\Osdi\ActionNetwork\Object\Person', get_class($result));
    $this->assertEquals($civiContact['first_name'], $result->givenName->get());
    $this->assertEquals($civiContact['last_name'], $result->familyName->get());
    $this->assertEquals($civiContact['address.street_address'], $result->postalStreet->get());
    $this->assertEquals($civiContact['address.city'], $result->postalLocality->get());
    $this->assertEquals($stateAbbreviation, $result->postalRegion->get());
    $this->assertEquals($civiContact['address.postal_code'], $result->postalCode->get());
    $this->assertEquals($civiContact['address.country_id:name'], $result->postalCountry->get());
    $this->assertEquals($civiContact['email.email'], $result->emailAddress->get());
    $this->assertEquals(
      substr($civiContact['phone.phone_numeric'], -10),
      substr(preg_replace('/[^0-9]/', '', $result->phoneNumber->get()), -10));
    $this->assertEquals($civiContact['Individual.Languages_spoken'][0] == 'spa' ? 'es' : 'en', $result->languageSpoken->get());
  }

  public function testMapLocalToExistingRemote_ChangeName() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Contact::update(0)
      ->addWhere('id', '=', $civiContact['id'])
      ->setValues([
        'first_name' => 'DifferentFirst',
        'last_name' => 'DifferentLast',
      ])
      ->execute();

    $result = self::$mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('Civi\Osdi\ActionNetwork\Object\Person', get_class($result));
    $this->assertEquals('DifferentFirst', $result->givenName->get());
    $this->assertEquals('DifferentLast', $result->familyName->get());
    $this->assertEquals($civiContact['email.email'], $result->emailAddress->get());
  }

  public function testMapLocalToExistingRemote_ChangePhone() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    self::assertNotEquals('19098887777',
      $existingRemotePerson->phoneNumber->get());
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Phone::update(0)
      ->addWhere('id', '=', $civiContact['phone.id'])
      ->addValue('phone', '19098887777')
      ->execute();

    $result = self::$mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('Civi\Osdi\ActionNetwork\Object\Person', get_class($result));
    $this->assertEquals(
      '9098887777',
      substr(preg_replace('/[^0-9]/', '', $result->phoneNumber->get()), -10));
    $this->assertEquals($civiContact['first_name'], $result->givenName->get());
    $this->assertEquals($civiContact['last_name'], $result->familyName->get());
  }

  public function testMapLocalToRemote_DoNotEmail_EmailShouldBeUnsubscribed() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $civiContact['id'])
      ->addValue('do_not_email', TRUE)
      ->execute();

    $result = self::$mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('unsubscribed',
      $result->emailStatus->get());
  }

  public function testMapLocalToRemote_DoNotSms_RemoteHasPhone_PhoneShouldBeUnsubscribed() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $civiContact['id'])
      ->addValue('do_not_sms', TRUE)
      ->execute();

    $result = self::$mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('unsubscribed',
      $result->phoneStatus->get());
  }

  public function testMapLocalToRemote_DoNotSms_BlankPhoneShouldStayBlank() {
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $civiContact['id'])
      ->addValue('do_not_sms', TRUE)
      ->execute();

    $result = self::$mapper->mapLocalToRemote(new LocalPerson($civiContact['id']));
    $this->assertEmpty($result->phoneNumber->get());
  }

  public function testMapLocalToRemote_PhoneShouldBeUnsubscribed_NoSmsNumber() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Phone::delete(FALSE)
      ->addWhere('contact_id', '=', $civiContact['id'])
      ->addWhere('phone_type_id:name', '=', 'SMS Permission - Mobile')
      ->execute();

    $result = self::$mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('unsubscribed',
      $result->phoneStatus->get());
  }

  public function testMapLocalToRemote_OptOut_EmailAndPhoneShouldBeUnsubscribed() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $civiContact['id'])
      ->addValue('is_opt_out', TRUE)
      ->execute();

    $result = self::$mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('unsubscribed',
      $result->emailStatus->get());
    $this->assertEquals('unsubscribed',
      $result->phoneStatus->get());
  }

  public function testMapLocalToRemote_OnlyDoNotPhone_EmailAndPhoneShouldBeSubscribed() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $civiContact['id'])
      ->addValue('is_opt_out', FALSE)
      ->addValue('do_not_email', FALSE)
      ->addValue('do_not_sms', FALSE)
      ->addValue('do_not_phone', TRUE)
      ->execute();

    $result = self::$mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('subscribed',
      $result->emailStatus->get());
    $this->assertEquals('subscribed',
      $result->phoneStatus->get());
  }

  public function testMapLocalToRemote_ZIPWorkaround_StateWithoutCity() {
    $createContact = Civi\Api4\Contact::create()->setValues(
      ['first_name' => 'Bob']
    )->addChain('address', \Civi\Api4\Address::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'street_address' => NULL,
          'city' => NULL,
          'state_province_id:name' => 'Missouri',
          'postal_code' => NULL,
          'country_id:name' => 'US',
        ]
      )
    )->execute();
    $cid = $createContact->single()['id'];
    self::$createdEntities['Contact'][] = $cid;

    $result = self::$mapper->mapLocalToRemote(new LocalPerson($cid));
    $this->assertEmpty($result->postalLocality->get() ?? NULL);
    $this->assertEquals('MO', $result->postalRegion->get());
    $this->assertEquals('63464', $result->postalCode->get());
    $this->assertEquals('63464', $result->customFields->get()['Placeholder ZIP']);
  }

  public function testMapLocalToRemote_ZIPWorkaround_EnoughDetailToLookupRealZIP() {
    $createContact = Civi\Api4\Contact::create()->setValues(
      ['first_name' => 'Bob']
    )->addChain('address', \Civi\Api4\Address::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'street_address' => '103 Cherry St.',
          'city' => 'Ty Ty',
          'state_province_id:name' => 'Georgia',
          'postal_code' => NULL,
          'country_id:name' => 'US',
        ]
      )
    )->execute();
    $cid = $createContact->single()['id'];
    self::$createdEntities['Contact'][] = $cid;

    Fixture::setUpGeocoding();
    try {
      $result = self::$mapper->mapLocalToRemote(new LocalPerson($cid));
    }
    catch (\Throwable $e) {
      self::fail($e->getMessage());
    }
    finally {
      Fixture::disableGeocoding();
    }

    $this->assertEquals('Ty Ty', $result->postalLocality->get());
    $this->assertEquals('GA', $result->postalRegion->get());
    $this->assertEquals('31795', $result->postalCode->get());
    $this->assertEquals('no', $result->customFields->get()['Placeholder ZIP']);
  }

  public function testMapLocalToRemote_ZIPWorkaround_NotEnoughDetailToLookupRealZIP() {
    $createContact = Civi\Api4\Contact::create()->setValues(
      ['first_name' => 'Bob']
    )->addChain('address', \Civi\Api4\Address::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'street_address' => NULL,
          'city' => 'San Francisco',
          'state_province_id:name' => 'California',
          'postal_code' => NULL,
          'country_id:name' => 'US',
        ]
      )
    )->execute();
    $cid = $createContact->single()['id'];
    self::$createdEntities['Contact'][] = $cid;

    Fixture::setUpGeocoding();
    try {
      $result = self::$mapper->mapLocalToRemote(new LocalPerson($cid));
    }
    catch (\Throwable $e) {
      self::fail($e->getMessage());
    }
    finally {
      Fixture::disableGeocoding();
    }

    $this->assertEquals('San Francisco', $result->postalLocality->get());
    $this->assertEquals('CA', $result->postalRegion->get());
    $this->assertEquals('94102', $result->postalCode->get());
    $this->assertEquals('94102', $result->customFields->get()['Placeholder ZIP']);
  }

  /**
   *
   * REMOTE ===> LOCAL
   *
   */
  public function testRemoteToNewLocal() {
    $remotePerson = $this->getCookieCutterOsdiPerson();
    $this->assertEquals('MO', $remotePerson->postalRegion->get());
    $stateName = 'Missouri';

    $result = self::$mapper->mapRemoteToLocal($remotePerson);
    $this->assertEquals(LocalPerson::class, get_class($result));
    $cid = $result->save()->getId();
    $resultContact = Civi\Api4\Contact::get(0)
      ->addWhere('id', '=', $cid)
      ->addJoin('Address')->addJoin('Email')->addJoin('Phone')
      ->addSelect('*', 'custom.*', 'address.*', 'address.state_province_id:name', 'address.country_id:name', 'email.*', 'phone.*')
      ->execute()
      ->single();
    $this->assertEquals($remotePerson->givenName->get(), $resultContact['first_name']);
    $this->assertEquals($remotePerson->familyName->get(), $resultContact['last_name']);
    $this->assertEquals($remotePerson->postalStreet->get(), $resultContact['address.street_address']);
    $this->assertEquals($remotePerson->postalLocality->get(), $resultContact['address.city']);
    $this->assertEquals($stateName, $resultContact['address.state_province_id:name']);
    $this->assertEquals($remotePerson->postalCode->get(), $resultContact['address.postal_code']);
    $this->assertEquals($remotePerson->postalCountry->get(), $resultContact['address.country_id:name']);
    $this->assertEquals($remotePerson->emailAddress->get(), $resultContact['email.email']);
    $this->assertEquals($remotePerson->phoneNumber->get(), $resultContact['phone.phone_numeric']);
    self::assertNotEmpty($resultContact['Individual.Languages_spoken'][0] ?? NULL);
    $lang = $resultContact['Individual.Languages_spoken'][0] == 'eng' ? 'en' : 'es';
    $this->assertEquals($remotePerson->languageSpoken->get(), $lang);
  }

  public function testMapRemoteToExistingLocal_BlankFieldsAreCopiedAsBlanks() {
    $existingLocalContactId = $this->getCookieCutterCiviContact()['id'];
    $existingLocalPersonLoaded = (new LocalPerson($existingLocalContactId))->load();
    self::assertNotEmpty($firstName = $existingLocalPersonLoaded->firstName->get());
    self::assertNotEmpty($existingLocalPersonLoaded->lastName->get());

    $remotePerson = $this->getCookieCutterOsdiPerson();
    $remotePerson->familyName->set(NULL);
    $remotePerson->postalLocality->set(NULL);

    self::assertEmpty($remotePerson->familyName->get());
    self::assertEmpty($remotePerson->postalLocality->get() ?? NULL);

    $mappedLocalPerson = self::$mapper->mapRemoteToLocal(
      $remotePerson,
      new LocalPerson($existingLocalContactId)
    );

    self::assertEquals($firstName, $mappedLocalPerson->firstName->get());
    self::assertEmpty($mappedLocalPerson->lastName->get());
    self::assertEmpty($mappedLocalPerson->addressCity->get());
  }

  public function testMapRemoteToExistingLocal_ChangeName() {
    $existingLocalContactId = $this->getCookieCutterCiviContact()['id'];
    $remotePerson = $this->getCookieCutterOsdiPerson();
    $remotePerson->givenName->set('DifferentFirst');
    $remotePerson->familyName->set('DifferentLast');

    $mappedLocalPerson = self::$mapper->mapRemoteToLocal(
      $remotePerson,
      new LocalPerson($existingLocalContactId)
    );
    $this->assertEquals('DifferentFirst', $mappedLocalPerson->firstName->get());
    $this->assertEquals('DifferentLast', $mappedLocalPerson->lastName->get());
    $this->assertEquals(
      $remotePerson->emailAddress->get(),
      $mappedLocalPerson->emailEmail->get()
    );
    $this->assertEquals(
      $remotePerson->postalLocality->get(),
      $mappedLocalPerson->addressCity->get()
    );
  }

  public function testMapRemoteToExistingLocal_ChangePhone() {
    $existingLocalContactId = $this->getCookieCutterCiviContact()['id'];
    $remotePerson = $this->getCookieCutterOsdiPerson();

    self::assertNotEquals('19098887777', $remotePerson->phoneNumber->get());

    $remotePerson->phoneNumber->set('19098887777');
    $remotePerson->phoneStatus->set('subscribed');

    $result = self::$mapper->mapRemoteToLocal(
      $remotePerson,
      new LocalPerson($existingLocalContactId)
    );
    $this->assertEquals('19098887777', $result->smsPhonePhone->get());
    $this->assertEquals($remotePerson->givenName->get(), $result->firstName->get());
    $this->assertEquals($remotePerson->familyName->get(), $result->lastName->get());
  }

  public function testMapRemoteToExistingLocal_UnsubscribedPhone_SMSPhoneIsDeleted() {
    $existingLocalPerson = (new LocalPerson(
      $this->getCookieCutterCiviContact()['id']))->load();
    $remotePerson = $this->getCookieCutterOsdiPerson();

    self::assertNotEmpty($existingLocalPerson->smsPhoneId->get());
    self::assertEmpty($existingLocalPerson->nonSmsMobilePhoneId->get());

    $remotePerson->phoneStatus->set('unsubscribed');

    $result = self::$mapper->mapRemoteToLocal(
      $remotePerson,
      $existingLocalPerson
    );

    $result->save();
    $reloadedLocalPerson = (new LocalPerson($result->getId()))->load();

    self::assertEmpty($reloadedLocalPerson->smsPhoneId->get());
    self::assertEquals($remotePerson->phoneNumber->get(),
      $reloadedLocalPerson->nonSmsMobilePhonePhone->get());
  }

  public function testMapRemoteToLocal_DummyZipAndCityAreExcluded() {
    $unsavedNewPerson = $this->makeBlankOsdiPerson();
    $unsavedNewPerson->emailAddress->set('dummy@zipcode.net');
    $unsavedNewPerson->postalCode->set('54643');
    $unsavedNewPerson->postalCountry->set('US');
    $unsavedNewPerson->customFields->set(['Placeholder ZIP' => '54643']);
    $remotePerson = $unsavedNewPerson->save();

    $result = self::$mapper->mapRemoteToLocal($remotePerson)->save();
    self::$createdEntities['Contact'][] = $result->getId();

    self::assertEquals('WI', $result->addressStateProvinceIdAbbreviation->get());
    self::assertEmpty($result->addressPostalCode->get());
    self::assertEmpty($result->addressCity->get());
  }

}
