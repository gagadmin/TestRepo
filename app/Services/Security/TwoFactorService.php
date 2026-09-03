<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * TOTP second factor (RFC 6238) and single-use recovery codes.
 *
 * Satisfies ISO 27001 A.8.5 and NIST IA-2.
 *
 * Two properties matter most here and are both tested:
 *   1. A code may be accepted only once. Without replay protection an attacker
 *      who observes a code over the shoulder, or captures it from a phished
 *      page, can reuse it for the remainder of its 30-second window.
 *   2. A recovery code is consumed on use, so a leaked backup sheet grants a
 *      bounded number of entries rather than permanent access.
 */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Begin enrolment: generate and persist an unconfirmed secret.
     *
     * The secret is stored immediately (encrypted) but `two_factor_confirmed_at`
     * stays null, so the account is not yet treated as enrolled. Re-running
     * this before confirmation issues a fresh secret, which is what a user who
     * lost the QR code mid-enrolment needs.
     */
    public function beginEnrolment(User $user): array
    {
        if ($user->hasConfirmedTwoFactor()) {
            throw new RuntimeException('Two-factor authentication is already enabled for this account.');
        }

        $secret = $this->google2fa->generateSecretKey(32);

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_timestep' => null,
        ])->save();

        return [
            'secret' => $secret,
            'qr_code_svg' => $this->qrCodeSvg($user, $secret),
            'otpauth_uri' => $this->provisioningUri($user, $secret),
        ];
    }

    /**
     * Complete enrolment by proving the authenticator is configured.
     *
     * Returns the recovery codes, which are shown exactly once.
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        if (blank($user->two_factor_secret)) {
            throw new RuntimeException('Start two-factor setup before confirming a code.');
        }

        $timestep = $this->verifyAndReturnTimestep($user->two_factor_secret, $code, null);

        if ($timestep === null) {
            throw new RuntimeException('That code is not valid. Check your authenticator app and try again.');
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_timestep' => $timestep,
            // Stored hashed: a database reader must not be able to use them.
            'two_factor_recovery_codes' => array_map(
                fn (string $code) => Hash::make($code),
                $codes,
            ),
        ])->save();

        $this->audit($user, 'auth.two_factor.enabled');

        return $codes;
    }

    /**
     * Verify a challenge response: either a TOTP code or a recovery code.
     *
     * @return bool True when authentication may proceed.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        $code = trim($code);

        // Recovery codes are longer and contain a separator, so the shape tells
        // us which path to take without asking the user to choose.
        if ($this->looksLikeRecoveryCode($code)) {
            return $this->consumeRecoveryCode($user, $code);
        }

        $timestep = $this->verifyAndReturnTimestep(
            $user->two_factor_secret,
            $code,
            $user->two_factor_last_used_timestep,
        );

        if ($timestep === null) {
            return false;
        }

        // Record the accepted step so the same code cannot be replayed.
        $user->forceFill(['two_factor_last_used_timestep' => $timestep])->save();

        return true;
    }

    /**
     * Validate a TOTP code and return the time-step it matched.
     *
     * @param  int|null  $lastUsedTimestep  Reject anything at or before this.
     * @return int|null The matched step, or null when invalid or replayed.
     */
    private function verifyAndReturnTimestep(
        ?string $secret,
        string $code,
        ?int $lastUsedTimestep,
    ): ?int {
        if (blank($secret) || ! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $window = config('security.two_factor.window', 1);

        // verifyKeyNewer returns the matched timestamp (time-step) or false.
        // Passing the last used step makes the library itself refuse anything
        // that is not strictly newer.
        $result = $this->google2fa->verifyKeyNewer(
            $secret,
            $code,
            $lastUsedTimestep,
            $window,
        );

        if ($result === false) {
            return null;
        }

        // When no previous step was recorded the library returns true rather
        // than a step, so derive it.
        return is_int($result)
            ? $result
            : (int) floor(time() / 30);
    }

    /**
     * Consume a single-use recovery code.
     */
    private function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $stored = $user->two_factor_recovery_codes ?? [];

        foreach ($stored as $index => $hash) {
            if (! Hash::check($candidate, $hash)) {
                continue;
            }

            unset($stored[$index]);
            $user->forceFill([
                'two_factor_recovery_codes' => array_values($stored),
            ])->save();

            $this->audit($user, 'auth.two_factor.recovery_code_used', [
                'remaining' => count($stored),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Issue a fresh set of recovery codes, invalidating the previous set.
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        if (! $user->hasConfirmedTwoFactor()) {
            throw new RuntimeException('Enable two-factor authentication first.');
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(
                fn (string $code) => Hash::make($code),
                $codes,
            ),
        ])->save();

        $this->audit($user, 'auth.two_factor.recovery_codes_regenerated');

        return $codes;
    }

    /**
     * Remove the second factor.
     *
     * Refused when policy requires this account to hold one, so a user cannot
     * opt out of a mandatory control.
     */
    public function disable(User $user, ?User $actor = null): void
    {
        if ($user->requiresTwoFactor() && ! $actor?->hasPermission('users.manage')) {
            throw new RuntimeException(
                'Two-factor authentication is required for this account and cannot be removed.'
            );
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_timestep' => null,
        ])->save();

        $this->audit($user, 'auth.two_factor.disabled', [
            'actor_id' => $actor?->id,
        ]);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * Recovery codes: 10 hex characters split by a dash for legibility.
     *
     * ~40 bits of entropy each, which is ample for a code that is single-use
     * and rate limited.
     */
    private function generateRecoveryCodes(): array
    {
        $count = config('security.two_factor.recovery_code_count', 8);

        return collect(range(1, $count))
            ->map(fn () => sprintf(
                '%s-%s',
                Str::upper(bin2hex(random_bytes(3))),
                Str::upper(bin2hex(random_bytes(3))),
            ))
            ->all();
    }

    private function looksLikeRecoveryCode(string $code): bool
    {
        return (bool) preg_match('/^[0-9A-Fa-f]{6}-[0-9A-Fa-f]{6}$/', $code);
    }

    private function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('security.two_factor.issuer', config('app.name')),
            $user->email,
            $secret,
        );
    }

    /**
     * Inline SVG QR code.
     *
     * Rendered server-side rather than via an external chart service so the
     * shared secret never leaves the application.
     */
    private function qrCodeSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(200, 0),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($this->provisioningUri($user, $secret));
    }

    private function audit(User $user, string $event, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'event' => $event,
            'auditable_type' => $user::class,
            'auditable_id' => (string) $user->id,
            'ip_address' => request()?->ip(),
            'user_agent' => str(request()?->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => $metadata,
        ]);
    }
}
