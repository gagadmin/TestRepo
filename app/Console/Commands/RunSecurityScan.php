<?php

namespace App\Console\Commands;

use App\Services\Security\SecurityAlertDispatcher;
use App\Services\Security\SecurityMonitor;
use Illuminate\Console\Command;

class RunSecurityScan extends Command
{
    protected $signature = 'security:scan
                            {--no-alerts : Run detectors without dispatching notifications}
                            {--quiet-ok : Only produce output when findings exist}';

    protected $description = 'Run the security detection agent and alert on new findings';

    public function handle(SecurityMonitor $monitor, SecurityAlertDispatcher $alerts): int
    {
        $scan = $monitor->scan('scheduled');
        $quietOk = $this->option('quiet-ok');

        if (! $quietOk || $scan->events_created > 0) {
            $this->info(sprintf(
                'Security scan #%d %s in %ss — %d detector(s), %d finding(s), %d new.',
                $scan->id,
                $scan->status,
                $scan->durationSeconds() ?? '?',
                $scan->detectors_run,
                $scan->events_detected,
                $scan->events_created,
            ));

            if ($scan->security_score !== null) {
                $this->line("  Security score: {$scan->security_score}/100");
            }
        }

        foreach ($scan->detector_results ?? [] as $detector => $result) {
            if (($result['status'] ?? null) === 'failed') {
                $this->error("  ✗ {$detector}: {$result['message']}");
            } elseif (($result['findings'] ?? 0) > 0) {
                $this->line("  • {$detector}: {$result['findings']} finding(s)");
            }
        }

        if ($this->option('no-alerts')) {
            $this->comment('  Alerting skipped (--no-alerts).');

            return $scan->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
        }

        $pending = $monitor->pendingAlerts();
        $result = $alerts->dispatch($pending);

        if ($result['skipped']) {
            $this->comment("  {$result['skipped']}");
        } else {
            $this->info("  Alerted on {$result['sent']} finding(s).");

            foreach ($result['channels'] as $channel => $outcome) {
                $this->line("    {$channel}: {$outcome}");
            }
        }

        return $scan->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }
}
