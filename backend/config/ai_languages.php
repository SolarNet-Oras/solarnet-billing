<?php

/*
 * SolarNet AI language policy.
 *
 * Keep language metadata in one place. New languages can be detected and
 * enabled for responses without changing the conversation or AI services.
 */
return [
    'default' => env('SOLARNET_AI_DEFAULT_LANGUAGE', 'en'),
    'fallback' => 'en',

    'languages' => [
        'en' => ['name' => 'English', 'response_enabled' => true],
        'fil' => ['name' => 'Filipino', 'response_enabled' => true],

        // Detected today, with a safe English/Filipino fallback until a
        // reviewed customer-support voice is enabled for each language.
        'ceb' => ['name' => 'Cebuano / Bisaya', 'response_enabled' => false],
        'ilo' => ['name' => 'Ilocano', 'response_enabled' => false],
        'hil' => ['name' => 'Hiligaynon', 'response_enabled' => false],
        'war' => ['name' => 'Waray', 'response_enabled' => false],
        'es' => ['name' => 'Spanish', 'response_enabled' => false],
        'zh' => ['name' => 'Chinese', 'response_enabled' => false],
        'ja' => ['name' => 'Japanese', 'response_enabled' => false],
    ],
];
