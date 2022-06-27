<?php

use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

/**
 * Contact.Actionnetworkbatchsync API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
//function _civicrm_api3_contact_Actionnetworkbatchsync_spec(&$spec) {
//  $spec['magicword']['api.required'] = 1;
//}

/**
 * Contact.Actionnetworkbatchsync API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_contact_Actionnetworkbatchsync(array $params): array {
  if (!defined('N2F_ACTION_NETWORK_API_TOKEN')) {
    throw new Exception('Cannot sync with Action Network without an API token');
  }

  $systemProfile = new CRM_OSDI_BAO_SyncProfile();
  $systemProfile->entry_point = 'https://actionnetwork.org/api/v2/';
  $systemProfile->api_token = N2F_ACTION_NETWORK_API_TOKEN;

  $httpClient = new Guzzle6HttpClient(new \GuzzleHttp\Client(['timeout' => 27]));
  $client = new Jsor\HalClient\HalClient('https://actionnetwork.org/api/v2/', $httpClient);

  $system = new Civi\Osdi\ActionNetwork\RemoteSystem($systemProfile, $client);

  $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person\N2F($system);
  $syncer->setMatcher(
    new \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail(
      $syncer, \Civi\Osdi\LocalObject\Person\N2F::class));
  $syncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\NineToFive2022June($system));

  return civicrm_api3_create_success(
    $syncer->batchSyncFromRemote(),
    $params,
    'Contact',
    'Actionnetworkbatchsync');
}
