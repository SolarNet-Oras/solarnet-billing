<?php

return [
    // Two independent switches prevent a database setting from activating the
    // scheduler before the deployment environment has been approved.
    'automation_enabled' => filter_var(env('COLLECTION_PLANNER_AUTOMATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'algorithm_version' => 'phase1-v1',
    'timezone' => 'Asia/Manila',
    'daily_at' => env('COLLECTION_PLANNER_DAILY_AT', '07:15'),
    // Administrative placeholder accounts may retain the collector role for
    // reporting access, but must never receive customers or field routes.
    'excluded_field_collector_names' => ['collector'],
    'primary_collector_name' => 'EDMAR CONDE ALCONABA',
    'followup_collector_name' => 'Nelson Cuanico',
    'territory_names' => ['Primary territory', 'Secondary territory', 'Follow-up territory', 'Fallback territory'],
    'territory_areas' => [
        'Balocawe','Bantayan','Burak','Butnga','Camanga','Core','Dao','Iwayan','Kaguras',
        'Kalaw','Kalawit','Kalobian','Mabuhay','Nadacpan','Paypayon','Relocation','Riverside',
        'Sabang','San Eduardo','San Roque','Saurong','Tawagan','Tiguib',
        'Phase A','Phase B','Phase C','Phase D','Phase E','Phase F','Phase G',
    ],
];
