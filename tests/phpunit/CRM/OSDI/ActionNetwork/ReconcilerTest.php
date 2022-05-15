<?php

use Civi\Osdi\LocalObject\Person\N2F as LocalPerson;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use \League\Csv\Reader;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_ReconcilerTest extends \PHPUnit\Framework\TestCase implements
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
  public static $system;

  /**
   * @var \Civi\Osdi\ActionNetwork\Mapper\Reconciliation2022May001
   */
  public static $mapper;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('osdi-client')
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass(): void {
    $osdiClientExtDir = dirname(CRM_Extension_System::singleton()
      ->getMapper()->keyToPath('osdi-client'));
    require_once "$osdiClientExtDir/tests/phpunit/CRM/OSDI/ActionNetwork/TestUtils.php";

    self::setUpCustomConfig();
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    self::$mapper = self::createMapper(self::$system);

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
    CRM_OSDI_ActionNetwork_Fixture::setUpCustomPhoneType();
    CRM_OSDI_ActionNetwork_Fixture::setUpCustomField();
    \CRM_Core_Config::singleton()->defaultContactCountry = 1228;
  }

  public static function createMapper(\Civi\Osdi\ActionNetwork\RemoteSystem $system) {
    return new Civi\Osdi\ActionNetwork\Mapper\NineToFive2022May($system);
  }

  public function testReconcile() {
    $augustusLocal = new LocalPerson();
    $augustusLocal->firstName->set('Augustus');
    $augustusLocal->lastName->set('Wright');
    $augustusLocal->individualLanguagesSpoken->set(NULL);
    $augustusLocal->emailEmail->set('auwt7654@gmail.com');
    $augustusLocal->addressStreetAddress->set(NULL);
    $augustusLocal->addressCity->set('Albany');
    $augustusLocal->addressStateProvinceId->set('1009');
    $augustusLocal->addressPostalCode->set(NULL);
    $augustusLocal->addressCountryId->set('1228');
    $augustusLocal->save();

    $cesarLocal = new LocalPerson();
    $cesarLocal->firstName->set('Cesar');
    $cesarLocal->lastName->set('Chavez');
    $cesarLocal->individualLanguagesSpoken->set(NULL);
    $cesarLocal->emailEmail->set('si.se@pue.de');
    $cesarLocal->nonSmsMobilePhoneIsPrimary->set(TRUE);
    $cesarLocal->nonSmsMobilePhonePhone->set('(970) 555-1212');
    $cesarLocal->smsPhoneIsPrimary->set(NULL);
    $cesarLocal->smsPhonePhone->set(NULL);
    $cesarLocal->save();

    $douglasLocal = new LocalPerson();
    $douglasLocal->firstName->set('Douglas');
    $douglasLocal->lastName->set('Fairbanks');
    $douglasLocal->emailEmail->set('douglas.fairbanks@ppcc.edu');
    $douglasLocal->addressStreetAddress->set('1605 1/2 N Nevada');
    $douglasLocal->addressCity->set('Colorado Springs');
    $douglasLocal->addressStateProvinceId->set('1005');
    $douglasLocal->addressPostalCode->set('80907');
    $douglasLocal->addressCountryId->set('1228');
    $douglasLocal->save();

    $keikoLocal = new LocalPerson();
    $keikoLocal->firstName->set('Keiko');
    $keikoLocal->lastName->set('Kurasawa');
    $keikoLocal->emailEmail->set('kkurasawa@gmail.com');
    $keikoLocal->save();

    $oonaLocal = new LocalPerson();
    $oonaLocal->firstName->set('Oona');
    $oonaLocal->lastName->set('Gee');
    $oonaLocal->emailEmail->set('oona@gee.net');
    $oonaLocal->addressStreetAddress->set('89 Freeport Dr');
    $oonaLocal->addressCity->set('Englewood');
    $oonaLocal->addressStateProvinceId->set('1005');
    $oonaLocal->addressPostalCode->set('80112');
    $oonaLocal->save();

    $ritaLocal = new LocalPerson();
    $ritaLocal->firstName->set('Rita');
    $ritaLocal->lastName->set(NULL);
    $ritaLocal->isOptOut->set(NULL);
    $ritaLocal->doNotEmail->set(NULL);
    $ritaLocal->doNotSms->set(NULL);
    $ritaLocal->individualLanguagesSpoken->set(NULL);
    $ritaLocal->emailEmail->set('ritamoreno4278@hotmail.com');
    $ritaLocal->smsPhoneIsPrimary->set(TRUE);
    $ritaLocal->smsPhonePhone->set('(312) 555-1212');
    $ritaLocal->addressStreetAddress->set(NULL);
    $ritaLocal->addressCity->set('Macon');
    $ritaLocal->addressStateProvinceId->set('1009');
    $ritaLocal->addressPostalCode->set('');
    $ritaLocal->addressCountryId->set('1228');
    $ritaLocal->save();

    $xavierLocal = new LocalPerson();
    $xavierLocal->firstName->set('Xavier');
    $xavierLocal->lastName->set('Zapata');
    $xavierLocal->doNotEmail->set(TRUE);
    $xavierLocal->individualLanguagesSpoken->set(['eng', 'spa']);
    $xavierLocal->emailEmail->set('xyz@zoro.net');
    $xavierLocal->addressStateProvinceId->set('1025');
    $xavierLocal->save();

    \CRM_OSDI_ActionNetwork_Fixture::setUpGeocoding();
    $main = new \Civi\Osdi\ActionNetwork\N2FReconciliationRunner();
    $main->setInput(__DIR__ . '/reconciliationTestCSVInput.csv');
    $outputFilePath = __DIR__ . '/reconciliationTestOutput.csv';
    $main->setOutput($outputFilePath);
    $main->run();

    $expectedCsv = Reader::createFromPath(__DIR__ . '/reconciliationTestExpectedOutput.csv');
    $expectedCsv->setHeaderOffset(0);
    $expectedRecords = $expectedCsv->getRecords();

    $actualCsv = Reader::createFromPath($outputFilePath);
    $actualCsv->setHeaderOffset(0);
    foreach ($actualCsv as $i => $row) {
      $actualRecords[$i] = $row;
    }

    foreach ($expectedRecords as $i => $expectedRow) {
      foreach ($expectedRow as $fieldName => $expectedValue) {
        $actualValue = $actualRecords[$i][$fieldName];

        if (empty($expectedValue)) {
          self::assertEmpty(
            $actualValue,
            "Row " . ($i + 1) . ": $fieldName: actual value '$actualValue'"
          );
        }

        elseif ('--' === $expectedValue) {
          self::assertNotEmpty(
            $actualValue,
            "Row " . ($i + 1) . ": $fieldName: expected some value"
          );
        }

        else {
          $firstChar = substr($expectedValue, 0, 1);
          if ('[' === $firstChar || '{' === $firstChar) {
            $expectedValue = json_decode($expectedValue, TRUE);
            $actualValue = json_decode($actualValue, TRUE);
          }
          self::assertEquals(
            $expectedValue,
            $actualValue,
            "Row " . ($i + 1) . ": $fieldName:"
          );
        }
      }
    }
  }

}
