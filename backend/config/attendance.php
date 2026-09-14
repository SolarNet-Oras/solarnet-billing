<?php

return [
    'scan_cooldown_seconds' => (int) env('ATTENDANCE_SCAN_COOLDOWN', 10),
    'photo_retention_days' => (int) env('ATTENDANCE_PHOTO_RETENTION_DAYS', 90),
    'require_location' => filter_var(env('ATTENDANCE_REQUIRE_LOCATION', false), FILTER_VALIDATE_BOOL),
];
