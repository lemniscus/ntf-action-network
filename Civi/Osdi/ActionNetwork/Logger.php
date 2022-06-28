<?php

namespace Civi\Osdi\ActionNetwork;

class Logger {

  public static function logError(?string $message, $context = NULL) {
    $priority = self::mapPriority(\Psr\Log\LogLevel::ERROR);

    if (!empty($context)) {
      if (isset($context['exception'])) {
        $context['exception'] = \CRM_Core_Error::formatTextException($context['exception']);
      }
      $message .= "\n" . print_r($context, 1);
    }
    \CRM_Core_Error::debug_log_message($message, FALSE, 'osdi', $priority);
  }

  public static function logDebug(?string $message) {
    $priority = self::mapPriority(\Psr\Log\LogLevel::DEBUG);
    \CRM_Core_Error::debug_log_message($message, FALSE, 'osdi', $priority);
  }

  private static function mapPriority(string $psrLogLevel) {
    static $levelMap;

    if (!isset($levelMap)) {
      $levelMap = \Civi::log()->getMap();
    }

    $priority = $levelMap[$psrLogLevel];
    return $priority;
  }

}