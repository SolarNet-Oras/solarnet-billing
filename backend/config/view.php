<?php

return [
    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | The API also renders invoice PDFs. Keeping Laravel's standard view
    | configuration makes deploy-time caching and PDF rendering use the same
    | resources/views directory instead of failing with "View path not found".
    |
    */
    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    */
    'compiled' => env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views'))),
];
