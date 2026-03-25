<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canvas LMS Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for your Canvas LMS instance. This should be the root URL
    | without trailing slash. Example: https://canvas.instructure.com
    |
    */

    'base_url' => env('CANVAS_URL', 'https://canvas.instructure.com'),

    /*
    |--------------------------------------------------------------------------
    | User-Agent Header
    |--------------------------------------------------------------------------
    |
    | Canvas API requires a User-Agent header on all requests. Customise this
    | to identify your application.
    |
    */

    'user_agent' => env('CANVAS_USER_AGENT', 'CanvasLmsLaravel/1.0'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Driver
    |--------------------------------------------------------------------------
    |
    | The authentication driver to use when making API requests.
    | Supported: "token", "oauth2"
    |
    */

    'auth_driver' => env('CANVAS_AUTH_DRIVER', 'token'),

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    |
    | Used when auth_driver is "token". Generate a token in Canvas under
    | Account > Settings > Approved Integrations.
    |
    */

    'token' => env('CANVAS_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Account ID
    |--------------------------------------------------------------------------
    |
    | The Canvas account ID for account-scoped operations (e.g., creating
    | users, listing courses at the account level). Use 1 for the root account.
    |
    */

    'account_id' => env('CANVAS_ACCOUNT_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | API Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, all Canvas API requests and responses are logged. Set
    | 'channel' to a Laravel log channel name defined in config/logging.php
    | (e.g. "canvas", "stack", "single"). Leave null to use the default channel.
    |
    */

    'logging' => [
        'enabled' => env('CANVAS_LOG_ENABLED', false),
        'channel' => env('CANVAS_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-System Field Adapter Mappings
    |--------------------------------------------------------------------------
    |
    | Define named field-mapping templates for bidirectional translation between
    | Canvas and other systems (Salesforce, SQL databases, custom APIs, etc.).
    |
    | Each key is a resource type name (e.g. 'user', 'course'). The value is
    | an array of field rows, where each row describes one logical field across
    | all connected systems.
    |
    | Row keys:
    |   - Any system name (string) => the field name in that system
    |   - 'priority' (array)       => ordered system names; first system with a
    |                                  value wins on conflict in merge(). Omit to
    |                                  fall back to the priority passed to merge().
    |   - 'transforms' (array)     => direction-keyed callables:
    |                                    'to_{system}'   — applied when projecting outbound
    |                                    'from_{system}' — applied when ingesting in merge()
    |
    | Load a named mapper:
    |   ResourceMapper::fromConfig('user')
    |
    | Two-way translation:
    |   $mapper->from('salesforce', $data)->to('canvas');
    |
    | Three-way merge with per-field priority:
    |   $mapper->merge(['canvas' => $a, 'salesforce' => $b, 'sql' => $c]);
    |   $record->for('canvas');
    |
    | Push external mutations into Canvas:
    |   app(AdapterService::class)->push('user', $id, 'salesforce', $payload);
    |
    | Enable HTTP mutation endpoint (POST /canvas/adapter/{resource}/{id}):
    |   Set 'routes_enabled' => true and publish the controller stub:
    |   php artisan vendor:publish --tag=canvas-adapter
    |
    */

    'adapters' => [

        'routes_enabled' => false,

        // Example: user mapping between Canvas, Salesforce, and a SQL database.
        // Uncomment and customise to match your actual field names.
        //
        // 'user' => [
        //     ['canvas' => 'name',       'salesforce' => 'Full_Name__c',  'sql' => 'full_name',
        //      'priority' => ['canvas', 'salesforce', 'sql']],
        //     ['canvas' => 'email',      'salesforce' => 'Email',          'sql' => 'email_address'],
        //     ['canvas' => 'sis_user_id','salesforce' => 'Student_ID__c',  'sql' => 'student_id'],
        // ],
        //
        // Example: course mapping where Salesforce owns start/end dates.
        //
        // 'course' => [
        //     ['canvas' => 'name',     'salesforce' => 'Course_Name__c',
        //      'priority' => ['canvas']],
        //     ['canvas' => 'start_at', 'salesforce' => 'Start_Date__c',
        //      'priority' => ['salesforce', 'canvas'],
        //      'transforms' => ['to_salesforce' => fn($v) => date('Y-m-d', strtotime($v))]],
        //     ['canvas' => 'end_at',   'salesforce' => 'End_Date__c',
        //      'priority' => ['salesforce', 'canvas']],
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Configuration
    |--------------------------------------------------------------------------
    |
    | Used when auth_driver is "oauth2". Register a Developer Key in Canvas
    | under Admin > Developer Keys to obtain client_id and client_secret.
    |
    */

    'oauth2' => [

        'client_id' => env('CANVAS_CLIENT_ID'),

        'client_secret' => env('CANVAS_CLIENT_SECRET'),

        'redirect_uri' => env('CANVAS_REDIRECT_URI'),

        /*
        | Token storage driver. The "cache" driver stores tokens in Laravel's
        | cache (works with any cache driver: file, Redis, database).
        | The "database" driver stores tokens in the canvas_oauth_tokens table
        | (requires publishing and running the package migration).
        | Supported: "cache", "database"
        */
        'storage_driver' => env('CANVAS_TOKEN_STORAGE', 'cache'),

        'cache_prefix' => 'canvas_oauth_token',

    ],

];
