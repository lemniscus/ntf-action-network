<?php

namespace Civi\Osdi\ActionNetwork;

class Logger {

  public static function logError(?string $message, $context = NULL) {
    static $levelMap;

    if (!isset($levelMap)) {
      $levelMap = \Civi::log()->getMap();
    }

    if (!empty($context)) {
      if (isset($context['exception'])) {
        $context['exception'] = \CRM_Core_Error::formatTextException($context['exception']);
      }
      $message .= "\n" . print_r($context, 1);
    }
    \CRM_Core_Error::debug_log_message($message, FALSE, 'osdi', $levelMap[\Psr\Log\LogLevel::ERROR]);
  }

}