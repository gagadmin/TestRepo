<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_user_can_sign_in_and_load_the_platform(): void
    {
        $user = User::factory()->create(['email' => 'analyst@example.com', 'is_active' => true]);

        $this->postJson('/auth/login', [
            'email' => '  ANALYST@EXAMPLE.COM ',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_an_inactive_user_cannot_sign_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_a_deactivated_user_loses_an_existing_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $this->getJson('/api/bootstrap')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This account is inactive.');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.session_revoked',
            'user_id' => $user->id,
        ]);
    }

    public function test_login_logout_and_failed_login_events_are_audited_without_plaintext_email(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $failedMetadata = DB::table('audit_logs')
            ->where('event', 'auth.login_failed')
            ->value('metadata');
        $this->assertStringNotContainsString(strtolower($user->email), $failedMetadata);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $this->postJson('/auth/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.logout', 'user_id' => $user->id]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'post.login']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'post.logout']);
    }
}
