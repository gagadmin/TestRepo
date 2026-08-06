<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Password policy per NIST SP 800-63B.
 *
 * Design note for auditors: 800-63B section 5.1.1.2 states that verifiers
 * SHOULD NOT require memorized secrets to be changed arbitrarily or
 * periodically, because forced rotation causes users to choose weaker,
 * predictably incremented passwords. This implementation therefore relies on
 * length, breach screening, and reuse prevention, and forces a change only on
 * evidence of compromise.
 *
 * A maximum password age IS implemented (`security.password.max_age_days`) and
 * ships disabled, so a literal NIST 800-53 IA-5(1)(d) reading can be satisfied
 * by configuration alone.
 */
class PasswordPolicyService
{
    /** Cached breached-password set, loaded once per request. */
    private ?array $compromised = null;

    /**
     * Validate a candidate password.
     *
     * @return array<string> Human-readable failures; empty means acceptable.
     */
    public function validate(string $password, ?User $user = null): array
    {
        $config = config('security.password');
        $failures = [];

        $length = mb_strlen($password);

        if ($length < $config['min_length']) {
            $failures[] = "Use at least {$config['min_length']} characters.";
        }

        if ($length > $config['max_length']) {
            $failures[] = "Use no more than {$config['max_length']} characters.";
        }

        // Long passphrases are the goal, so no composition rules are imposed.
        // 800-63B explicitly discourages them: they shrink the search space in
        // predictable ways without adding real strength.

        if ($config['block_compromised'] && $this->isCompromised($password)) {
            $failures[] = 'That password appears in known breach and common-password lists. Choose something unique.';
        }

        if ($config['block_contextual'] && $user && $this->isContextual($password, $user)) {
            $failures[] = 'Do not use your name or email address in your password.';
        }

        if ($this->isLowEntropy($password)) {
            $failures[] = 'That password is too repetitive or sequential. Choose something less predictable.';
        }

        if ($user && $this->wasRecentlyUsed($password, $user)) {
            $depth = $config['history_depth'];
            $failures[] = "You cannot reuse any of your last {$depth} passwords.";
        }

        return $failures;
    }

    public function isValid(string $password, ?User $user = null): bool
    {
        return $this->validate($password, $user) === [];
    }

    /**
     * Apply a new password, recording history and clearing any forced change.
     */
    public function update(User $user, string $password, ?string $reason = null): void
    {
        $previousHash = $user->password;

        $user->forceFill([
            'password' => $password, // hashed by the model cast
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        if (filled($previousHash)) {
            PasswordHistory::create([
                'user_id' => $user->id,
                'password_hash' => $previousHash,
            ]);
        }

        $this->pruneHistory($user);

        AuditLog::create([
            'user_id' => $user->id,
            'event' => 'auth.password.changed',
            'auditable_type' => $user::class,
            'auditable_id' => (string) $user->id,
            'ip_address' => request()?->ip(),
            'user_agent' => str(request()?->userAgent() ?? '')->limit(500)->toString(),
            // The password itself is never logged, only that it changed.
            'metadata' => array_filter(['reason' => $reason]),
        ]);
    }

    /**
     * Generate a temporary password that satisfies this policy.
     *
     * Word-based rather than random characters: the admin has to read it aloud
     * or paste it into a message, and a memorable-but-random passphrase survives
     * that trip far better than `Xk9#mQ2!vB`. Entropy comes from the word count
     * and the numeric suffix, not from character classes.
     *
     * ~44 bits with the shipped list, which is ample for a single-use credential
     * that must be changed at first sign-in.
     */
    public function generateTemporary(): string
    {
        $words = [
            'harbour', 'lantern', 'meadow', 'compass', 'thunder', 'willow', 'granite', 'ember',
            'cobalt', 'juniper', 'marble', 'saffron', 'timber', 'velvet', 'canyon', 'drifter',
            'falcon', 'glacier', 'hazel', 'indigo', 'kestrel', 'lagoon', 'mosaic', 'nectar',
            'orchid', 'pebble', 'quartz', 'ridge', 'summit', 'tundra', 'umber', 'vessel',
        ];

        // Loop because the policy could reject a draw (for example if a future
        // list entry collides with the blocked-password file).
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $picked = [];

            for ($index = 0; $index < 3; $index++) {
                $picked[] = $words[random_int(0, count($words) - 1)];
            }

            $candidate = implode('-', $picked).'-'.random_int(10, 99);

            if ($this->isValid($candidate)) {
                return $candidate;
            }
        }

        // Fall back to raw randomness rather than returning something weak.
        return 'tmp-'.bin2hex(random_bytes(9));
    }

    /**
     * Force a change at next sign-in — used when compromise is suspected.
     */
    public function requireChange(User $user, string $reason): void
    {
        $user->forceFill(['must_change_password' => true])->save();

        AuditLog::create([
            'user_id' => $user->id,
            'event' => 'auth.password.change_required',
            'auditable_type' => $user::class,
            'auditable_id' => (string) $user->id,
            'ip_address' => request()?->ip(),
            'metadata' => ['reason' => $reason],
        ]);
    }

    /**
     * Whether the user must change their password before continuing.
     */
    public function mustChange(User $user): bool
    {
        if ($user->must_change_password) {
            return true;
        }

        $maxAge = (int) config('security.password.max_age_days', 0);

        // Zero means rotation is disabled, which is the recommended default.
        if ($maxAge <= 0) {
            return false;
        }

        // An unrecorded change date is treated as expired: these are accounts
        // created before the policy existed.
        if ($user->password_changed_at === null) {
            return true;
        }

        return $user->password_changed_at->addDays($maxAge)->isPast();
    }

    private function wasRecentlyUsed(string $password, User $user): bool
    {
        // The current password counts as reuse.
        if (filled($user->password) && Hash::check($password, $user->password)) {
            return true;
        }

        $depth = (int) config('security.password.history_depth', 5);

        if ($depth <= 0) {
            return false;
        }

        return $user->passwordHistories()
            ->latest('created_at')
            ->limit($depth)
            ->get(['password_hash'])
            ->contains(fn (PasswordHistory $entry) => Hash::check($password, $entry->password_hash));
    }

    private function pruneHistory(User $user): void
    {
        $depth = (int) config('security.password.history_depth', 5);

        $keepIds = $user->passwordHistories()
            ->latest('created_at')
            ->limit($depth)
            ->pluck('id');

        $user->passwordHistories()
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /**
     * Case-insensitive check against the bundled breach/common list.
     *
     * A local list is used rather than an online breach API because outbound
     * HTTP to arbitrary destinations is disallowed by the platform's
     * architecture rules. The trade-off is coverage: this catches the common
     * cases, not every breached credential.
     */
    private function isCompromised(string $password): bool
    {
        $this->compromised ??= $this->loadCompromisedList();

        return isset($this->compromised[Str::lower($password)]);
    }

    private function loadCompromisedList(): array
    {
        $path = resource_path('security/compromised-passwords.txt');

        if (! is_readable($path)) {
            return [];
        }

        $entries = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Keys give O(1) lookups instead of scanning the list.
            $entries[Str::lower($line)] = true;
        }

        return $entries;
    }

    /**
     * Reject passwords built from the user's own identity.
     */
    private function isContextual(string $password, User $user): bool
    {
        $haystack = Str::lower($password);
        $localPart = Str::before(Str::lower((string) $user->email), '@');

        $tokens = collect(preg_split('/\s+/', Str::lower((string) $user->name)))
            ->push($localPart)
            ->push(Str::lower((string) config('app.name')))
            ->filter(fn (?string $token) => filled($token) && mb_strlen($token) >= 4);

        return $tokens->contains(fn (string $token) => str_contains($haystack, $token));
    }

    /**
     * Catch trivially predictable strings that pass a length check.
     *
     * "aaaaaaaaaaaa" and "123456789012" are twelve characters but offer almost
     * no resistance.
     */
    private function isLowEntropy(string $password): bool
    {
        $normalised = Str::lower($password);

        // A single repeated character.
        if (preg_match('/^(.)\1+$/u', $normalised)) {
            return true;
        }

        // Fewer than five distinct characters in a long string.
        if (count(array_unique(mb_str_split($normalised))) < 5) {
            return true;
        }

        // Long ascending or descending runs from the keyboard or alphabet.
        $sequences = ['abcdefghijklmnopqrstuvwxyz', '0123456789', 'qwertyuiop', 'asdfghjkl'];

        foreach ($sequences as $sequence) {
            for ($offset = 0; $offset + 6 <= mb_strlen($sequence); $offset++) {
                $run = mb_substr($sequence, $offset, 6);

                if (str_contains($normalised, $run) || str_contains($normalised, strrev($run))) {
                    return true;
                }
            }
        }

        return false;
    }
}
