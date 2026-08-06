<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\LoginThrottle;
use Illuminate\Support\Carbon;

/**
 * Progressive account lockout (CIS 6.2).
 *
 * Scoping decision: state is keyed on (account, source address, stage) rather
 * than on the account alone.
 *
 * A purely account-wide lock is a denial-of-service primitive — anyone who
 * knows an email address can keep the owner permanently locked out by sending
 * bad passwords from anywhere. Including the source address means an attacker
 * locks out only themselves, while a real credential-stuffing run from one host
 * still gets shut down. Distributed attacks are separately constrained by
 * Laravel's per-route rate limit and detected by the credential-stuffing
 * detector in SecurityMonitor.
 *
 * Password and second-factor attempts are counted separately so a wrong TOTP
 * code cannot exhaust the password budget.
 */
class LoginThrottleService
{
    public const STAGE_PASSWORD = 'password';

    public const STAGE_TWO_FACTOR = 'two_factor';

    /**
     * Stable, non-reversible key for an email address.
     *
     * The plaintext address is never written to the throttle table, matching
     * how failed logins are already audited.
     */
    public function identifier(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    /**
     * Seconds remaining on an active lock, or 0 when not locked.
     */
    public function lockedFor(string $email, string $ip, string $stage = self::STAGE_PASSWORD): int
    {
        if (! config('security.lockout.enabled')) {
            return 0;
        }

        $throttle = $this->find($email, $ip, $stage);

        return $throttle?->isLocked() ? $throttle->secondsRemaining() : 0;
    }

    public function isLocked(string $email, string $ip, string $stage = self::STAGE_PASSWORD): bool
    {
        return $this->lockedFor($email, $ip, $stage) > 0;
    }

    /**
     * Record a failure and apply the next backoff step if the threshold is met.
     *
     * @return int Seconds locked as a result of this failure (0 if not locked).
     */
    public function recordFailure(
        string $email,
        string $ip,
        string $stage = self::STAGE_PASSWORD,
    ): int {
        if (! config('security.lockout.enabled')) {
            return 0;
        }

        $config = config('security.lockout');
        $now = now();

        $throttle = LoginThrottle::firstOrNew([
            'identifier_hash' => $this->identifier($email),
            'ip_address' => $ip,
            'stage' => $stage,
        ]);

        // A quiet period resets the counter, so someone who mistyped last month
        // does not resume from a punished state.
        $decayed = $throttle->last_failed_at !== null
            && $throttle->last_failed_at->addMinutes($config['decay_minutes'])->isPast();

        if ($decayed || ! $throttle->exists) {
            $throttle->failure_count = 0;
            $throttle->first_failed_at = $now;
        }

        $throttle->failure_count++;
        $throttle->last_failed_at = $now;

        $threshold = $stage === self::STAGE_TWO_FACTOR
            ? $config['two_factor_threshold']
            : $config['threshold'];

        $lockSeconds = 0;

        if ($throttle->failure_count >= $threshold) {
            $steps = $config['backoff_minutes'];
            // Index into the backoff ladder; the last value repeats.
            $position = min($throttle->failure_count - $threshold, count($steps) - 1);
            $minutes = $steps[$position];

            $throttle->locked_until = $now->copy()->addMinutes($minutes);
            $lockSeconds = $minutes * 60;
        }

        $throttle->save();

        if ($lockSeconds > 0) {
            $this->auditLock($email, $ip, $stage, $throttle);
        }

        return $lockSeconds;
    }

    /**
     * Clear state after a successful authentication.
     *
     * Only the rows for this account+address are removed, so a lock earned by
     * an attacker at another address survives.
     */
    public function clear(string $email, string $ip, ?string $stage = null): void
    {
        LoginThrottle::query()
            ->where('identifier_hash', $this->identifier($email))
            ->where('ip_address', $ip)
            ->when($stage, fn ($query) => $query->where('stage', $stage))
            ->delete();
    }

    /**
     * Administrative unlock across every source address.
     */
    public function unlockAccount(string $email): int
    {
        return LoginThrottle::query()
            ->where('identifier_hash', $this->identifier($email))
            ->delete();
    }

    /**
     * Attempts left before the next lock, for the response body.
     */
    public function remainingAttempts(
        string $email,
        string $ip,
        string $stage = self::STAGE_PASSWORD,
    ): ?int {
        if (! config('security.lockout.enabled')) {
            return null;
        }

        $threshold = $stage === self::STAGE_TWO_FACTOR
            ? config('security.lockout.two_factor_threshold')
            : config('security.lockout.threshold');

        $throttle = $this->find($email, $ip, $stage);

        return max(0, $threshold - ($throttle->failure_count ?? 0));
    }

    /**
     * Remove expired rows. Called from the security history purge.
     */
    public function purgeExpired(int $olderThanHours = 24): int
    {
        return LoginThrottle::query()
            ->where('last_failed_at', '<', now()->subHours($olderThanHours))
            ->where(function ($query) {
                $query->whereNull('locked_until')
                    ->orWhere('locked_until', '<', now());
            })
            ->delete();
    }

    private function find(string $email, string $ip, string $stage): ?LoginThrottle
    {
        return LoginThrottle::query()
            ->where('identifier_hash', $this->identifier($email))
            ->where('ip_address', $ip)
            ->where('stage', $stage)
            ->first();
    }

    private function auditLock(string $email, string $ip, string $stage, LoginThrottle $throttle): void
    {
        AuditLog::create([
            'user_id' => null,
            'event' => 'auth.account_locked',
            'auditable_type' => 'user',
            'ip_address' => $ip,
            'user_agent' => str(request()?->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => [
                // Fingerprint only, consistent with auth.login_failed.
                'email_fingerprint' => $this->identifier($email),
                'stage' => $stage,
                'failure_count' => $throttle->failure_count,
                'locked_until' => $throttle->locked_until?->toIso8601String(),
            ],
        ]);
    }
}
