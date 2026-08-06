<?php

namespace App\Console\Commands;

use App\Models\SecurityEvent;
use App\Models\SecurityScan;
use App\Services\Security\LoginThrottleService;
use Illuminate\Console\Command;

class PurgeSecurityHistory extends Command
{
    protected $signature = 'security:purge-history
                            {--event-days= : Override the resolved event retention period}
                            {--scan-days= : Override the scan history retention period}';

    protected $description = 'Remove resolved security events and scan history beyond the retention window';

    public function handle(LoginThrottleService $throttles): int
    {
        $eventDays = (int) ($this->option('event-days')
            ?: config('security.retention.resolved_event_days', 365));
        $scanDays = (int) ($this->option('scan-days')
            ?: config('security.retention.scan_history_days', 90));

        // Only resolved findings are purged. Open findings are retained
        // regardless of age so nothing unattended disappears silently.
        $events = SecurityEvent::query()
            ->whereIn('status', ['resolved', 'false_positive'])
            ->where('resolved_at', '<', now()->subDays($eventDays))
            ->delete();

        $scans = SecurityScan::query()
            ->where('created_at', '<', now()->subDays($scanDays))
            ->delete();

        // Expired lockout rows are dropped so the table stays small; active
        // locks are always preserved.
        $throttleRows = $throttles->purgeExpired();

        $this->info("Purged {$events} resolved security event(s) older than {$eventDays} days.");
        $this->info("Purged {$scans} scan record(s) older than {$scanDays} days.");
        $this->info("Purged {$throttleRows} expired login throttle row(s).");

        return self::SUCCESS;
    }
}
