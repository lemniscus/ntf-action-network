<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;

class TaggingN2F extends TaggingBasic {

  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult {
    $name = $pair->getOriginObject()->getTag()->loadOnce()->name->get();
    foreach (['Comms:', 'Campaign:'] as $allowedPrefix) {
      if (strncmp($name, $allowedPrefix, strlen($allowedPrefix)) === 0) {
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
