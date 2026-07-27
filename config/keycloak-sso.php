<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Config resolver
    |--------------------------------------------------------------------------
    |
    | The class responsible for turning the current environment into a set of
    | Keycloak connection credentials. The default resolver reads from the
    | "connections" array below (env-driven). Projects that store credentials
    | elsewhere (e.g. a system_configs DB table) can bind their own
    | implementation of PhAb\KeycloakSso\Contracts\ConfigResolver.
    |
    */

    'resolver' => \PhAb\KeycloakSso\Resolvers\ConfigConnectionResolver::class,

    /*
    |--------------------------------------------------------------------------
    | Credentials callback (for CallbackConnectionResolver)
    |--------------------------------------------------------------------------
    |
    | Used only when 'resolver' is set to CallbackConnectionResolver::class.
    | You provide the query; the package just calls it. Your database schema
    | stays entirely your own — the package never touches a table.
    |
    | PRIORITY (see 'credentials_priority' below): by default .env WINS and
    | this callback only fills in what .env is missing. Set it null to ignore
    | the callback entirely. To make the callback win instead — .env becomes
    | the fallback — set 'credentials_priority' => true.
    |
    | Provide either:
    |   - an invokable class name (recommended; survives `config:cache`), or
    |   - a closure: fn (string $environment) => array|ConnectionConfig
    |
    | The callback returns an array with keys: base_url, realm, client_id,
    | client_secret (or a ConnectionConfig instance).
    |
    | Example — reading a key-value settings table:
    |
    |   'credentials' => function (string $environment) {
    |       $rows = DB::table('system_configs')
    |           ->where('group_name', 'KEYCLOAK')
    |           ->where('environment', $environment)
    |           ->pluck('value', 'key_name');
    |
    |       return [
    |           'base_url'      => $rows['KEYCLOAK_BASE_URL'],
    |           'realm'         => $rows['KEYCLOAK_REALM'],
    |           'client_id'     => $rows['KEYCLOAK_CLIENT_ID'],
    |           'client_secret' => $rows['KEYCLOAK_CLIENT_SECRET'],
    |       ];
    |   },
    |
    */

    'credentials' => null,

    // false => .env wins, the callback only fills in what .env is missing (default)
    // true  => the callback above wins, .env is the fallback
    'credentials_priority' => env('KEYCLOAK_CREDENTIALS_PRIORITY', false),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Per-environment Keycloak credentials. The active connection is chosen by
    | the application environment (app()->environment()), falling back to
    | "default" when no environment-specific entry exists.
    |
    */

    'connections' => [

        'local' => [
            'base_url'      => env('KEYCLOAK_BASE_URL_DEV', env('KEYCLOAK_BASE_URL')),
            'realm'         => env('KEYCLOAK_REALM_DEV', env('KEYCLOAK_REALM')),
            'client_id'     => env('KEYCLOAK_CLIENT_ID_DEV', env('KEYCLOAK_CLIENT_ID')),
            'client_secret' => env('KEYCLOAK_CLIENT_SECRET_DEV', env('KEYCLOAK_CLIENT_SECRET')),
        ],

        'staging' => [
            'base_url'      => env('KEYCLOAK_BASE_URL_STAGING', env('KEYCLOAK_BASE_URL')),
            'realm'         => env('KEYCLOAK_REALM_STAGING', env('KEYCLOAK_REALM')),
            'client_id'     => env('KEYCLOAK_CLIENT_ID_STAGING', env('KEYCLOAK_CLIENT_ID')),
            'client_secret' => env('KEYCLOAK_CLIENT_SECRET_STAGING', env('KEYCLOAK_CLIENT_SECRET')),
        ],

        'production' => [
            'base_url'      => env('KEYCLOAK_BASE_URL_PROD', env('KEYCLOAK_BASE_URL')),
            'realm'         => env('KEYCLOAK_REALM_PROD', env('KEYCLOAK_REALM')),
            'client_id'     => env('KEYCLOAK_CLIENT_ID_PROD', env('KEYCLOAK_CLIENT_ID')),
            'client_secret' => env('KEYCLOAK_CLIENT_SECRET_PROD', env('KEYCLOAK_CLIENT_SECRET')),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth scope
    |--------------------------------------------------------------------------
    */

    'scope' => env('KEYCLOAK_SCOPE', 'openid profile email'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('KEYCLOAK_HTTP_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    |
    | Channel used for structured auth logging. Set to null to disable logging.
    |
    */

    'log_channel' => env('KEYCLOAK_LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | DB-password fallback
    |--------------------------------------------------------------------------
    |
    | Optional. When Keycloak rejects the credentials as invalid, the service
    | invokes this callback so the application can attempt its own local
    | verification (e.g. a legacy users table). Return a truthy value (a user
    | model/array) to accept the login, or null to reject.
    |
    | Provide either:
    |   - an invokable class name (resolved from the container), or
    |   - null to disable.
    |
    | Signature: function (string $username, string $password): mixed
    |
    */

    'fallback' => null,

];
