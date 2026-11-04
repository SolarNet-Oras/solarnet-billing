<?php

return [
    // Two independent switches prevent a database setting from activating the
    // scheduler before the deployment environment has been approved.
    'automation_enabled' => filter_var(env('COLLECTION_PLANNER_AUTOMATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'algorithm_version' => 'phase1-v1',
    'timezone' => 'Asia/Manila',
    'daily_at' => env('COLLECTION_PLANNER_DAILY_AT', '07:15'),
];
