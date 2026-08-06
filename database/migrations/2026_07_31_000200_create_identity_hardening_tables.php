<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes three failing controls:
 *   ISO 27001 A.8.5 / NIST IA-2  multi-factor authentication
 *   NIST IA-5                    password history and policy
 *   CIS 6.2                      account lockout after failed attempts
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted at rest via the model's `encrypted` casts. Nullable
            // because an account exists before it enrols.
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            // Guards against TOTP replay: the last accepted time-step counter.
            $table->unsignedBigInteger('two_factor_last_used_timestep')->nullable()
                ->after('two_factor_confirmed_at');

            // NIST 800-63B: no forced rotation, but the age is recorded so a
            // maximum lifetime can be switched on later without a migration,
            // and so "changed on evidence of compromise" is provable.
            $table->timestamp('password_changed_at')->nullable()->after('two_factor_last_used_timestep');

            // Set by an administrator or by the security agent when an account
            // is suspected compromised.
            $table->boolean('must_change_password')->default(false)
                ->after('password_changed_at');

            $table->index('two_factor_confirmed_at', 'users_two_factor_confirmed_idx');
        });

        // Prevents reuse of recent passwords (IA-5). Stores hashes only.
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password_hash');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'password_histories_user_created_idx');
        });

        /**
         * Progressive lockout state, scoped to account + source address.
         *
         * Scoping to the pair is deliberate: a purely account-wide lock lets a
         * remote attacker who knows an email address deny service to the real
         * owner indefinitely. One indexed row per pair also means the login
         * path is a single keyed read rather than a COUNT over audit_logs.
         */
        Schema::create('login_throttles', function (Blueprint $table) {
            $table->id();

            // HMAC of the lowercased email. The plaintext address is never
            // stored here, consistent with how failed logins are audited.
            $table->string('identifier_hash', 64);
            $table->string('ip_address', 45);

            // 'password' and 'two_factor' are counted separately so a wrong
            // TOTP code cannot exhaust the password budget or vice versa.
            $table->string('stage', 16)->default('password');

            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('first_failed_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();

            $table->unique(['identifier_hash', 'ip_address', 'stage'], 'login_throttles_unique');
            $table->index('locked_until', 'login_throttles_locked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_throttles');
        Schema::dropIfExists('password_histories');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_two_factor_confirmed_idx');
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_used_timestep',
                'password_changed_at',
                'must_change_password',
            ]);
        });
    }
};
