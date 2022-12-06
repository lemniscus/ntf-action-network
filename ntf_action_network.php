<?php

require_once 'ntf_action_network.civix.php';
// phpcs:disable
use CRM_NtfActionNetwork_ExtensionUtil as E;
// phpcs:enable

function ntf_action_network_civicrm_geocoderFormat($geoProvider, &$values, $xml) {
  if ($geoProvider !== 'Google') {
    return;
  }

  if ('OK' !== (string) $xml->status) {
    /** @var \SimpleXMLElement $xml */
    $msg = $xml->error_message ?: '[no message]';
    $context = ['address' => $values, 'response' => $xml->asXML()];
    Civi::log()->error("From Google: $msg", $context);
    //throw new Exception("From Google: $msg");
    return;
  }

  foreach ($xml->result->address_component as $component) {
    $type = (string) $component->type[0];
    if ($type === 'postal_code') {
      $values['zip'] = (string) $component->short_name;
      return;
    }
  }
}

function ntf_action_network_civicrm_check(&$messages, $statusNames, $includeDisabled) {
  if ($statusNames && !in_array('ntfActionNetworkZombieJob', $statusNames)) {
    return;
  }

  if (!$includeDisabled) {
    $disabled = \Civi\Api4\StatusPreference::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('is_active', '=', FALSE)
      ->addWhere('domain_id', '=', 'current_domain')
      ->addWhere('name', '=', 'ntfActionNetworkZombieJob')
      ->execute()->count();
    if ($disabled) {
      return;
    }
  }

  $jobStartTime = Civi::settings()->get('ntfActionNetwork.syncJobStartTime');
  $jobProcessId = Civi::settings()->get('ntfActionNetwork.syncJobProcessId');
  if (empty($jobStartTime) || empty(($jobProcessId))) {
    return;
  }

  if (posix_getsid($jobProcessId) === FALSE) {
    return;
  }

  if (time() - $jobStartTime > 3600) {
    $messages[] = new CRM_Utils_Check_Message(
      'ntfActionNetworkZombieJob',
      ts('An Action Network sync job has been running for over an hour. '
        . 'This prevents new sync jobs from running. Process ID %1 began %2.',
        [1 => $jobProcessId, 2 => date(DATE_COOKIE, $jobStartTime)]),
      ts('Long-Running Action Network Sync'),
      \Psr\Log\LogLevel::WARNING,
      'fa-hourglass'
    );
  }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function ntf_action_network_civicrm_config(&$config) {
  _ntf_action_network_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function ntf_action_network_civicrm_xmlMenu(&$files) {
  _ntf_action_network_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function ntf_action_network_civicrm_install() {
  _ntf_action_network_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function ntf_action_network_civicrm_postInstall() {
  _ntf_action_network_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function ntf_action_network_civicrm_uninstall() {
  _ntf_action_network_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function ntf_action_network_civicrm_enable() {
  _ntf_action_network_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function ntf_action_network_civicrm_disable() {
  _ntf_action_network_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function ntf_action_network_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _ntf_action_network_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function ntf_action_network_civicrm_managed(&$entities) {
  _ntf_action_network_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function ntf_action_network_civicrm_caseTypes(&$caseTypes) {
  _ntf_action_network_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function ntf_action_network_civicrm_angularModules(&$angularModules) {
  _ntf_action_network_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function ntf_action_network_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _ntf_action_network_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function ntf_action_network_civicrm_entityTypes(&$entityTypes) {
  _ntf_action_network_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_themes().
 */
function ntf_action_network_civicrm_themes(&$themes) {
  _ntf_action_network_civix_civicrm_themes($themes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function ntf_action_network_civicrm_preProcess($formName, &$form) {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
//function ntf_action_network_civicrm_navigationMenu(&$menu) {
//  _ntf_action_network_civix_insert_navigation_menu($menu, 'Mailings', array(
//    'label' => E::ts('New subliminal message'),
//    'name' => 'mailing_subliminal_message',
//    'url' => 'civicrm/mailing/subliminal',
//    'permission' => 'access CiviMail',
//    'operator' => 'OR',
//    'separator' => 0,
//  ));
//  _ntf_action_network_civix_navigationMenu($menu);
//}
