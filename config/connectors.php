<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Connector framework (padosoft/askmydocs-connector-base)
|--------------------------------------------------------------------------
|
| Configuration for the pluggable external-source connector framework.
| Each connector implements
| `Padosoft\AskMyDocsConnectorBase\ConnectorInterface` and is
| registered either as a built-in below OR by an installed composer
| package via its `composer.json::extra.askmydocs.connectors` array.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Built-in connectors
    |--------------------------------------------------------------------------
    |
    | FQCN list of connector implementations the host app wants to
    | wire by hand (no separate composer package required). Most
    | consumers leave this empty and install one
    | `padosoft/askmydocs-connector-*` package per source instead —
    | the `extra.askmydocs.connectors` composer auto-discovery hook
    | in `ConnectorRegistry` finds them automatically.
    |
    */
    'built_in' => [
        // \App\Connectors\BuiltIn\MyCustomConnector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default sync cadence
    |--------------------------------------------------------------------------
    |
    | The `Padosoft\AskMyDocsConnectorBase\Scheduling\SyncScheduler`
    | dispatches a `ConnectorSyncJob` per active installation when
    | this many minutes have elapsed since the installation's
    | `last_sync_at`. Per-connector overrides live below.
    |
    */
    'default_sync_cadence_minutes' => (int) env('CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Per-connector cadence overrides
    |--------------------------------------------------------------------------
    |
    | Keyed by `ConnectorInterface::key()`. Use this to give chatty
    | connectors a tighter sync window without affecting the defaults.
    | e.g.
    |
    |   'per_connector_cadence' => [
    |       'google-drive' => 10,  // every 10 min
    |       'notion'       => 30,  // every 30 min
    |   ],
    |
    */
    'per_connector_cadence' => [
        // No overrides shipped by default — the framework picks
        // `default_sync_cadence_minutes` for every connector unless
        // operators set an override here.
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth state token TTL
    |--------------------------------------------------------------------------
    |
    | `BaseConnector::issueOAuthState()` stores its CSRF state token
    | in the application cache with this expiry (seconds). 10
    | minutes is long enough for a typical OAuth round-trip
    | including provider-side login + 2FA, short enough to make
    | replay attacks impractical.
    |
    */
    'oauth_state_ttl_seconds' => (int) env('CONNECTOR_OAUTH_STATE_TTL_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Queue name for ConnectorSyncJob
    |--------------------------------------------------------------------------
    |
    | Override to isolate connector traffic onto a dedicated queue
    | (e.g. `connectors` worker pool) so it doesn't compete with the
    | host application's chat / ingest hot path.
    |
    */
    'sync_job_queue' => env('CONNECTOR_SYNC_JOB_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Per-connector provider settings
    |--------------------------------------------------------------------------
    |
    | Connector implementations read their provider client_id /
    | client_secret / redirect_uri from this block. Each connector
    | owns its own sub-array; the host operator wires the env vars.
    | Per-connector packages typically `mergeConfigFrom()` their own
    | provider stub here.
    |
    */
    'providers' => [
        // Example provider stub — connector packages typically merge
        // their own block here via their service provider:
        //
        // 'my-connector' => [
        //     'client_id'           => env('CONNECTOR_MY_CLIENT_ID'),
        //     'client_secret'       => env('CONNECTOR_MY_CLIENT_SECRET'),
        //     'redirect_uri'        => env('CONNECTOR_MY_REDIRECT_URI'),
        //     'oauth_authorize_url' => 'https://...',
        //     'oauth_token_url'     => 'https://...',
        //     'api_base'            => 'https://...',
        // ],
    ],

];
