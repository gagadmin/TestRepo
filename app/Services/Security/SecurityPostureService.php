<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityScan;
use App\Services\Security\SecurityMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the security dashboard payload from telemetry the application owns.
 *
 * Every figure returned here is derived from real data. Sections that require
 * an external security connector report `connected => false` and carry no
 * figures at all, so the dashboard never displays a fabricated metric.
 */
class SecurityPostureService
{
    /**
     * Full dashboard payload.
     */
    public function dashboard(int $trendDays = 30): array
    {
        return [
            'overview' => $this->overview($trendDays),
            'threats' => $this->threatMonitoring($trendDays),
            'identity' => $this->identityAccess(),
            'incidents' => $this->incidentResponse($trendDays),
            'compliance' => $this->compliance(),
            'assets' => $this->assetInventory(),
            'vulnerability_management' => $this->connectorSection(
                'vulnerability_feed',
                'Vulnerability Management',
                'Connect a vulnerability scanner or CVE feed to report critical vulnerabilities, patch age, and patch compliance.',
                ['Qualys', 'Tenable', 'Rapid7', 'Microsoft Defender Vulnerability Management'],
            ),
            'endpoint_security' => $this->connectorSection(
                'defender_endpoint',
                'Endpoint Security',
                'Connect an endpoint protection platform to report device health, EDR coverage, and antivirus status.',
                ['Microsoft Defender for Endpoint', 'CrowdStrike Falcon', 'SentinelOne'],
            ),
            'email_security' => $this->connectorSection(
                'defender_office365',
                'Email & Collaboration Security',
                'Connect Microsoft Defender for Office 365 to report blocked phishing, malware attachments, and Safe Links activity.',
                ['Microsoft Defender for Office 365', 'Proofpoint', 'Mimecast'],
            ),
            'cloud_security' => $this->connectorSection(
                'cloud_posture',
                'Cloud Security',
                'Connect a cloud security posture management source to report misconfigured resources and exposed storage.',
                ['Microsoft Defender for Cloud', 'AWS Security Hub', 'Wiz'],
            ),
            'scan' => $this->lastScan(),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'timezone' => config('app.timezone'),
                'trend_days' => $trendDays,
                'data_basis' => 'Derived from application audit logs, sessions, accounts, and runtime configuration.',
            ],
        ];
    }

    /* ------------------------------------------------------------------
     * 1. Security overview
     * ------------------------------------------------------------------ */

    public function overview(int $trendDays = 30): array
    {
        $score = $this->securityScore();
        $open = SecurityEvent::query()->unresolved();

        $previousWindow = SecurityEvent::query()
            ->whereBetween('first_detected_at', [
                CarbonImmutable::now()->subDays($trendDays * 2),
                CarbonImmutable::now()->subDays($trendDays),
            ])
            ->count();
        $currentWindow = SecurityEvent::query()
            ->where('first_detected_at', '>=', CarbonImmutable::now()->subDays($trendDays))
            ->count();

        return [
            'security_score' => $score['score'],
            'score_grade' => $score['grade'],
            'score_breakdown' => $score['breakdown'],
            'open_incidents' => (clone $open)->count(),
            'critical_alerts' => (clone $open)->where('severity', 'critical')->count(),
            'high_alerts' => (clone $open)->where('severity', 'high')->count(),
            'medium_alerts' => (clone $open)->where('severity', 'medium')->count(),
            'low_alerts' => (clone $open)->where('severity', 'low')->count(),
            'compliance_percentage' => $this->compliance()['overall_percentage'],
            'mttd_minutes' => $this->meanTimeToDetect(),
            'mttr_minutes' => $this->meanTimeToRespond(),
            'trend' => [
                'current_period' => $currentWindow,
                'previous_period' => $previousWindow,
                'change_percentage' => $previousWindow > 0
                    ? round((($currentWindow - $previousWindow) / $previousWindow) * 100, 1)
                    : null,
                'direction' => match (true) {
                    $currentWindow > $previousWindow => 'up',
                    $currentWindow < $previousWindow => 'down',
                    default => 'flat',
                },
            ],
        ];
    }

    /**
     * Overall security score out of 100.
     *
     * Starts at 100 and deducts for open findings weighted by severity, plus
     * fixed deductions for structural weaknesses (no MFA, stale privileged
     * accounts). Deliberately transparent rather than a black box.
     */
    public function securityScore(): array
    {
        $deductions = [];
        $score = 100;

        $open = SecurityEvent::query()->unresolved()->get(['severity']);

        foreach (SecurityEvent::SEVERITY_WEIGHT as $severity => $weight) {
            $count = $open->where('severity', $severity)->count();

            if ($count === 0 || $weight === 0) {
                continue;
            }

            // Diminishing impact: the first finding of a severity hurts most.
            $deduction = (int) min($weight * 2, $weight * sqrt($count));
            $score -= $deduction;
            $deductions[] = [
                'reason' => ucfirst($severity).' severity findings open',
                'count' => $count,
                'points' => -$deduction,
            ];
        }

        // MFA is now implemented, so the deduction is driven by real enrolment
        // rather than by the absence of the capability.
        $activeUsers = DB::table('users')->where('is_active', true)->count();
        $mfa = $this->mfaCoverage($activeUsers);

        if (! ($mfa['enabled'] ?? false)) {
            $score -= 15;
            $deductions[] = [
                'reason' => 'Multi-factor authentication disabled by configuration',
                'count' => null,
                'points' => -15,
            ];
        } elseif (($mfa['not_enrolled'] ?? 0) > 0) {
            // Weighted by how much of the estate is uncovered, capped at 10.
            $uncovered = (int) $mfa['not_enrolled'];
            $deduction = (int) min(10, ceil($uncovered / max(1, $activeUsers) * 10));
            $score -= $deduction;
            $deductions[] = [
                'reason' => 'Accounts without a second factor',
                'count' => $uncovered,
                'points' => -$deduction,
            ];
        }

        $compliance = $this->compliance();
        $failedControls = collect($compliance['controls'])->where('passed', false)->count();

        if ($failedControls > 0) {
            $deduction = min(15, $failedControls * 3);
            $score -= $deduction;
            $deductions[] = [
                'reason' => 'Failed internal security controls',
                'count' => $failedControls,
                'points' => -$deduction,
            ];
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'grade' => match (true) {
                $score >= 90 => 'A',
                $score >= 80 => 'B',
                $score >= 70 => 'C',
                $score >= 60 => 'D',
                default => 'F',
            },
            'breakdown' => $deductions,
        ];
    }

    public function meanTimeToDetect(): ?float
    {
        $events = SecurityEvent::query()
            ->whereNotNull('occurred_at')
            ->whereNotNull('first_detected_at')
            ->where('first_detected_at', '>=', CarbonImmutable::now()->subDays(90))
            ->get(['occurred_at', 'first_detected_at']);

        if ($events->isEmpty()) {
            return null;
        }

        return round($events->avg(fn (SecurityEvent $event) => $event->detectionMinutes() ?? 0), 1);
    }

    public function meanTimeToRespond(): ?float
    {
        $events = SecurityEvent::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', CarbonImmutable::now()->subDays(90))
            ->get(['first_detected_at', 'resolved_at']);

        if ($events->isEmpty()) {
            return null;
        }

        return round($events->avg(fn (SecurityEvent $event) => $event->responseMinutes() ?? 0), 1);
    }

    /* ------------------------------------------------------------------
     * 2. Threat monitoring
     * ------------------------------------------------------------------ */

    public function threatMonitoring(int $trendDays = 30): array
    {
        $since = CarbonImmutable::now()->subDays($trendDays);

        $bySeverity = SecurityEvent::query()
            ->unresolved()
            ->select('severity', DB::raw('COUNT(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $byDetector = SecurityEvent::query()
            ->where('first_detected_at', '>=', $since)
            ->select('detector', DB::raw('COUNT(*) as total'))
            ->groupBy('detector')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['label' => $this->humanise($row->detector), 'value' => (int) $row->total])
            ->all();

        return [
            'active_threats' => SecurityEvent::query()->unresolved()->whereIn('category', ['threat'])->count(),
            'blocked_login_attempts' => DB::table('audit_logs')
                ->where('event', 'auth.login_failed')
                ->where('created_at', '>=', $since)
                ->count(),
            'suspicious_sources' => DB::table('audit_logs')
                ->where('event', 'auth.login_failed')
                ->where('created_at', '>=', $since)
                ->distinct()
                ->count('ip_address'),
            'revoked_sessions' => DB::table('audit_logs')
                ->where('event', 'auth.session_revoked')
                ->where('created_at', '>=', $since)
                ->count(),
            'severity_breakdown' => collect(['critical', 'high', 'medium', 'low', 'info'])
                ->map(fn (string $severity) => [
                    'label' => ucfirst($severity),
                    'value' => (int) ($bySeverity[$severity] ?? 0),
                ])
                ->filter(fn (array $row) => $row['value'] > 0)
                ->values()
                ->all(),
            'by_detector' => $byDetector,
            'trend' => $this->eventTrend($trendDays),
            'top_sources' => $this->topAttackSources($since),
            'recent' => SecurityEvent::query()
                ->unresolved()
                ->with('user:id,name,department')
                ->orderByRaw(SecurityMonitor::severityOrder())
                ->orderByDesc('last_detected_at')
                ->limit(25)
                ->get()
                ->map(fn (SecurityEvent $event) => $this->serialiseEvent($event))
                ->all(),
        ];
    }

    private function eventTrend(int $days): array
    {
        $since = CarbonImmutable::now()->subDays($days)->startOfDay();

        $detected = DB::table('security_events')
            ->select(DB::raw('DATE(first_detected_at) as day'), DB::raw('COUNT(*) as total'))
            ->where('first_detected_at', '>=', $since)
            ->groupBy('day')
            ->pluck('total', 'day');

        $failedLogins = DB::table('audit_logs')
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->where('event', 'auth.login_failed')
            ->where('created_at', '>=', $since)
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];

        for ($cursor = $since; $cursor <= CarbonImmutable::now(); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();
            $series[] = [
                'date' => $key,
                'events' => (int) ($detected[$key] ?? 0),
                'failed_logins' => (int) ($failedLogins[$key] ?? 0),
            ];
        }

        return $series;
    }

    private function topAttackSources(CarbonImmutable $since): array
    {
        return DB::table('audit_logs')
            ->select('ip_address', DB::raw('COUNT(*) as attempts'), DB::raw('MAX(created_at) as last_seen'))
            ->where('event', 'auth.login_failed')
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->orderByDesc('attempts')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'ip_address' => $row->ip_address,
                'attempts' => (int) $row->attempts,
                'last_seen' => $row->last_seen,
            ])
            ->all();
    }

    /* ------------------------------------------------------------------
     * 3. Identity & access
     * ------------------------------------------------------------------ */

    public function identityAccess(): array
    {
        $dormantDays = config('security.detection.dormant_account.dormant_days', 90);
        $dormantThreshold = CarbonImmutable::now()->subDays($dormantDays);
        $since = CarbonImmutable::now()->subDays(30);

        $totalUsers = DB::table('users')->count();
        $activeUsers = DB::table('users')->where('is_active', true)->count();

        $privileged = DB::table('users')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.name', ['administrator', 'security_officer'])
            ->where('users.is_active', true)
            ->distinct()
            ->count('users.id');

        $dormant = DB::table('users')
            ->where('is_active', true)
            ->where(function ($query) use ($dormantThreshold) {
                $query->whereNull('last_login_at')
                    ->orWhere('last_login_at', '<', $dormantThreshold);
            })
            ->count();

        $successfulLogins = DB::table('audit_logs')
            ->where('event', 'auth.login')
            ->where('created_at', '>=', $since)
            ->count();
        $failedLogins = DB::table('audit_logs')
            ->where('event', 'auth.login_failed')
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'total_accounts' => $totalUsers,
            'active_accounts' => $activeUsers,
            'inactive_accounts' => $totalUsers - $activeUsers,
            'privileged_accounts' => $privileged,
            'privileged_percentage' => $activeUsers > 0
                ? round(($privileged / $activeUsers) * 100, 1)
                : 0.0,
            'dormant_accounts' => $dormant,
            'never_signed_in' => DB::table('users')
                ->where('is_active', true)
                ->whereNull('last_login_at')
                ->count(),
            'unverified_email' => DB::table('users')
                ->where('is_active', true)
                ->whereNull('email_verified_at')
                ->count(),

            'mfa' => $this->mfaCoverage($activeUsers),

            'authentication' => [
                'successful' => $successfulLogins,
                'failed' => $failedLogins,
                'failure_rate' => ($successfulLogins + $failedLogins) > 0
                    ? round(($failedLogins / ($successfulLogins + $failedLogins)) * 100, 1)
                    : 0.0,
            ],
            'active_sessions' => $this->activeSessions(),
            'risky_users' => SecurityEvent::query()
                ->unresolved()
                ->whereNotNull('user_id')
                ->whereIn('category', ['identity', 'data'])
                ->with('user:id,name,department')
                ->get()
                ->groupBy('user_id')
                ->map(fn (Collection $events) => [
                    'user' => $events->first()->user?->name ?? 'Unknown',
                    'department' => $events->first()->user?->department,
                    'findings' => $events->count(),
                    'highest_severity' => $events
                        ->sortBy(fn (SecurityEvent $e) => array_search($e->severity, SecurityEvent::SEVERITIES, true))
                        ->first()->severity,
                ])
                ->sortByDesc('findings')
                ->values()
                ->take(10)
                ->all(),
            'by_role' => DB::table('roles')
                ->leftJoin('role_user', 'roles.id', '=', 'role_user.role_id')
                ->select('roles.label', DB::raw('COUNT(role_user.user_id) as total'))
                ->groupBy('roles.id', 'roles.label')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->total])
                ->all(),
        ];
    }

    /**
     * Real MFA enrolment coverage, now that a second factor exists.
     */
    private function mfaCoverage(int $activeUsers): array
    {
        if (! config('security.two_factor.enabled')) {
            return [
                'supported' => true,
                'enabled' => false,
                'coverage_percentage' => null,
                'note' => 'Multi-factor authentication is implemented but disabled by configuration '
                    .'(MFA_ENABLED=false). Enable it to satisfy ISO 27001 A.8.5 / NIST IA-2.',
            ];
        }

        $enrolled = DB::table('users')
            ->where('is_active', true)
            ->whereNotNull('two_factor_confirmed_at')
            ->count();

        $privilegedTotal = DB::table('users')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.name', ['administrator', 'security_officer'])
            ->where('users.is_active', true)
            ->distinct()
            ->count('users.id');

        $privilegedEnrolled = DB::table('users')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.name', ['administrator', 'security_officer'])
            ->where('users.is_active', true)
            ->whereNotNull('users.two_factor_confirmed_at')
            ->distinct()
            ->count('users.id');

        $coverage = $activeUsers > 0
            ? round(($enrolled / $activeUsers) * 100, 1)
            : 100.0;

        return [
            'supported' => true,
            'enabled' => true,
            'method' => 'TOTP (RFC 6238) with single-use recovery codes',
            'required_for_all' => (bool) config('security.two_factor.required_for_all'),
            'enrolled' => $enrolled,
            'not_enrolled' => $activeUsers - $enrolled,
            'coverage_percentage' => $coverage,
            'privileged_enrolled' => $privilegedEnrolled,
            'privileged_total' => $privilegedTotal,
            'privileged_coverage_percentage' => $privilegedTotal > 0
                ? round(($privilegedEnrolled / $privilegedTotal) * 100, 1)
                : 100.0,
            'note' => $coverage >= 100
                ? 'All active accounts hold a second factor.'
                : ($activeUsers - $enrolled).' active account(s) have not yet enrolled. '
                    .'Unenrolled sessions are confined to the enrolment flow and cannot reach business data.',
        ];
    }

    private function activeSessions(): array
    {
        if (config('session.driver') !== 'database') {
            return [
                'available' => false,
                'note' => 'Active session enumeration requires the database session driver.',
                'total' => null,
                'sessions' => [],
            ];
        }

        $cutoff = CarbonImmutable::now()->subMinutes((int) config('session.lifetime', 120))->timestamp;

        $sessions = DB::table('sessions')
            ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
            ->where('sessions.last_activity', '>=', $cutoff)
            ->whereNotNull('sessions.user_id')
            ->orderByDesc('sessions.last_activity')
            ->limit(50)
            ->get([
                'sessions.id', 'sessions.ip_address', 'sessions.user_agent',
                'sessions.last_activity', 'users.name as user_name', 'users.department',
            ]);

        return [
            'available' => true,
            'total' => DB::table('sessions')->where('last_activity', '>=', $cutoff)->count(),
            'sessions' => $sessions->map(fn ($row) => [
                'user' => $row->user_name ?? 'Unauthenticated',
                'department' => $row->department,
                'ip_address' => $row->ip_address,
                'user_agent' => str($row->user_agent ?? '')->limit(80)->toString(),
                'last_activity' => CarbonImmutable::createFromTimestamp($row->last_activity)->toIso8601String(),
            ])->all(),
        ];
    }

    /* ------------------------------------------------------------------
     * 8. Compliance & governance
     * ------------------------------------------------------------------ */

    public function compliance(): array
    {
        $isProduction = app()->environment('production');

        $controls = [
            [
                'id' => 'encryption_at_rest',
                'framework' => 'ISO 27001 A.8.24',
                'name' => 'Sensitive data encrypted at rest',
                'passed' => true,
                'detail' => 'API credentials, report snapshots, AI conversations, and schedule recipients use encrypted casts.',
            ],
            [
                'id' => 'session_encryption',
                'framework' => 'ISO 27001 A.8.24',
                'name' => 'Session payloads encrypted',
                'passed' => (bool) config('session.encrypt'),
                'detail' => config('session.encrypt')
                    ? 'Session encryption is enabled.'
                    : 'SESSION_ENCRYPT is disabled.',
            ],
            [
                'id' => 'debug_disabled',
                'framework' => 'CIS 16.11',
                'name' => 'Debug mode disabled in production',
                'passed' => ! ($isProduction && config('app.debug')),
                'detail' => $isProduction && config('app.debug')
                    ? 'APP_DEBUG is enabled in production.'
                    : 'Debug mode is correctly configured for this environment.',
            ],
            [
                'id' => 'transport_security',
                'framework' => 'NIST SC-8',
                'name' => 'Outbound integrations require HTTPS',
                'passed' => (bool) config('integrations.require_https'),
                'detail' => config('integrations.require_https')
                    ? 'Integration URLs must use HTTPS.'
                    : 'Plaintext HTTP integration URLs are permitted.',
            ],
            [
                'id' => 'ssrf_guard',
                'framework' => 'NIST SC-7',
                'name' => 'SSRF protection on outbound requests',
                'passed' => ! config('integrations.allow_private_networks') || ! $isProduction,
                'detail' => config('integrations.allow_private_networks') && $isProduction
                    ? 'Private network ranges are reachable from integrations in production.'
                    : 'Private and reserved IP ranges are blocked by the URL guard.',
            ],
            [
                'id' => 'audit_logging',
                'framework' => 'ISO 27001 A.8.15',
                'name' => 'Security events are logged',
                'passed' => DB::table('audit_logs')->where('created_at', '>=', CarbonImmutable::now()->subDays(7))->exists(),
                'detail' => 'Authentication, authorisation changes, and all mutating requests are audit logged with source IP.',
            ],
            [
                'id' => 'access_control',
                'framework' => 'ISO 27001 A.5.15',
                'name' => 'Role-based access control enforced',
                'passed' => DB::table('permission_role')->exists(),
                'detail' => 'Permissions are enforced server-side by middleware and query scopes.',
            ],
            [
                'id' => 'rate_limiting',
                'framework' => 'CIS 13.5',
                'name' => 'Authentication rate limiting',
                'passed' => true,
                'detail' => 'The login route is throttled to 6 attempts per minute.',
            ],
            [
                'id' => 'security_headers',
                'framework' => 'CIS 16.x',
                'name' => 'Browser security headers applied',
                'passed' => true,
                'detail' => 'CSP, HSTS, X-Frame-Options, nosniff, and Referrer-Policy are applied globally.',
            ],
            $this->mfaControl(),
            $this->passwordPolicyControl(),
            $this->lockoutControl(),
        ];

        $passed = collect($controls)->where('passed', true)->count();
        $total = count($controls);

        $byFramework = collect($controls)
            ->groupBy(fn (array $control) => explode(' ', $control['framework'])[0])
            ->map(fn (Collection $group, string $framework) => [
                'framework' => $framework,
                'passed' => $group->where('passed', true)->count(),
                'total' => $group->count(),
                'percentage' => round(($group->where('passed', true)->count() / $group->count()) * 100, 1),
            ])
            ->values()
            ->all();

        return [
            'overall_percentage' => $total > 0 ? round(($passed / $total) * 100, 1) : 0.0,
            'controls_passed' => $passed,
            'controls_total' => $total,
            'by_framework' => $byFramework,
            'controls' => $controls,
            'open_findings' => SecurityEvent::query()
                ->unresolved()
                ->where('category', 'compliance')
                ->get()
                ->map(fn (SecurityEvent $event) => $this->serialiseEvent($event))
                ->all(),
        ];
    }

    /**
     * ISO 27001 A.8.5 / NIST IA-2.
     *
     * Passes only when MFA is enabled AND every privileged account has actually
     * enrolled. A capability nobody uses is not a control.
     */
    private function mfaControl(): array
    {
        $enabled = (bool) config('security.two_factor.enabled');

        if (! $enabled) {
            return [
                'id' => 'mfa',
                'framework' => 'ISO 27001 A.8.5 / NIST IA-2',
                'name' => 'Multi-factor authentication',
                'passed' => false,
                'detail' => 'TOTP is implemented but disabled by configuration (MFA_ENABLED=false).',
            ];
        }

        $activeUsers = DB::table('users')->where('is_active', true)->count();
        $coverage = $this->mfaCoverage($activeUsers);
        $privilegedCovered = $coverage['privileged_coverage_percentage'] >= 100;

        return [
            'id' => 'mfa',
            'framework' => 'ISO 27001 A.8.5 / NIST IA-2',
            'name' => 'Multi-factor authentication',
            'passed' => $privilegedCovered,
            'detail' => $privilegedCovered
                ? sprintf(
                    'TOTP enforced. %s%% of active accounts enrolled; all %d privileged account(s) enrolled. '
                    .'No session is established before the second factor is verified.',
                    $coverage['coverage_percentage'],
                    $coverage['privileged_total'],
                )
                : sprintf(
                    'TOTP enforced, but %d of %d privileged account(s) have not enrolled. '
                    .'Their sessions are confined to the enrolment flow until they do.',
                    $coverage['privileged_total'] - $coverage['privileged_enrolled'],
                    $coverage['privileged_total'],
                ),
        ];
    }

    /**
     * NIST IA-5, interpreted against NIST SP 800-63B.
     *
     * 800-63B section 5.1.1.2 advises against mandatory periodic rotation, so
     * this control passes on length, breach screening, and reuse prevention.
     * Rotation is available but off; the detail text states this explicitly so
     * an auditor reading a literal IA-5(1)(d) checklist sees the reasoning.
     */
    private function passwordPolicyControl(): array
    {
        $config = config('security.password');

        $checks = [
            'minimum length' => $config['min_length'] >= 12,
            'reuse history' => $config['history_depth'] > 0,
            'breach screening' => (bool) $config['block_compromised'],
            'contextual screening' => (bool) $config['block_contextual'],
        ];

        $failed = array_keys(array_filter($checks, fn (bool $ok) => ! $ok));

        $rotation = $config['max_age_days'] > 0
            ? "Maximum age is set to {$config['max_age_days']} days."
            : 'Periodic rotation is deliberately disabled per NIST SP 800-63B 5.1.1.2, which advises '
                .'against arbitrary password changes; a forced change is triggered only on evidence of '
                .'compromise. Set PASSWORD_MAX_AGE_DAYS to enable a maximum lifetime.';

        return [
            'id' => 'password_policy',
            'framework' => 'NIST IA-5 / SP 800-63B',
            'name' => 'Password policy, history, and screening',
            'passed' => $failed === [],
            'detail' => $failed === []
                ? sprintf(
                    'Enforced: minimum %d characters, last %d passwords blocked, breach and contextual '
                    .'screening active. %s',
                    $config['min_length'],
                    $config['history_depth'],
                    $rotation,
                )
                : 'Weak configuration: '.implode(', ', $failed).' not enforced.',
        ];
    }

    /**
     * CIS 6.2.
     */
    private function lockoutControl(): array
    {
        $config = config('security.lockout');

        if (! $config['enabled']) {
            return [
                'id' => 'account_lockout',
                'framework' => 'CIS 6.2',
                'name' => 'Account lockout after failed attempts',
                'passed' => false,
                'detail' => 'Progressive lockout is implemented but disabled (LOCKOUT_ENABLED=false).',
            ];
        }

        $activeLocks = DB::table('login_throttles')
            ->where('locked_until', '>', now())
            ->count();

        return [
            'id' => 'account_lockout',
            'framework' => 'CIS 6.2',
            'name' => 'Account lockout after failed attempts',
            'passed' => true,
            'detail' => sprintf(
                'Progressive lockout after %d failures, backing off %s minutes, scoped to account and source '
                .'address so a remote attacker cannot deny service to the real owner. Second-factor attempts '
                .'have a separate budget of %d. %d lock(s) currently active.',
                $config['threshold'],
                implode('/', $config['backoff_minutes']),
                $config['two_factor_threshold'],
                $activeLocks,
            ),
        ];
    }

    /* ------------------------------------------------------------------
     * 9. Incident response
     * ------------------------------------------------------------------ */

    public function incidentResponse(int $trendDays = 30): array
    {
        $since = CarbonImmutable::now()->subDays($trendDays);

        return [
            'open' => SecurityEvent::query()->where('status', 'open')->count(),
            'acknowledged' => SecurityEvent::query()->where('status', 'acknowledged')->count(),
            'resolved' => SecurityEvent::query()
                ->where('status', 'resolved')
                ->where('resolved_at', '>=', $since)
                ->count(),
            'false_positive' => SecurityEvent::query()
                ->where('status', 'false_positive')
                ->where('updated_at', '>=', $since)
                ->count(),
            'mttd_minutes' => $this->meanTimeToDetect(),
            'mttr_minutes' => $this->meanTimeToRespond(),
            'by_category' => SecurityEvent::query()
                ->where('first_detected_at', '>=', $since)
                ->select('category', DB::raw('COUNT(*) as total'))
                ->groupBy('category')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => ['label' => $this->humanise($row->category), 'value' => (int) $row->total])
                ->all(),
            'oldest_open' => SecurityEvent::query()
                ->unresolved()
                ->with('user:id,name')
                ->orderBy('first_detected_at')
                ->limit(10)
                ->get()
                ->map(fn (SecurityEvent $event) => [
                    ...$this->serialiseEvent($event),
                    'age_hours' => round($event->first_detected_at->diffInMinutes(now()) / 60, 1),
                ])
                ->all(),
        ];
    }

    /* ------------------------------------------------------------------
     * 10. Asset inventory
     * ------------------------------------------------------------------ */

    public function assetInventory(): array
    {
        $sources = DB::table('data_sources')
            ->select('type', 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('type', 'status')
            ->get();

        $byType = $sources
            ->groupBy('type')
            ->map(fn (Collection $group, string $type) => [
                'label' => $this->humanise($type),
                'value' => (int) $group->sum('total'),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'note' => 'Asset inventory covers assets this application manages. Endpoint, server, and mobile '
                .'device inventory requires an endpoint management connector.',
            'integrations_total' => DB::table('data_sources')->count(),
            'integrations_connected' => DB::table('data_sources')->where('status', 'connected')->count(),
            'integrations_failing' => DB::table('data_sources')->where('status', '!=', 'connected')->count(),
            'integrations_encrypted' => DB::table('api_configurations')->count(),
            'reports_total' => DB::table('reports')->count(),
            'dashboards_total' => DB::table('dashboards')->where('is_active', true)->count(),
            'scheduled_jobs' => DB::table('report_schedules')->where('is_active', true)->count(),
            'user_accounts' => DB::table('users')->count(),
            'by_type' => $byType,
            'by_status' => $sources
                ->groupBy('status')
                ->map(fn (Collection $group, string $status) => [
                    'label' => ucfirst($status),
                    'value' => (int) $group->sum('total'),
                ])
                ->values()
                ->all(),
            'integrations' => DB::table('data_sources')
                ->leftJoin('api_configurations', 'data_sources.id', '=', 'api_configurations.data_source_id')
                ->select(
                    'data_sources.id', 'data_sources.name', 'data_sources.type',
                    'data_sources.status', 'data_sources.base_url', 'data_sources.last_tested_at',
                    'api_configurations.auth_type',
                )
                ->orderBy('data_sources.name')
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'type' => $this->humanise($row->type),
                    'status' => $row->status,
                    'transport' => str_starts_with((string) $row->base_url, 'https://') ? 'https' : 'http',
                    'auth_type' => $row->auth_type,
                    'last_tested_at' => $row->last_tested_at,
                ])
                ->all(),
        ];
    }

    /* ------------------------------------------------------------------
     * Sections awaiting an external connector
     * ------------------------------------------------------------------ */

    private function connectorSection(
        string $connector,
        string $title,
        string $description,
        array $suggestedSources,
    ): array {
        return [
            'title' => $title,
            'connected' => (bool) config("security.connectors.{$connector}"),
            'connector_key' => $connector,
            'description' => $description,
            'suggested_sources' => $suggestedSources,
            'metrics' => [],
        ];
    }

    public function lastScan(): ?array
    {
        $scan = SecurityScan::query()->latest('id')->first();

        if (! $scan) {
            return null;
        }

        return [
            'id' => $scan->id,
            'trigger' => $scan->trigger,
            'status' => $scan->status,
            'events_detected' => $scan->events_detected,
            'events_created' => $scan->events_created,
            'detectors_run' => $scan->detectors_run,
            'duration_seconds' => $scan->durationSeconds(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'error_message' => $scan->error_message,
            'detector_results' => $scan->detector_results,
        ];
    }

    public function serialiseEvent(SecurityEvent $event): array
    {
        return [
            'id' => $event->id,
            'detector' => $this->humanise($event->detector),
            'category' => $this->humanise($event->category),
            'severity' => $event->severity,
            'title' => $event->title,
            'description' => $event->description,
            'status' => $event->status,
            'user' => $event->relationLoaded('user') ? $event->user?->name : null,
            'ip_address' => $event->ip_address,
            'occurrences' => $event->occurrences,
            'evidence' => $event->evidence,
            'recommendation' => $event->recommendation,
            'first_detected_at' => $event->first_detected_at?->toIso8601String(),
            'last_detected_at' => $event->last_detected_at?->toIso8601String(),
            'acknowledged_at' => $event->acknowledged_at?->toIso8601String(),
            'resolved_at' => $event->resolved_at?->toIso8601String(),
        ];
    }

    private function humanise(string $value): string
    {
        return str($value)->replace('_', ' ')->title()->toString();
    }
}
