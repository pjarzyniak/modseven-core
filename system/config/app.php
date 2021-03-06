<?php

return [
    'base_url' => '/',
    'index_file' => 'index.php',
    'charset' => 'utf-8',
    'errors' => TRUE,
    'reporting' => E_ALL,
    'profile' => FALSE,
    'caching' => FALSE,
    'expose' => FALSE,
    'timezone' => 'Europe/Vienna',
    'locale' => 'en_US.utf-8',
    'cookie' => [
        'salt' => FALSE,
        'httponly' => FALSE,
        'secure' => FALSE,
        'domain' => NULL,
    ],
    'view_template' => '',
    'trusted_hosts' => [
        // Set up your hostnames here
        //
        // Example:
        //
        //        'example\.org',
        //        '.*\.example\.org',
        //
        // Do not forget to escape your dots (.) as these are regex patterns.
        // These patterns should always fully match,
        // as they are prepended with `^` and appended with `$`
    ],
];
