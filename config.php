<?php
// config.php
// Fill these in with your DirectAdmin MySQL details.
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'database_name',
        'user'    => 'database_user',
        'pass'    => 'database_password',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        // If the app is not in the domain root, set e.g. '/portal'
        'base_url' => '',
        'app_name' => 'Seguilo Connect'
    ],
];
