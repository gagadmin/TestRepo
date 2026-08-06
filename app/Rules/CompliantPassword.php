<?php

namespace App\Rules;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Applies the password policy as a validation rule, so form requests get the
 * same rules the service enforces and the user sees every problem at once.
 */
class CompliantPassword implements ValidationRule
{
    public function __construct(private readonly ?User $user = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $failures = app(PasswordPolicyService::class)->validate($value, $this->user);

        // Report all failures rather than only the first: a user who is told
        // only about length will hit the breach rule on their next attempt.
        foreach ($failures as $message) {
            $fail($message);
        }
    }
}
