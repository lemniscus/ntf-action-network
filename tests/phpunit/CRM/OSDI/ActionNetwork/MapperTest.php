<?php

use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_NtF2022MayMapperTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  /**
   * @var array{Contact: array, OptionGroup: array, OptionValue: array, CustomGroup: array, CustomField: array}
   */
  private static $createdEntities = [];

  /**
   * @var \Civi\Osdi\ActionNetwork\RemoteSystem
   */
  private $system;

  /**
   * @var \Civi\Osdi\ActionNetwork\Mapper\NineToFive2022May
   */
  private $mapper;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('osdi-client')
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    self::setUpCustomConfig();
  }

  public function setUp(): void {
    $this->system = $this->createRemoteSystem();
    $this->mapper = $this->createMapper($this->system);
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    $reset = $this->getCookieCutterOsdiPerson();
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
    self::setUpCustomPhoneType();
    self::setUpCustomField();
    \CRM_Core_Config::singleton()->defaultContactCountry = 1228;
  }

  protected static function setUpCustomPhoneType(): void {
    $phoneTypeExists = \Civi\Api4\OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', 'phone_type')
      ->addWhere('name', '=', 'SMS Permission - Mobile')
      ->selectRowCount()->execute()->count();

    if (!$phoneTypeExists) {
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
      self::$createdEntities['OptionValue'][] = $create['id'];
    }
  }

  protected static function setUpGeocoding(): void {
    $googleAPIToken = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'GoogleAPIToken');
    \Civi\Api4\Setting::set()
      ->addValue('geoProvider', 'Google')
      ->addValue('geoAPIKey', $googleAPIToken)
      ->addValue('mapProvider', 'Google')
      ->addValue('mapAPIKey', $googleAPIToken)
      ->execute();
    CRM_Utils_GeocodeProvider::reset();
  }

  protected static function disableGeocoding(): void {
    \Civi\Api4\Setting::revert()
      ->addSelect('geoProvider', 'mapProvider', 'geoAPIKey', 'mapAPIKey')
      ->execute();
    CRM_Utils_GeocodeProvider::reset();
  }

  protected static function setUpCustomField(): void {
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

  public function createRemoteSystem(): \Civi\Osdi\ActionNetwork\RemoteSystem {
    $osdiClientExtDir = dirname(\CRM_Extension_System::singleton()
      ->getMapper()
      ->keyToPath('osdi-client'));
    require_once "$osdiClientExtDir/tests/phpunit/CRM/OSDI/HttpClient.php";
    require_once "$osdiClientExtDir/tests/phpunit/CRM/OSDI/FixtureHttpClient.php";
    $systemProfile = new CRM_OSDI_BAO_SyncProfile();
    $systemProfile->entry_point = 'https://actionnetwork.org/api/v2/';
    $systemProfile->api_token = file_get_contents(
      $osdiClientExtDir
      . '/tests/phpunit/CRM/OSDI/ActionNetwork/apiToken');
    //    $client = new Jsor\HalClient\HalClient(
    //        'https://actionnetwork.org/api/v2/',
    //         new CRM_OSDI_FixtureHttpClient()
    //    );
    $client = new Jsor\HalClient\HalClient(
      'https://actionnetwork.org/api/v2/' //,
      //CRM_OSDI_HttpClient::client(),
    );
    return new Civi\Osdi\ActionNetwork\RemoteSystem($systemProfile, $client);
  }

  private function createMapper(\Civi\Osdi\ActionNetwork\RemoteSystem $system) {
    return new Civi\Osdi\ActionNetwork\Mapper\NineToFive2022May($system);
  }

  public function makeBlankOsdiPerson(): \Civi\Osdi\ActionNetwork\Object\Person {
    return new \Civi\Osdi\ActionNetwork\Object\Person($this->system);
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
    return $person->save();
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

    $result = $this->mapper->mapLocalToRemote(
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
    $this->assertEquals($civiContact['phone.phone_numeric'], $result->phoneNumber->get());
    $this->assertEquals($civiContact['Individual.Languages_spoken'][0] == 'spa' ? 'es' : 'en', $result->languageSpoken->get());
  }

  public function testMapLocalToExistingRemote_ChangeName() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Contact::update(0)
      ->addWhere('id', '=', $civiContact['id'])
      ->setValues(['first_name' => 'DifferentFirst', 'last_name' => 'DifferentLast'])
      ->execute();

    $result = $this->mapper->mapLocalToRemote(
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

    $result = $this->mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('Civi\Osdi\ActionNetwork\Object\Person', get_class($result));
    $this->assertEquals('19098887777', $result->phoneNumber->get());
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

    $result = $this->mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('unsubscribed',
      $result->emailStatus->get());
  }

  public function testMapLocalToRemote_DoNotSms_PhoneShouldBeUnsubscribed() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $civiContact['id'])
      ->addValue('do_not_sms', TRUE)
      ->execute();

    $result = $this->mapper->mapLocalToRemote(
      new LocalPerson($civiContact['id']),
      $existingRemotePerson
    );
    $this->assertEquals('unsubscribed',
      $result->phoneStatus->get());
  }

  public function testMapLocalToRemote_PhoneShouldBeUnsubscribed_NoSmsNumber() {
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $civiContact = $this->getCookieCutterCiviContact();
    Civi\Api4\Phone::delete(FALSE)
      ->addWhere('contact_id', '=', $civiContact['id'])
      ->addWhere('phone_type_id:name', '=', 'SMS Permission - Mobile')
      ->execute();

    $result = $this->mapper->mapLocalToRemote(
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

    $result = $this->mapper->mapLocalToRemote(
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

    $result = $this->mapper->mapLocalToRemote(
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

    $result = $this->mapper->mapLocalToRemote(new LocalPerson($cid));
    $this->assertEmpty($result->postalLocality->get() ?? NULL);
    $this->assertEquals('MO', $result->postalRegion->get());
    $this->assertEquals('63464', $result->postalCode->get());
    $this->assertEquals('63464', $result->customFields->get()['Dummy ZIP']);
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

    self::setUpGeocoding();
    try {
      $result = $this->mapper->mapLocalToRemote(new LocalPerson($cid));
    }
    catch (Throwable $e) {
      self::fail($e->getMessage());
    }
    finally {
      self::disableGeocoding();
    }

    $this->assertEquals('Ty Ty', $result->postalLocality->get());
    $this->assertEquals('GA', $result->postalRegion->get());
    $this->assertEquals('31795', $result->postalCode->get());
    $this->assertEquals('no', $result->customFields->get()['Dummy ZIP']);
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

    self::setUpGeocoding();
    try {
      $result = $this->mapper->mapLocalToRemote(new LocalPerson($cid));
    }
    catch (Throwable $e) {
      self::fail($e->getMessage());
    }
    finally {
      self::disableGeocoding();
    }

    $this->assertEquals('San Francisco', $result->postalLocality->get());
    $this->assertEquals('CA', $result->postalRegion->get());
    $this->assertEquals('94102', $result->postalCode->get());
    $this->assertEquals('94102', $result->customFields->get()['Dummy ZIP']);
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

    $result = $this->mapper->mapRemoteToLocal($remotePerson);
    $this->assertEquals(\Civi\Osdi\LocalObject\Person\N2F::class, get_class($result));
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

    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $existingRemotePerson->familyName->set(NULL);
    $existingRemotePerson->postalLocality->set(NULL);
    $sparserRemotePerson = $existingRemotePerson->save();

    self::assertEmpty($sparserRemotePerson->familyName->get());
    self::assertEmpty($sparserRemotePerson->postalLocality->get() ?? NULL);

    $mappedLocalPerson = $this->mapper->mapRemoteToLocal(
      $sparserRemotePerson,
      new LocalPerson($existingLocalContactId)
    );

    self::assertEquals($firstName, $mappedLocalPerson->firstName->get());
    self::assertEmpty($mappedLocalPerson->lastName->get());
    self::assertEmpty($mappedLocalPerson->addressCity->get());
  }

  public function testMapRemoteToExistingLocal_ChangeName() {
    $existingLocalContactId = $this->getCookieCutterCiviContact()['id'];
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();
    $existingRemotePerson->givenName->set('DifferentFirst');
    $existingRemotePerson->familyName->set('DifferentLast');
    $alteredRemotePerson = $existingRemotePerson->save();

    $mappedLocalPerson = $this->mapper->mapRemoteToLocal(
      $alteredRemotePerson,
      new LocalPerson($existingLocalContactId)
    );
    $this->assertEquals('DifferentFirst', $mappedLocalPerson->firstName->get());
    $this->assertEquals('DifferentLast', $mappedLocalPerson->lastName->get());
    $this->assertEquals(
      $existingRemotePerson->emailAddress->get(),
      $mappedLocalPerson->emailEmail->get()
    );
    $this->assertEquals(
      $existingRemotePerson->postalLocality->get(),
      $mappedLocalPerson->addressCity->get()
    );
  }

  public function testMapRemoteToExistingLocal_ChangePhone() {
    $existingLocalContactId = $this->getCookieCutterCiviContact()['id'];
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();

    self::assertNotEquals('19098887777', $existingRemotePerson->phoneNumber->get());

    $existingRemotePerson->phoneNumber->set('19098887777');
    $existingRemotePerson->phoneStatus->set('subscribed');
    $alteredRemotePerson = $existingRemotePerson->save();

    $result = $this->mapper->mapRemoteToLocal(
      $alteredRemotePerson,
      new LocalPerson($existingLocalContactId)
    );
    $this->assertEquals('19098887777', $result->smsPhonePhone->get());
    $this->assertEquals($existingRemotePerson->givenName->get(), $result->firstName->get());
    $this->assertEquals($existingRemotePerson->familyName->get(), $result->lastName->get());
  }

  public function testMapRemoteToExistingLocal_UnsubscribedPhone_SMSPhoneIsDeleted() {
    $existingLocalPerson = (new LocalPerson(
      $this->getCookieCutterCiviContact()['id']))->load();
    $existingRemotePerson = $this->getCookieCutterOsdiPerson();

    self::assertNotEmpty($existingLocalPerson->smsPhoneId->get());
    self::assertEmpty($existingLocalPerson->nonSmsMobilePhoneId->get());

    $existingRemotePerson->phoneStatus->set('unsubscribed');
    $alteredRemotePerson = $existingRemotePerson->save();

    $result = $this->mapper->mapRemoteToLocal(
      $alteredRemotePerson,
      $existingLocalPerson
    );

    $result->save();
    $reloadedLocalPerson = (new LocalPerson($result->getId()))->load();

    self::assertEmpty($reloadedLocalPerson->smsPhoneId->get());
    self::assertEquals($alteredRemotePerson->phoneNumber->get(),
      $reloadedLocalPerson->nonSmsMobilePhonePhone->get());
  }

  public function testMapRemoteToLocal_DummyZipAndCityAreExcluded() {
    $unsavedNewPerson = $this->makeBlankOsdiPerson();
    $unsavedNewPerson->emailAddress->set('dummy@zipcode.net');
    $unsavedNewPerson->postalCode->set('54643');
    $unsavedNewPerson->postalCountry->set('US');
    $unsavedNewPerson->customFields->set(['Dummy ZIP' => '54643']);
    $remotePerson = $unsavedNewPerson->save();

    $result = $this->mapper->mapRemoteToLocal($remotePerson)->save();
    self::$createdEntities['Contact'][] = $result->getId();

    self::assertEquals('WI', $result->addressStateProvinceIdAbbreviation->get());
    self::assertEmpty($result->addressPostalCode->get());
    self::assertEmpty($result->addressCity->get());
  }

}
