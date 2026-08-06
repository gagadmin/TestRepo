<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Detection thresholds
    |--------------------------------------------------------------------------
    |
    | Every detector reads its thresholds from here so they can be tuned per
    | environment without editing detection code. Windows are in minutes.
    |
    */

    'detection' => [
        'window_minutes' => (int) env('SECURITY_SCAN_WINDOW_MINUTES', 60),

        'brute_force' => [
            'failures_per_ip' => (int) env('SECURITY_BRUTE_FORCE_IP_THRESHOLD', 10),
            'failures_per_account' => (int) env('SECURITY_BRUTE_FORCE_ACCOUNT_THRESHOLD', 6),
            'window_minutes' => (int) env('SECURITY_BRUTE_FORCE_WINDOW', 15),
        ],

        'credential_stuffing' => [
            'distinct_accounts_per_ip' => (int) env('SECURITY_STUFFING_ACCOUNT_THRESHOLD', 5),
            'window_minutes' => (int) env('SECURITY_STUFFING_WINDOW', 30),
        ],

        'session_anomaly' => [
            'distinct_ips_per_user' => (int) env('SECURITY_SESSION_IP_THRESHOLD', 3),
            'window_minutes' => (int) env('SECURITY_SESSION_WINDOW', 60),
        ],

        'data_exfiltration' => [
            'exports_per_user' => (int) env('SECURITY_EXPORT_THRESHOLD', 25),
            'window_minutes' => (int) env('SECURITY_EXPORT_WINDOW', 60),
        ],

        'dormant_account' => [
            'dormant_days' => (int) env('SECURITY_DORMANT_DAYS', 90),
        ],

        'after_hours' => [
            'enabled' => (bool) env('SECURITY_AFTER_HOURS_ENABLED', true),
            'start_hour' => (int) env('SECURITY_BUSINESS_START_HOUR', 6),
            'end_hour' => (int) env('SECURITY_BUSINESS_END_HOUR', 21),
        ],

        'password_age' => [
            'max_days' => (int) env('SECURITY_PASSWORD_MAX_AGE_DAYS', 180),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-factor authentication (ISO 27001 A.8.5 / NIST IA-2)
    |--------------------------------------------------------------------------
    |
    | TOTP (RFC 6238) via an authenticator app. A session is never established
    | until the second factor has been verified.
    |
    */

    'two_factor' => [
        'enabled' => (bool) env('MFA_ENABLED', true),

        // Every account must enrol. Privileged-only enforcement is available by
        // setting this false and listing roles below.
        'required_for_all' => (bool) env('MFA_REQUIRED_FOR_ALL', true),
        'required_roles' => ['administrator', 'security_officer'],

        'issuer' => env('MFA_ISSUER', 'Ask GAHolding'),

        // A 30-second step with one window either side tolerates ordinary clock
        // drift. Larger windows widen the replay opportunity.
        'window' => (int) env('MFA_WINDOW', 1),

        'recovery_code_count' => 8,

        // Minutes the half-authenticated state between password and code
        // remains valid. Short, because it is an unauthenticated pending state.
        'challenge_ttl_minutes' => (int) env('MFA_CHALLENGE_TTL', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password policy (NIST SP 800-63B)
    |--------------------------------------------------------------------------
    |
    | 800-63B section 5.1.1.2 advises AGAINST mandatory periodic rotation,
    | because it drives users towards weak incrementing passwords. Length and
    | breach screening carry the weight instead. `max_age_days` is implemented
    | but disabled; set it if an auditor insists on a literal IA-5(1)(d)
    | maximum-lifetime reading.
    |
    */

    'password' => [
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 12),
        'max_length' => 4096,

        // 0 disables forced rotation, which is the recommended setting.
        'max_age_days' => (int) env('PASSWORD_MAX_AGE_DAYS', 0),

        // Number of previous passwords that may not be reused.
        'history_depth' => (int) env('PASSWORD_HISTORY_DEPTH', 5),

        // Reject passwords appearing in the bundled breached/common list.
        'block_compromised' => (bool) env('PASSWORD_BLOCK_COMPROMISED', true),

        // Reject passwords containing the user's name or email local part.
        'block_contextual' => (bool) env('PASSWORD_BLOCK_CONTEXTUAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account lockout (CIS 6.2)
    |--------------------------------------------------------------------------
    |
    | Progressive backoff scoped to account + source address. Account-wide
    | locking would let anyone who knows an email address deny service to its
    | owner, so the source address forms part of the key.
    |
    */

    'lockout' => [
        'enabled' => (bool) env('LOCKOUT_ENABLED', true),

        // Failures tolerated before the first lock applies.
        'threshold' => (int) env('LOCKOUT_THRESHOLD', 5),

        // Lock duration in minutes per additional failure past the threshold.
        // The final value repeats for every further failure.
        'backoff_minutes' => [1, 5, 15, 60, 240],

        // A quiet period resets the counter so an honest user who mistyped
        // last week does not start from a punished state.
        'decay_minutes' => (int) env('LOCKOUT_DECAY_MINUTES', 60),

        // Second-factor attempts get their own, tighter budget.
        'two_factor_threshold' => (int) env('LOCKOUT_2FA_THRESHOLD', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting
    |--------------------------------------------------------------------------
    |
    | The agent records and alerts. It never disables accounts or takes any
    | automated containment action; a human always decides the response.
    |
    */

    'alerts' => [
        'enabled' => (bool) env('SECURITY_ALERTS_ENABLED', true),
        'minimum_severity' => env('SECURITY_ALERT_MIN_SEVERITY', 'high'),
        'recipients' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('SECURITY_ALERT_RECIPIENTS', '')))
        )),
        'teams_enabled' => (bool) env('SECURITY_ALERT_TEAMS', false),
        'throttle_minutes' => (int) env('SECURITY_ALERT_THROTTLE_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'resolved_event_days' => (int) env('SECURITY_EVENT_RETENTION_DAYS', 365),
        'scan_history_days' => (int) env('SECURITY_SCAN_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | External security data sources
    |--------------------------------------------------------------------------
    |
    | These remain false until a real connector is configured. The dashboard
    | renders an explicit "not connected" state for each one rather than
    | displaying placeholder or sample figures.
    |
    */

    'connectors' => [
        'defender_endpoint' => (bool) env('SECURITY_CONNECTOR_DEFENDER', false),
        'entra_id' => (bool) env('SECURITY_CONNECTOR_ENTRA', false),
        'defender_office365' => (bool) env('SECURITY_CONNECTOR_O365', false),
        'cloud_posture' => (bool) env('SECURITY_CONNECTOR_CLOUD', false),
        'vulnerability_feed' => (bool) env('SECURITY_CONNECTOR_VULN', false),
    ],
];
