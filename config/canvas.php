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
