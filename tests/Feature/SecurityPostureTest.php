<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\SecurityScan;
use App\Models\User;
use App\Services\Security\SecurityPostureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Security dashboard figures.
 *
 * The score is the headline number the security area is judged on and the
 * mean-time metrics are reported upward, yet neither had any coverage: the
 * existing tests exercise the dashboard's authorisation and its "not connected"
 * sections, not its arithmetic.
 */
class SecurityPostureTest extends TestCase
{
    use RefreshDatabase;

    private function posture(): SecurityPostureService
    {
        return app(SecurityPostureService::class);
    }

    /* ------------------------------------------------------------------
     | Security score
     |------------------------------------------------------------------ */

    /*
     * The score is asserted as a delta from the environment's own baseline
     * rather than against 100. Some compliance controls read runtime
     * configuration — session driver, debug flag — so a test environment does
     * not necessarily start clean, and pinning an absolute number would make
     * these tests fail for reasons that have nothing to do with the finding
     * arithmetic under test.
     */
    private function baselineScore(): int
    {
        return app(SecurityPostureService::class)->securityScore()['score'];
    }

    public function test_a_clean_estate_records_no_finding_deductions(): void
    {
        Config::set('security.two_factor.enabled', true);

        $score = $this->posture()->securityScore();

        $reasons = collect($score['breakdown'])->pluck('reason')->all();
        $this->assertNotContains('Critical severity findings open', $reasons);
        $this->assertNotContains('High severity findings open', $reasons);
        $this->assertContains($score['grade'], ['A', 'B']);
    }

    public function test_open_findings_deduct_by_severity(): void
    {
        Config::set('security.two_factor.enabled', true);
        $baseline = $this->baselineScore();

        $this->event('critical');
        $score = $this->posture()->securityScore();

        // Critical carries a weight of 25 and one finding deducts one weight.
        $this->assertSame($baseline - 25, $score['score']);
        $deduction = collect($score['breakdown'])->firstWhere('reason', 'Critical severity findings open');
        $this->assertSame(-25, $deduction['points']);
        $this->assertSame(1, $deduction['count']);
    }

    public function test_repeat_findings_of_one_severity_have_diminishing_impact(): void
    {
        // Deliberate: ten open medium findings must not zero the score on their
        // own, or every other signal becomes invisible.
        Config::set('security.two_factor.enabled', true);
        $baseline = $this->baselineScore();

        for ($i = 0; $i < 10; $i++) {
            $this->event('medium');
        }

        $score = $this->posture()->securityScore();

        // Medium weighs 5, capped at twice the weight however many there are.
        $this->assertSame($baseline - 10, $score['score']);
    }

    public function test_informational_findings_do_not_move_the_score(): void
    {
        Config::set('security.two_factor.enabled', true);
        $baseline = $this->baselineScore();

        $this->event('info');

        $this->assertSame($baseline, $this->posture()->securityScore()['score']);
    }

    public function test_a_resolved_finding_stops_counting_against_the_score(): void
    {
        Config::set('security.two_factor.enabled', true);
        $baseline = $this->baselineScore();

        $event = $this->event('critical');
        $this->assertSame($baseline - 25, $this->posture()->securityScore()['score']);

        $event->update(['status' => 'resolved', 'resolved_at' => now()]);

        $this->assertSame($baseline, $this->posture()->securityScore()['score']);
    }

    public function test_disabled_multi_factor_costs_fifteen_points(): void
    {
        Config::set('security.two_factor.enabled', false);

        $score = $this->posture()->securityScore();

        $reasons = collect($score['breakdown'])->pluck('reason')->all();
        $this->assertContains('Multi-factor authentication disabled by configuration', $reasons);
        $this->assertLessThanOrEqual(85, $score['score']);
    }

    public function test_the_score_never_falls_below_zero(): void
    {
        Config::set('security.two_factor.enabled', false);
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            for ($i = 0; $i < 20; $i++) {
                $this->event($severity);
            }
        }

        $score = $this->posture()->securityScore();

        $this->assertGreaterThanOrEqual(0, $score['score']);
        $this->assertSame('F', $score['grade']);
    }

    /* ------------------------------------------------------------------
     | Mean time to detect and respond
     |------------------------------------------------------------------ */

    public function test_the_mean_time_metrics_are_null_with_nothing_to_measure(): void
    {
        // Null means "no basis to report", which the interface renders as such.
        // Returning zero would read as instant detection.
        $this->assertNull($this->posture()->meanTimeToDetect());
        $this->assertNull($this->posture()->meanTimeToRespond());
    }

    public function test_mean_time_to_detect_averages_the_detection_lag(): void
    {
        $this->event('high', [
            'occurred_at' => now()->subMinutes(30),
            'first_detected_at' => now()->subMinutes(20),
        ]);
        $this->event('high', [
            'occurred_at' => now()->subMinutes(60),
            'first_detected_at' => now()->subMinutes(40),
        ]);

        $this->assertSame(15.0, $this->posture()->meanTimeToDetect());
    }

    public function test_mean_time_to_respond_averages_detection_to_resolution(): void
    {
        $this->event('high', [
            'first_detected_at' => now()->subMinutes(60),
            'resolved_at' => now()->subMinutes(30),
            'status' => 'resolved',
        ]);

        $this->assertSame(30.0, $this->posture()->meanTimeToRespond());
    }

    public function test_an_unresolved_finding_is_excluded_from_response_time(): void
    {
        $this->event('high', ['first_detected_at' => now()->subMinutes(60)]);

        $this->assertNull($this->posture()->meanTimeToRespond());
    }

    /* ------------------------------------------------------------------
     | Overview trend
     |------------------------------------------------------------------ */

    public function test_the_trend_reports_no_change_percentage_without_a_baseline(): void
    {
        // Dividing by an empty previous period would be a division by zero; the
        // interface needs an explicit null rather than a fabricated 100%.
        $this->event('high', ['first_detected_at' => now()->subDays(2)]);

        $trend = $this->posture()->overview(30)['trend'];

        $this->assertSame(1, $trend['current_period']);
        $this->assertSame(0, $trend['previous_period']);
        $this->assertNull($trend['change_percentage']);
        $this->assertSame('up', $trend['direction']);
    }

    public function test_the_trend_compares_the_two_windows(): void
    {
        $this->event('high', ['first_detected_at' => now()->subDays(40)]);
        $this->event('high', ['first_detected_at' => now()->subDays(45)]);
        $this->event('high', ['first_detected_at' => now()->subDays(3)]);

        $trend = $this->posture()->overview(30)['trend'];

        $this->assertSame(1, $trend['current_period']);
        $this->assertSame(2, $trend['previous_period']);
        $this->assertSame(-50.0, $trend['change_percentage']);
        $this->assertSame('down', $trend['direction']);
    }

    /* ------------------------------------------------------------------
     | Last scan
     |------------------------------------------------------------------ */

    public function test_the_last_scan_is_null_before_any_scan_runs(): void
    {
        $this->assertNull($this->posture()->lastScan());
    }

    public function test_the_last_scan_reports_the_most_recent_run(): void
    {
        SecurityScan::create(['trigger' => 'scheduled', 'status' => 'completed', 'security_score' => 80]);
        $latest = SecurityScan::create(['trigger' => 'manual', 'status' => 'completed', 'security_score' => 90]);

        $scan = $this->posture()->lastScan();

        $this->assertSame($latest->id, $scan['id']);
        $this->assertSame('manual', $scan['trigger']);
    }

    /* ------------------------------------------------------------------
     | Repeated calculation
     |------------------------------------------------------------------ */

    public function test_the_dashboard_computes_compliance_and_mfa_coverage_once(): void
    {
        /*
         * A dashboard request reached compliance() three times and
         * mfaCoverage() five, each re-running the same account and policy
         * queries. Both are memoised per request now. Asserting on the query
         * count is what stops the duplication returning unnoticed.
         */
        $this->seedPrivilegedUser();
        $posture = $this->posture();

        DB::enableQueryLog();
        $posture->dashboard(30);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        /*
         * Measured: 62 queries before memoisation, 54 after. The threshold sits
         * between the two so removing the cache fails here, while leaving a
         * little room for a legitimate new query. If a genuine addition pushes
         * it over, raise the number deliberately rather than deleting the
         * assertion.
         */
        $this->assertLessThan(
            58,
            $queries,
            "The dashboard issued {$queries} queries; the repeated compliance and MFA work has returned."
        );
    }

    public function test_the_memoised_dashboard_still_reports_the_same_figures(): void
    {
        $this->seedPrivilegedUser();
        $this->event('critical');

        $dashboard = $this->posture()->dashboard(30);

        // The score reached through the dashboard must match the direct call,
        // which is what a stale cache would break.
        $this->assertSame(
            $this->posture()->securityScore()['score'],
            $dashboard['overview']['security_score'],
        );
        $this->assertSame(
            $dashboard['compliance']['overall_percentage'],
            $dashboard['overview']['compliance_percentage'],
        );
    }

    /* ------------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(string $severity, array $attributes = []): SecurityEvent
    {
        return SecurityEvent::create([
            'detector' => 'test',
            'category' => 'threat',
            'severity' => $severity,
            'title' => 'Finding',
            'description' => 'Created by the posture test.',
            'status' => 'open',
            'fingerprint' => 'posture:'.uniqid('', true),
            'first_detected_at' => CarbonImmutable::now(),
            'last_detected_at' => CarbonImmutable::now(),
            ...$attributes,
        ]);
    }

    private function seedPrivilegedUser(): void
    {
        $role = Role::firstOrCreate(['name' => 'administrator'], ['label' => 'Administrator']);
        $permission = Permission::firstOrCreate(
            ['name' => 'security.view'],
            ['label' => 'security.view', 'group' => 'Security'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        User::factory()->create(['is_active' => true])->roles()->attach($role);
    }
}
