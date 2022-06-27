<?php
return [
  'ntfActionNetwork.syncJobProcessId' => [
    'name'        => 'ntfActionNetwork.syncJobProcessId',
    'title'       => ts('Action Network sync PID'),
    'description' => 'Process ID of the last Action Network sync job',
    'type'        => 'Integer',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'ntfActionNetwork.syncJobActNetModTimeCutoff' => [
    'name'        => 'ntfActionNetwork.syncJobActNetModTimeCutoff',
    'title'       => ts('Action Network sync AN mod time cutoff'),
    'description' => 'Lower limit for Action Network modification datetimes in '
    . 'the last sync job, formatted like 2021-03-03T18:15:57Z',
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'ntfActionNetwork.syncJobStartTime' => [
    'name'        => 'ntfActionNetwork.syncJobStartTime',
    'title'       => ts('Action Network sync start time'),
    'description' => ts('Start time of last Action Network sync job, as Unix timestamp'),
    'type'        => 'Integer',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'ntfActionNetwork.syncJobEndTime' => [
    'name'        => 'ntfActionNetwork.syncJobEndTime',
    'title'       => ts('Action Network sync end time'),
    'description' => ts('End time of last Action Network sync job, as Unix timestamp'),
    'type'        => 'Integer',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
];
