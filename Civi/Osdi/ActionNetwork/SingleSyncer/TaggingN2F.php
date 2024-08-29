<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;

class TaggingN2F extends TaggingBasic {

  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult {
    $name = $pair->getOriginObject()->getTagUsingCache()->name->get();
    foreach (['Comms_', 'Campaign_'] as $allowedPrefix) {
      if (str_starts_with($name, $allowedPrefix)) {
        return parent::oneWayMapAndWrite($pair);
      }
    }

    $result = new MapAndWriteResult();
    $result->setStatusCode($result::ERROR);
    $result->setMessage("'$name' is not one of the tags we sync");
    $pair->getResultStack()->push($result);
    return $result;
  }

}
