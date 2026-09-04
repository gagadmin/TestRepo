<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityScan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Detection engine for the security agent.
 *
 * Every detector reads telemetry the application already owns (audit logs,
 * sessions, users, data sources, runtime configuration) and records findings
 * as SecurityEvent rows. Detectors never mutate accounts, revoke sessions, or
 * take any containment action -- response is always a human decision.
 */
class SecurityMonitor
{
    public function __construct(private readonly SecurityPostureService $posture) {}

    /**
     * Run every detector and persist the findings.
     */
    public function scan(string $trigger = 'scheduled', ?int $triggeredBy = null): SecurityScan
    {
        $scan = SecurityScan::create([
            'trigger' => $trigger,
            'triggered_by' => $triggeredBy,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $detectors = [
            'brute_force' => fn () => $this->detectBruteForce(),
            'credential_stuffing' => fn () => $this->detectCredentialStuffing(),
            'session_anomaly' => fn () => $this->detectSessionAnomalies(),
            'privilege_escalation' => fn () => $this->detectPrivilegeEscalation(),
            'data_exfiltration' => fn () => $this->detectDataExfiltration(),
            'dormant_account' => fn () => $this->detectDormantAccountActivity(),
            'after_hours_admin' => fn () => $this->detectAfterHoursAdminActivity(),
            'inactive_session_probe' => fn () => $this->detectRevokedSessionProbing(),
            'configuration_drift' => fn () => $this->detectConfigurationDrift(),
            'credential_exposure' => fn () => $this->detectCredentialExposure(),
            'two_factor_gap' => fn () => $this->detectTwoFactorGaps(),
        ];

        $findings = [];
        $results = [];
        $failed = [];

        foreach ($detectors as $name => $detector) {
            try {
                $detected = $detector();
                $findings = [...$findings, ...$detected];
                $results[$name] = ['status' => 'ok', 'findings' => count($detected)];
            } catch (Throwable $exception) {
                // One broken detector must not abort the whole scan.
                $failed[] = $name;
                $results[$name] = [
                    'status' => 'failed',
                    'message' => str($exception->getMessage())->limit(300)->toString(),
                ];
                Log::warning("Security detector [{$name}] failed.", [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $created = 0;

        foreach ($findings as $finding) {
            if ($this->record($finding)) {
                $created++;
            }
        }

        $scan->update([
            'status' => $failed === [] ? 'succeeded' : 'partial',
            'events_detected' => count($findings),
            'events_created' => $created,
            'detectors_run' => count($detectors),
            'security_score' => $this->posture->securityScore()['score'] ?? null,
            'detector_results' => $results,
            'error_message' => $failed === []
                ? null
                : 'Detectors failed: '.implode(', ', $failed),
            'finished_at' => now(),
        ]);

        return $scan->refresh();
    }

    /**
     * Upsert a finding by fingerprint so a persisting condition increments one
     * row rather than flooding the table on every scan.
     */
    private function record(array $finding): bool
    {
        $existing = SecurityEvent::where('fingerprint', $finding['fingerprint'])->first();
        $now = now();

        if ($existing) {
            // A finding that recurs after being resolved is reopened.
            $reopened = in_array($existing->status, ['resolved', 'false_positive'], true);

            $existing->update([
                'occurrences' => $existing->occurrences + 1,
                'last_detected_at' => $now,
                'severity' => $finding['severity'],
                'description' => $finding['description'],
                'evidence' => $finding['evidence'] ?? null,
                'status' => $reopened ? 'open' : $existing->status,
                'resolved_at' => $reopened ? null : $existing->resolved_at,
                'resolved_by' => $reopened ? null : $existing->resolved_by,
                'alerted' => $reopened ? false : $existing->alerted,
            ]);

            return false;
        }

        SecurityEvent::create([
            'detector' => $finding['detector'],
            'category' => $finding['category'],
            'severity' => $finding['severity'],
            'title' => $finding['title'],
            'description' => $finding['description'],
            'status' => 'open',
            'user_id' => $finding['user_id'] ?? null,
            'ip_address' => $finding['ip_address'] ?? null,
            'fingerprint' => $finding['fingerprint'],
            'occurrences' => 1,
            'evidence' => $finding['evidence'] ?? null,
            'recommendation' => $finding['recommendation'] ?? null,
            'first_detected_at' => $now,
            'last_detected_at' => $now,
            'occurred_at' => $finding['occurred_at'] ?? $now,
            'alerted' => false,
        ]);

        return true;
    }

    private function since(int $minutes): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes($minutes);
    }

    /**
     * Driver-aware JSON path extraction.
     *
     * MySQL and SQLite spell this differently, and the test suite runs on
     * SQLite while production runs on MySQL/PostgreSQL.
     */
    public static function jsonPath(string $column, string $path): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$path}'))",
            'pgsql' => "({$column}::json ->> '{$path}')",
            default => "json_extract({$column}, '$.{$path}')",
        };
    }

    /**
     * Portable severity ordering. FIELD() is MySQL-only, so a CASE expression
     * is used instead.
     */
    public static function severityOrder(string $column = 'severity'): string
    {
        $cases = [];

        foreach (SecurityEvent::SEVERITIES as $index => $severity) {
            $cases[] = "WHEN '{$severity}' THEN {$index}";
        }

        return 'CASE '.$column.' '.implode(' ', $cases).' ELSE 99 END';
    }

    /* ------------------------------------------------------------------
     * Detector: brute force
     * ------------------------------------------------------------------ */

    private function detectBruteForce(): array
    {
        $config = config('security.detection.brute_force');
        $since = $this->since($config['window_minutes']);
        $findings = [];

        $byIp = DB::table('audit_logs')
            ->select('ip_address', DB::raw('COUNT(*) as attempts'), DB::raw('MIN(created_at) as first_attempt'))
            ->where('event', 'auth.login_failed')
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->having('attempts', '>=', $config['failures_per_ip'])
            ->get();

        foreach ($byIp as $row) {
            $findings[] = [
                'detector' => 'brute_force',
                'category' => 'threat',
                'severity' => $row->attempts >= ($config['failures_per_ip'] * 3) ? 'critical' : 'high',
                'title' => "Brute force login attempts from {$row->ip_address}",
                'description' => "{$row->attempts} failed login attempts originated from {$row->ip_address} "
                    ."within the last {$config['window_minutes']} minutes.",
                'ip_address' => $row->ip_address,
                'fingerprint' => 'brute_force:ip:'.$row->ip_address.':'.$since->format('YmdH'),
                'occurred_at' => $row->first_attempt ? CarbonImmutable::parse($row->first_attempt) : null,
                'evidence' => [
                    'ip_address' => $row->ip_address,
                    'failed_attempts' => (int) $row->attempts,
                    'window_minutes' => $config['window_minutes'],
                ],
                'recommendation' => [
                    'Confirm whether the source address belongs to a known office or VPN range.',
                    'Block the address at the network edge if it is not recognised.',
                    'Review whether any account from this address later logged in successfully.',
                ],
            ];
        }

        // Repeated failures against a single account, identified by the HMAC
        // fingerprint the auth controller stores (the plaintext email is never
        // written to the audit log).
        $accountExpression = self::jsonPath('metadata', 'email_fingerprint');

        $byAccount = DB::table('audit_logs')
            ->select(
                DB::raw("{$accountExpression} as account"),
                DB::raw('COUNT(*) as attempts'),
                DB::raw('COUNT(DISTINCT ip_address) as sources'),
                DB::raw('MIN(created_at) as first_attempt'),
            )
            ->where('event', 'auth.login_failed')
            ->where('created_at', '>=', $since)
            ->whereNotNull('metadata')
            ->groupByRaw($accountExpression)
            ->having('attempts', '>=', $config['failures_per_account'])
            ->havingRaw("{$accountExpression} IS NOT NULL")
            ->get();

        foreach ($byAccount as $row) {
            $short = substr((string) $row->account, 0, 12);

            $findings[] = [
                'detector' => 'brute_force',
                'category' => 'identity',
                'severity' => $row->sources > 1 ? 'high' : 'medium',
                'title' => "Repeated failed logins against a single account ({$short}…)",
                'description' => "{$row->attempts} failed login attempts targeted one account from "
                    ."{$row->sources} distinct address(es) in the last {$config['window_minutes']} minutes. "
                    .'The account is identified by its stored fingerprint; the email address is never logged in plaintext.',
                'fingerprint' => 'brute_force:account:'.$row->account.':'.$since->format('YmdH'),
                'occurred_at' => $row->first_attempt ? CarbonImmutable::parse($row->first_attempt) : null,
                'evidence' => [
                    'account_fingerprint' => $row->account,
                    'failed_attempts' => (int) $row->attempts,
                    'distinct_sources' => (int) $row->sources,
                ],
                'recommendation' => [
                    'Identify the account by matching the fingerprint, then contact the owner.',
                    'Force a password reset if the owner did not make these attempts.',
                    'Consider temporarily deactivating the account while investigating.',
                ],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: credential stuffing
     * ------------------------------------------------------------------ */

    private function detectCredentialStuffing(): array
    {
        $config = config('security.detection.credential_stuffing');
        $since = $this->since($config['window_minutes']);

        $accountExpression = self::jsonPath('metadata', 'email_fingerprint');

        $rows = DB::table('audit_logs')
            ->select(
                'ip_address',
                DB::raw("COUNT(DISTINCT {$accountExpression}) as accounts"),
                DB::raw('COUNT(*) as attempts'),
                DB::raw('MIN(created_at) as first_attempt'),
            )
            ->where('event', 'auth.login_failed')
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->having('accounts', '>=', $config['distinct_accounts_per_ip'])
            ->get();

        return $rows->map(fn ($row) => [
            'detector' => 'credential_stuffing',
            'category' => 'threat',
            'severity' => 'critical',
            'title' => "Credential stuffing pattern from {$row->ip_address}",
            'description' => "{$row->ip_address} attempted to sign in to {$row->accounts} different accounts "
                ."({$row->attempts} attempts) within {$config['window_minutes']} minutes. This pattern is "
                .'characteristic of an automated credential stuffing attack using a breached password list.',
            'ip_address' => $row->ip_address,
            'fingerprint' => 'credential_stuffing:'.$row->ip_address.':'.$since->format('YmdH'),
            'occurred_at' => $row->first_attempt ? CarbonImmutable::parse($row->first_attempt) : null,
            'evidence' => [
                'ip_address' => $row->ip_address,
                'distinct_accounts_targeted' => (int) $row->accounts,
                'total_attempts' => (int) $row->attempts,
            ],
            'recommendation' => [
                'Block the source address immediately at the network edge.',
                'Check whether any targeted account subsequently authenticated successfully.',
                'Force password resets for any account that did authenticate from this address.',
            ],
        ])->all();
    }

    /* ------------------------------------------------------------------
     * Detector: concurrent sessions from many addresses
     * ------------------------------------------------------------------ */

    private function detectSessionAnomalies(): array
    {
        $config = config('security.detection.session_anomaly');
        $since = $this->since($config['window_minutes']);

        $rows = DB::table('audit_logs')
            ->select(
                'user_id',
                DB::raw('COUNT(DISTINCT ip_address) as sources'),
                DB::raw('MIN(created_at) as first_seen'),
            )
            ->where('event', 'auth.login')
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->whereNotNull('ip_address')
            ->groupBy('user_id')
            ->having('sources', '>=', $config['distinct_ips_per_user'])
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $user = User::find($row->user_id);

            if (! $user) {
                continue;
            }

            $addresses = DB::table('audit_logs')
                ->where('event', 'auth.login')
                ->where('user_id', $row->user_id)
                ->where('created_at', '>=', $since)
                ->whereNotNull('ip_address')
                ->distinct()
                ->pluck('ip_address')
                ->take(20)
                ->all();

            $findings[] = [
                'detector' => 'session_anomaly',
                'category' => 'identity',
                'severity' => $row->sources >= ($config['distinct_ips_per_user'] * 2) ? 'high' : 'medium',
                'title' => "{$user->name} signed in from {$row->sources} different addresses",
                'description' => "{$user->name} authenticated from {$row->sources} distinct IP addresses within "
                    ."{$config['window_minutes']} minutes. This can indicate a shared or stolen session, "
                    .'or simply a user moving between networks.',
                'user_id' => $user->id,
                'fingerprint' => 'session_anomaly:'.$user->id.':'.$since->format('YmdH'),
                'occurred_at' => $row->first_seen ? CarbonImmutable::parse($row->first_seen) : null,
                'evidence' => [
                    'user' => $user->name,
                    'department' => $user->department,
                    'distinct_sources' => (int) $row->sources,
                    'addresses' => $addresses,
                ],
                'recommendation' => [
                    'Confirm the sign-in locations with the user.',
                    'Review the active sessions listed on the security dashboard.',
                    'Force a password reset if any location is unexpected.',
                ],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: privilege escalation
     * ------------------------------------------------------------------ */

    private function detectPrivilegeEscalation(): array
    {
        $since = $this->since(config('security.detection.window_minutes'));

        $changes = DB::table('audit_logs')
            ->where('event', 'user.access.updated')
            ->where('created_at', '>=', $since)
            ->get(['id', 'user_id', 'ip_address', 'metadata', 'created_at', 'auditable_id']);

        $findings = [];

        foreach ($changes as $change) {
            $metadata = json_decode((string) $change->metadata, true) ?? [];
            $before = collect($metadata['before']['roles'] ?? []);
            $after = collect($metadata['after']['roles'] ?? []);
            $gained = $after->diff($before)->values();

            $sensitive = $gained->intersect(['administrator', 'security_officer'])->values();

            // Also flag a reactivated account in the same change. Read from the
            // metadata, not from $before: that holds only the roles list, so an
            // `is_active` lookup against it was always null and this branch
            // never fired.
            $reactivated = ($metadata['before']['is_active'] ?? null) === false
                && ($metadata['after']['is_active'] ?? null) === true;

            if ($sensitive->isEmpty() && ! $reactivated) {
                continue;
            }

            $actor = $change->user_id ? User::find($change->user_id) : null;
            $subject = $change->auditable_id ? User::find((int) $change->auditable_id) : null;

            $findings[] = [
                'detector' => 'privilege_escalation',
                'category' => 'identity',
                'severity' => $sensitive->isNotEmpty() ? 'high' : 'medium',
                'title' => $sensitive->isNotEmpty()
                    ? 'Privileged role granted: '.$sensitive->implode(', ')
                    : 'Deactivated account was re-enabled',
                'description' => sprintf(
                    '%s modified access for %s. %s',
                    $actor?->name ?? 'An administrator',
                    $subject?->name ?? "user #{$change->auditable_id}",
                    $sensitive->isNotEmpty()
                        ? 'Roles gained: '.$sensitive->implode(', ').'.'
                        : 'The account was changed from inactive to active.'
                ),
                'user_id' => $subject?->id,
                'ip_address' => $change->ip_address,
                'fingerprint' => 'privilege_escalation:audit:'.$change->id,
                'occurred_at' => CarbonImmutable::parse($change->created_at),
                'evidence' => [
                    'actor' => $actor?->name,
                    'subject' => $subject?->name,
                    'roles_before' => $before->all(),
                    'roles_after' => $after->all(),
                    'roles_gained' => $gained->all(),
                    'reactivated' => $reactivated,
                    'audit_log_id' => $change->id,
                ],
                'recommendation' => [
                    'Confirm the change was approved through your access request process.',
                    'Verify the granting administrator intended this change.',
                    'Revoke the role if the change was not authorised.',
                ],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: unusual export volume
     * ------------------------------------------------------------------ */

    private function detectDataExfiltration(): array
    {
        $config = config('security.detection.data_exfiltration');
        $since = $this->since($config['window_minutes']);

        $rows = DB::table('audit_logs')
            ->select(
                'user_id',
                'ip_address',
                DB::raw('COUNT(*) as exports'),
                DB::raw('MIN(created_at) as first_export'),
            )
            ->whereIn('event', ['report.exported', 'analytics.generated'])
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->groupBy('user_id', 'ip_address')
            ->having('exports', '>=', $config['exports_per_user'])
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $user = User::find($row->user_id);

            if (! $user) {
                continue;
            }

            $findings[] = [
                'detector' => 'data_exfiltration',
                'category' => 'data',
                'severity' => $row->exports >= ($config['exports_per_user'] * 2) ? 'high' : 'medium',
                'title' => "Unusual export volume by {$user->name}",
                'description' => "{$user->name} exported or generated {$row->exports} reports within "
                    ."{$config['window_minutes']} minutes. Bulk extraction can indicate data staging "
                    .'ahead of exfiltration, or a legitimate reporting cycle.',
                'user_id' => $user->id,
                'ip_address' => $row->ip_address,
                'fingerprint' => 'data_exfiltration:'.$user->id.':'.$since->format('YmdH'),
                'occurred_at' => $row->first_export ? CarbonImmutable::parse($row->first_export) : null,
                'evidence' => [
                    'user' => $user->name,
                    'department' => $user->department,
                    'export_count' => (int) $row->exports,
                    'ip_address' => $row->ip_address,
                    'window_minutes' => $config['window_minutes'],
                ],
                'recommendation' => [
                    'Ask the user to confirm the business reason for the bulk export.',
                    'Review which reports were exported in the audit trail.',
                    'Check whether the export volume matches a known reporting cycle.',
                ],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: dormant account suddenly active
     * ------------------------------------------------------------------ */

    private function detectDormantAccountActivity(): array
    {
        $dormantDays = config('security.detection.dormant_account.dormant_days');
        $since = $this->since(config('security.detection.window_minutes'));

        $logins = DB::table('audit_logs')
            ->where('event', 'auth.login')
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'ip_address', 'created_at']);

        $findings = [];

        foreach ($logins as $login) {
            // Was there any prior login before the dormancy threshold, and none since?
            $priorLogin = DB::table('audit_logs')
                ->where('event', 'auth.login')
                ->where('user_id', $login->user_id)
                ->where('created_at', '<', $login->created_at)
                ->max('created_at');

            if (! $priorLogin) {
                continue;
            }

            $gapDays = CarbonImmutable::parse($priorLogin)
                ->diffInDays(CarbonImmutable::parse($login->created_at));

            if ($gapDays < $dormantDays) {
                continue;
            }

            $user = User::find($login->user_id);

            if (! $user) {
                continue;
            }

            $findings[] = [
                'detector' => 'dormant_account',
                'category' => 'identity',
                'severity' => 'medium',
                'title' => "Dormant account reactivated: {$user->name}",
                'description' => "{$user->name} signed in after ".(int) $gapDays.' days of inactivity. '
                    .'Dormant accounts are a common target because their owners are unlikely to notice misuse.',
                'user_id' => $user->id,
                'ip_address' => $login->ip_address,
                'fingerprint' => 'dormant_account:'.$user->id.':'.$login->id,
                'occurred_at' => CarbonImmutable::parse($login->created_at),
                'evidence' => [
                    'user' => $user->name,
                    'department' => $user->department,
                    'days_dormant' => (int) $gapDays,
                    'previous_login' => $priorLogin,
                    'ip_address' => $login->ip_address,
                ],
                'recommendation' => [
                    'Confirm with the user that they initiated this sign-in.',
                    'Deactivate the account if the owner has left the organisation.',
                    'Review what the account accessed after signing in.',
                ],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: administrative changes outside business hours
     * ------------------------------------------------------------------ */

    private function detectAfterHoursAdminActivity(): array
    {
        $config = config('security.detection.after_hours');

        if (! $config['enabled']) {
            return [];
        }

        $since = $this->since(config('security.detection.window_minutes'));

        /*
         * The event alternatives must be grouped. Left ungrouped, SQL binds AND
         * more tightly than OR, so the `user.access.updated` branch escaped both
         * the scan window and the user filter: every access change ever recorded
         * was re-read and re-evaluated on every five-minute scan, and old
         * findings had their last-detected time refreshed forever instead of
         * ageing out.
         */
        $rows = DB::table('audit_logs')
            ->where(function ($query) {
                $query->where('event', 'user.access.updated')
                    ->orWhere('event', 'like', '%.integrations.%');
            })
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'event', 'ip_address', 'created_at']);

        $findings = [];

        foreach ($rows as $row) {
            $at = CarbonImmutable::parse($row->created_at)->setTimezone(config('app.timezone'));
            $hour = (int) $at->format('G');
            $isWeekend = in_array($at->dayOfWeek, [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY], true);
            $outsideHours = $hour < $config['start_hour'] || $hour >= $config['end_hour'];

            if (! $outsideHours && ! $isWeekend) {
                continue;
            }

            $user = User::find($row->user_id);

            if (! $user) {
                continue;
            }

            $findings[] = [
                'detector' => 'after_hours_admin',
                'category' => 'governance',
                'severity' => 'low',
                'title' => "Administrative change outside business hours by {$user->name}",
                'description' => sprintf(
                    '%s performed "%s" at %s (%s). Administrative changes outside normal hours '
                    .'warrant confirmation that they were planned.',
                    $user->name,
                    $row->event,
                    $at->format('D d M Y, H:i'),
                    config('app.timezone'),
                ),
                'user_id' => $user->id,
                'ip_address' => $row->ip_address,
                'fingerprint' => 'after_hours_admin:audit:'.$row->id,
                'occurred_at' => $at,
                'evidence' => [
                    'user' => $user->name,
                    'event' => $row->event,
                    'local_time' => $at->format('Y-m-d H:i:s'),
                    'weekend' => $isWeekend,
                    'business_hours' => "{$config['start_hour']}:00 - {$config['end_hour']}:00",
                ],
                'recommendation' => [
                    'Confirm the change was part of a planned maintenance window.',
                    'Check the change against your change management records.',
                ],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: deactivated accounts still holding sessions
     * ------------------------------------------------------------------ */

    private function detectRevokedSessionProbing(): array
    {
        $since = $this->since(config('security.detection.window_minutes'));

        $rows = DB::table('audit_logs')
            ->select('user_id', DB::raw('COUNT(*) as attempts'), DB::raw('MIN(created_at) as first_attempt'))
            ->where('event', 'auth.session_revoked')
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->having('attempts', '>=', 3)
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $user = User::find($row->user_id);

            if (! $user) {
                continue;
            }

            $findings[] = [
                'detector' => 'inactive_session_probe',
                'category' => 'identity',
                'severity' => 'medium',
                'title' => "Deactivated account still attempting access: {$user->name}",
                'description' => "A session belonging to the deactivated account {$user->name} was revoked "
                    ."{$row->attempts} times. Something is still actively using this account's credentials or session.",
                'user_id' => $user->id,
                'fingerprint' => 'inactive_session_probe:'.$user->id.':'.$since->format('YmdH'),
                'occurred_at' => $row->first_attempt ? CarbonImmutable::parse($row->first_attempt) : null,
                'evidence' => [
                    'user' => $user->name,
                    'revocations' => (int) $row->attempts,
                ],
                'recommendation' => [
                    'Identify the device or integration still presenting this session.',
                    'Rotate any API credentials the account may have configured.',
                    'Confirm the account was deactivated intentionally.',
                ],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: runtime configuration drift
     * ------------------------------------------------------------------ */

    private function detectConfigurationDrift(): array
    {
        $findings = [];
        $isProduction = app()->environment('production');

        $checks = [
            [
                'id' => 'app_debug',
                'failed' => $isProduction && config('app.debug'),
                'severity' => 'critical',
                'title' => 'Debug mode is enabled in production',
                'description' => 'APP_DEBUG is true in a production environment. Stack traces, environment '
                    .'variables, and database credentials can be exposed to anyone who triggers an error.',
                'recommendation' => [
                    'Set APP_DEBUG=false immediately.',
                    'Clear the configuration cache with php artisan config:cache.',
                    'Review recent error responses for leaked configuration.',
                ],
            ],
            [
                'id' => 'app_env',
                'failed' => ! $isProduction && ! app()->environment(['local', 'testing']),
                'severity' => 'high',
                'title' => 'Unrecognised application environment',
                'description' => 'APP_ENV is set to "'.app()->environment().'", which is neither production '
                    .'nor a recognised development environment. Security policy is selected by environment.',
                'recommendation' => ['Set APP_ENV to production, local, or testing.'],
            ],
            [
                'id' => 'session_encryption',
                'failed' => ! config('session.encrypt'),
                'severity' => 'high',
                'title' => 'Session payloads are not encrypted',
                'description' => 'Session encryption is disabled, so session contents are stored in readable '
                    .'form in the session store.',
                'recommendation' => ['Set SESSION_ENCRYPT=true and re-cache configuration.'],
            ],
            [
                'id' => 'session_secure_cookie',
                'failed' => $isProduction && ! config('session.secure'),
                'severity' => 'high',
                'title' => 'Session cookie is not restricted to HTTPS',
                'description' => 'The session cookie lacks the Secure flag in production, so it can be '
                    .'transmitted over plaintext HTTP.',
                'recommendation' => ['Set SESSION_SECURE_COOKIE=true in production.'],
            ],
            [
                'id' => 'integration_https',
                'failed' => ! config('integrations.require_https'),
                'severity' => 'high',
                'title' => 'Integrations are permitted over plaintext HTTP',
                'description' => 'INTEGRATION_REQUIRE_HTTPS is disabled, so outbound integration traffic '
                    .'including API credentials may travel unencrypted.',
                'recommendation' => ['Set INTEGRATION_REQUIRE_HTTPS=true.'],
            ],
            [
                'id' => 'integration_private_networks',
                'failed' => $isProduction && config('integrations.allow_private_networks'),
                'severity' => 'medium',
                'title' => 'Integrations may reach private network ranges',
                'description' => 'The SSRF guard is permitted to resolve private and reserved IP ranges. '
                    .'This widens the blast radius if an attacker controls an integration URL.',
                'recommendation' => [
                    'Set INTEGRATION_ALLOW_PRIVATE_NETWORKS=false unless an approved internal integration requires it.',
                ],
            ],
            [
                'id' => 'session_lifetime',
                'failed' => (int) config('session.lifetime') > 1440,
                'severity' => 'medium',
                'title' => 'Session lifetime exceeds 24 hours',
                'description' => 'Sessions remain valid for '.config('session.lifetime').' minutes. Long-lived '
                    .'sessions increase the window in which a stolen session remains usable.',
                'recommendation' => ['Reduce SESSION_LIFETIME to your policy maximum, commonly 480 minutes.'],
            ],
        ];

        foreach ($checks as $check) {
            if (! $check['failed']) {
                continue;
            }

            $findings[] = [
                'detector' => 'configuration_drift',
                'category' => 'compliance',
                'severity' => $check['severity'],
                'title' => $check['title'],
                'description' => $check['description'],
                'fingerprint' => 'configuration_drift:'.$check['id'],
                'evidence' => [
                    'check' => $check['id'],
                    'environment' => app()->environment(),
                ],
                'recommendation' => $check['recommendation'],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: credential and secret exposure
     * ------------------------------------------------------------------ */

    private function detectCredentialExposure(): array
    {
        $findings = [];

        // Integrations configured over plaintext HTTP.
        $insecure = DB::table('data_sources')
            ->whereNotNull('base_url')
            ->where('base_url', 'like', 'http://%')
            ->get(['id', 'name', 'base_url']);

        foreach ($insecure as $source) {
            $findings[] = [
                'detector' => 'credential_exposure',
                'category' => 'data',
                'severity' => 'high',
                'title' => "Integration \"{$source->name}\" uses plaintext HTTP",
                'description' => "The data source \"{$source->name}\" is configured with an http:// base URL. "
                    .'API credentials sent to this endpoint travel unencrypted and can be intercepted.',
                'fingerprint' => 'credential_exposure:http:'.$source->id,
                'evidence' => [
                    'data_source_id' => $source->id,
                    'name' => $source->name,
                    'base_url' => $source->base_url,
                ],
                'recommendation' => [
                    'Change the base URL to https://.',
                    'Rotate the API credentials for this integration, as they may already be compromised.',
                ],
            ];
        }

        // Accounts holding privileged roles without a recorded sign-in.
        $staleAdmins = DB::table('users')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.name', ['administrator', 'security_officer'])
            ->where('users.is_active', true)
            ->whereNull('users.last_login_at')
            ->distinct()
            ->get(['users.id', 'users.name', 'users.email', 'roles.name as role']);

        foreach ($staleAdmins as $account) {
            $findings[] = [
                'detector' => 'credential_exposure',
                'category' => 'identity',
                'severity' => 'medium',
                'title' => "Privileged account has never signed in: {$account->name}",
                'description' => "The active account \"{$account->name}\" holds the {$account->role} role but has "
                    .'no recorded sign-in. Unused privileged accounts are a standing risk and are often '
                    .'forgotten during access reviews.',
                'user_id' => $account->id,
                'fingerprint' => 'credential_exposure:unused_admin:'.$account->id,
                'evidence' => [
                    'user' => $account->name,
                    'role' => $account->role,
                ],
                'recommendation' => [
                    'Confirm the account is still required.',
                    'Deactivate it or remove the privileged role if it is not in use.',
                ],
            ];
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     * Detector: second-factor coverage gaps
     * ------------------------------------------------------------------ */

    private function detectTwoFactorGaps(): array
    {
        if (! config('security.two_factor.enabled')) {
            return [[
                'detector' => 'two_factor_gap',
                'category' => 'compliance',
                'severity' => 'critical',
                'title' => 'Multi-factor authentication is disabled',
                'description' => 'MFA_ENABLED is false, so accounts authenticate with a password alone. '
                    .'This is the highest-impact control for an internet-reachable deployment.',
                'fingerprint' => 'two_factor_gap:disabled',
                'evidence' => ['config' => 'security.two_factor.enabled'],
                'recommendation' => [
                    'Set MFA_ENABLED=true and clear the configuration cache.',
                    'Confirm privileged accounts enrol immediately afterwards.',
                ],
            ]];
        }

        $findings = [];

        // Privileged accounts without a second factor are the priority.
        $unenrolled = DB::table('users')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.name', ['administrator', 'security_officer'])
            ->where('users.is_active', true)
            ->whereNull('users.two_factor_confirmed_at')
            ->distinct()
            ->get(['users.id', 'users.name', 'roles.name as role']);

        foreach ($unenrolled as $account) {
            $findings[] = [
                'detector' => 'two_factor_gap',
                'category' => 'identity',
                'severity' => 'high',
                'title' => "Privileged account without a second factor: {$account->name}",
                'description' => "The active account \"{$account->name}\" holds the {$account->role} role but has "
                    .'not enrolled in multi-factor authentication. Its session is confined to the enrolment '
                    .'flow, but the account remains a password-only target.',
                'user_id' => $account->id,
                'fingerprint' => 'two_factor_gap:user:'.$account->id,
                'evidence' => ['user' => $account->name, 'role' => $account->role],
                'recommendation' => [
                    'Ask the account owner to complete enrolment.',
                    'Deactivate the account if it is not in active use.',
                ],
            ];
        }

        // Users who have exhausted their recovery codes will be locked out if
        // they lose their authenticator.
        $exhausted = DB::table('users')
            ->where('is_active', true)
            ->whereNotNull('two_factor_confirmed_at')
            ->whereNotNull('two_factor_recovery_codes')
            ->get(['id', 'name', 'two_factor_recovery_codes']);

        foreach ($exhausted as $account) {
            try {
                $codes = json_decode(decrypt($account->two_factor_recovery_codes), true) ?? [];
            } catch (Throwable) {
                continue;
            }

            if (count($codes) > 1) {
                continue;
            }

            $findings[] = [
                'detector' => 'two_factor_gap',
                'category' => 'identity',
                'severity' => 'low',
                'title' => "Recovery codes nearly exhausted: {$account->name}",
                'description' => count($codes).' recovery code(s) remain for this account. If the authenticator '
                    .'device is lost, the user will need an administrator to reset their second factor.',
                'user_id' => $account->id,
                'fingerprint' => 'two_factor_gap:recovery:'.$account->id,
                'evidence' => ['user' => $account->name, 'remaining' => count($codes)],
                'recommendation' => ['Ask the user to regenerate their recovery codes.'],
            ];
        }

        return $findings;
    }

    /**
     * Events that still need to be alerted on, filtered by minimum severity.
     */
    public function pendingAlerts(): Collection
    {
        $minimum = config('security.alerts.minimum_severity', 'high');
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];
        $threshold = $order[$minimum] ?? 1;

        $severities = collect($order)
            ->filter(fn (int $rank) => $rank <= $threshold)
            ->keys()
            ->all();

        return SecurityEvent::query()
            ->where('alerted', false)
            ->whereIn('severity', $severities)
            ->unresolved()
            ->orderByRaw(self::severityOrder())
            ->orderByDesc('last_detected_at')
            ->get();
    }
}
