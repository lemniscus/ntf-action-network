<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Api4\Tag;

class TaggingN2F extends TaggingBasic {

  protected function getEligibleLocalTagIds(): array {
    return Tag::get(FALSE)
      ->addSelect('id')
      ->addClause('OR',
        ['name', 'LIKE', 'Comms_%'],
        ['name', 'LIKE', 'Campaign_%'])
      ->execute()
      ->column('id');
  }

}
