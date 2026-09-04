<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

/**
 * Bootstrap administrator seeding.
 *
 * The seeder creates the first administrator, so the value it uses for a
 * password decides whether a fresh environment starts with a credential the
 * operator chose or one published in this repository. It previously read
 * `env('BI_ADMIN_PASSWORD')` directly, which returns null against a cached
 * configuration, and it only refused to continue in `production`.
 */
class BootstrapSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_configured_password_and_identity(): void
    {
        Config::set('bootstrap.admin.password', 'A-Configured-Password-1!');
        Config::set('bootstrap.admin.email', 'ops@example.test');
        Config::set('bootstrap.admin.name', 'Ops Administrator');

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'ops@example.test')->first();
        $this->assertNotNull($admin);
        $this->assertSame('Ops Administrator', $admin->name);
        $this->assertTrue(Hash::check('A-Configured-Password-1!', $admin->password));
    }

    public function test_it_reads_configuration_rather_than_the_environment(): void
    {
        /*
         * The distinction that matters: `env()` returns null once the config
         * cache is warm, which is the normal deployed state. Setting the
         * configuration alone — with no environment variable behind it — must
         * be enough.
         */
        Config::set('bootstrap.admin.password', 'From-Config-Only-1!');
        Config::set('bootstrap.admin.email', 'cached@example.test');

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'cached@example.test')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('From-Config-Only-1!', $admin->password));
    }

    public function test_it_refuses_to_seed_an_unconfigured_deployed_environment(): void
    {
        // Staging and UAT were previously able to seed an administrator whose
        // password is published in the source; only production refused.
        Config::set('bootstrap.admin.password', null);
        $this->app->detectEnvironment(fn () => 'staging');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('BI_ADMIN_PASSWORD must be configured');

        $this->seed(DatabaseSeeder::class);
    }

    public function test_it_still_refuses_in_production(): void
    {
        Config::set('bootstrap.admin.password', null);
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(LogicException::class);

        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_local_database_may_be_seeded_without_a_configured_password(): void
    {
        // Developers must still be able to seed a scratch database.
        Config::set('bootstrap.admin.password', null);
        Config::set('bootstrap.admin.email', 'dev@example.test');
        $this->app->detectEnvironment(fn () => 'local');

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'dev@example.test')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check(config('bootstrap.development_password'), $admin->password));
    }

    public function test_the_seeded_administrator_holds_the_administrator_role(): void
    {
        Config::set('bootstrap.admin.password', 'A-Configured-Password-1!');
        Config::set('bootstrap.admin.email', 'ops@example.test');

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'ops@example.test')->first();
        $this->assertSame(['administrator'], $admin->roles->pluck('name')->all());
    }
}
