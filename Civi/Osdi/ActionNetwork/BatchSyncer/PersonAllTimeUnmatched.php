<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

class PersonAllTimeUnmatched extends PersonBasic {

  /**
   * We aren't changing the cutoff time in a meaningful way, so just return
   * the same cutoff that we received.
   */
  protected function findAndSyncLocalUpdatesAsNeeded($cutoff): array {
    [$mostRecentPreSyncModTime, $count] =
      parent::findAndSyncLocalUpdatesAsNeeded($cutoff);

    return [$cutoff, $count];
  }

  /**
   * Find contacts from all time (ignore cutoff time) who don't have sync states.
   */
  protected function getCandidateLocalContacts($cutoff): \Civi\Api4\Generic\Result {
    $civiContacts = \Civi\Api4\Email::get(FALSE)
      ->addSelect(
        'contact_id',
        'contact.modified_date',
        'sync_state.local_pre_sync_modified_time',
        'sync_state.local_post_sync_modified_time')
      ->addJoin('Contact AS contact', 'INNER')
      ->addJoin(
        'OsdiPersonSyncState AS sync_state',
        'EXCLUDE',
        ['contact_id', '=', 'sync_state.contact_id'])
      ->addGroupBy('email')
      ->addOrderBy('contact.modified_date')
      ->addWhere('is_primary', '=', TRUE)
      ->addWhere('contact.is_deleted', '=', FALSE)
      ->addWhere('contact.is_opt_out', '=', FALSE)
      ->addWhere('contact.contact_type', '=', 'Individual')
      ->execute();
    return $civiContacts;
  }

}
