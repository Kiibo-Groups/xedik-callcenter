<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    */

    'enabled' => env('VOICE_ENABLED', false),

    'direct_jssip_enabled' => env(
        'VOICE_DIRECT_JSSIP_ENABLED',
        false
    ),

    'agent_console' => [
        'enabled' => env('VOICE_AGENT_CONSOLE_ENABLED', false),

        'tenant_ids' => array_values(array_filter(
            array_map(
                'intval',
                explode(',', (string) env(
                    'VOICE_AGENT_CONSOLE_TENANT_IDS',
                    ''
                ))
            ),
            fn (int $id): bool => $id > 0
        )),

        'user_ids' => array_values(array_filter(
            array_map(
                'intval',
                explode(',', (string) env(
                    'VOICE_AGENT_CONSOLE_USER_IDS',
                    ''
                ))
            ),
            fn (int $id): bool => $id > 0
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser SIP connection
    |--------------------------------------------------------------------------
    */

    'realm' => env('VOICE_REALM'),

    'wss_server' => env('VOICE_WSS_SERVER'),

    'pbx_id' => env('VOICE_PBX_ID'),

    'recordings' => [
        'base_url' => env('ASTERISK_RECORDING_BASE_URL'),
        'shared_secret' => env('ASTERISK_RECORDING_SHARED_SECRET'),
        'url_ttl' => (int) env('ASTERISK_RECORDING_URL_TTL', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent session lifecycle
    |--------------------------------------------------------------------------
    */

    'session_ttl_seconds' => (int) env(
        'VOICE_SESSION_TTL_SECONDS',
        28800
    ),

    'heartbeat_stale_seconds' => (int) env(
        'VOICE_HEARTBEAT_STALE_SECONDS',
        900
    ),

    'pending_session_timeout_seconds' => (int) env(
        'VOICE_PENDING_SESSION_TIMEOUT_SECONDS',
        120
    ),

    'replaced_session_retention_seconds' => (int) env(
        'VOICE_REPLACED_SESSION_RETENTION_SECONDS',
        300
    ),

    'max_sessions_per_agent' => (int) env(
        'VOICE_MAX_SESSIONS_PER_AGENT',
        1
    ),

    // How recently (in seconds) a call_state update must have been received
    // to be considered "fresh" for ownership policy decisions.
    // Stale call states always produce policy=confirm rather than silent or blocked.
    // Recommended range: 90–300s (heartbeat is 60s, so 2× heartbeat = 120s).
    'call_state_fresh_seconds' => (int) env(
        'VOICE_CALL_STATE_FRESH_SECONDS',
        120
    ),

    // Safety gate for the call_state ownership policy.
    // Keep FALSE until the frontend (Codex) is connected and reporting call states.
    // While false: other_tab always produces confirm (never silent or blocked),
    //   regardless of call_state value. The /voice/session/call-state endpoint
    //   remains active for integration work.
    // Set to TRUE only after validating that ringing/active/no_call are reliably
    //   reported by the browser on every JsSIP state transition.
    'call_state_policy_enabled' => (bool) env(
        'VOICE_CALL_STATE_POLICY_ENABLED',
        false
    ),

    // QA whitelist of allowed dial destinations.
    // Only applies while this is a QA/lab deployment.
    // Production agents dial any number — this guard should be removed when routing
    // is open. Default: ['9001'] (original single QA extension).
    'qa_allowed_destinations' => array_values(array_filter(
        array_map(
            'trim',
            explode(',', (string) env('VOICE_QA_ALLOWED_DESTINATIONS', '9001'))
        ),
        fn(string $d): bool => $d !== ''
    )),

    /*
    |--------------------------------------------------------------------------
    | Stable SIP endpoint provisioning
    |--------------------------------------------------------------------------
    */

    'sip_provisioning_mode' => env(
        'VOICE_SIP_PROVISIONING_MODE',
        'stable_user_endpoint'
    ),

    'stable_endpoint_prefix' => env(
        'VOICE_STABLE_ENDPOINT_PREFIX',
        'v'
    ),

    'stable_endpoint_invalidate_on_revoke' => env(
        'VOICE_STABLE_ENDPOINT_INVALIDATE_ON_REVOKE',
        true
    ),

    /*
    |--------------------------------------------------------------------------
    | Asterisk Realtime
    |--------------------------------------------------------------------------
    */

    'asterisk_realtime_connection' => env(
        'ASTERISK_REALTIME_CONNECTION',
        'asterisk_realtime'
    ),

    'asterisk_transport' => env(
        'ASTERISK_REALTIME_TRANSPORT',
        'wss'
    ),

    'asterisk_tables' => [
        'endpoints' => env(
            'ASTERISK_REALTIME_ENDPOINTS_TABLE',
            'ps_endpoints'
        ),

        'auths' => env(
            'ASTERISK_REALTIME_AUTHS_TABLE',
            'ps_auths'
        ),

        'aors' => env(
            'ASTERISK_REALTIME_AORS_TABLE',
            'ps_aors'
        ),

        'queues' => env(
            'VOICE_ASTERISK_QUEUES_TABLE',
            'queues'
        ),

        'queue_members' => env(
            'VOICE_ASTERISK_QUEUE_MEMBERS_TABLE',
            'queue_members'
        ),
    ],
];
