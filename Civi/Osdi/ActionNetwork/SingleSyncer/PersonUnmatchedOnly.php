<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\FetchOldOrFindNewMatch;
use Civi\Osdi\Result\SyncEligibility;

class PersonUnmatchedOnly extends PersonN2F {

  /**
   * Only unmatched people are eligible for sync.
   */
  protected function getSyncEligibility(LocalRemotePair $pair): SyncEligibility {
    $matchResult = $pair->getLastResultOfType(FetchOldOrFindNewMatch::class);
    if ($matchResult && $matchResult->isStatus($matchResult::NO_MATCH_FOUND)) {
      return parent::getSyncEligibility($pair);
    }
    else {
      $eligibility = (new SyncEligibility())
        ->setMessage('has a match')
        ->setStatusCode(SyncEligibility::INELIGIBLE);
      $pair->pushResult($eligibility);
      return $eligibility;
    }
  }

}
