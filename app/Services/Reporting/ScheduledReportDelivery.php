<?php

namespace App\Services\Reporting;

use App\Mail\ScheduledReportMail;
use App\Models\ReportScheduleRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class ScheduledReportDelivery
{
    public function deliver(ReportScheduleRun $run, string $contents, int $rowCount): array
    {
        $schedule = $run->schedule;
        $report = $run->report;
        $results = $run->channel_results ?? [];
        $failures = [];

        foreach ($schedule->delivery_channels as $channel) {
            if (($results[$channel]['status'] ?? null) === 'succeeded') {
                continue;
            }

            try {
                if ($channel === 'email') {
                    Mail::to($schedule->recipients)->send(
                        new ScheduledReportMail(
                            $report,
                            $schedule->format,
                            $contents,
                            $rowCount,
                            $run->snapshot?->summary ?? [],
                            $schedule->filters ?? [],
                        )
                    );
                } elseif ($channel === 'teams') {
                    $this->sendToTeams($report->name, $report->id, $rowCount, $schedule->format);
                }

                $results[$channel] = [
                    'status' => 'succeeded',
                    'delivered_at' => now()->toIso8601String(),
                ];
            } catch (Throwable $exception) {
                $results[$channel] = [
                    'status' => 'failed',
                    'message' => str($exception->getMessage())->limit(500)->toString(),
                ];
                $failures[] = $channel;
            }
        }

        $run->update(['channel_results' => $results]);

        if ($failures !== []) {
            throw new RuntimeException('Delivery failed for: '.implode(', ', $failures).'.');
        }

        return $results;
    }

    private function sendToTeams(string $reportName, int $reportId, int $rowCount, string $format): void
    {
        $webhook = config('services.teams.webhook_url');

        if (! $webhook || parse_url($webhook, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('A secure Microsoft Teams webhook is not configured.');
        }

        $response = Http::asJson()->timeout(15)->post($webhook, [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => $reportName, 'weight' => 'Bolder', 'size' => 'Medium'],
                        ['type' => 'TextBlock', 'text' => "{$rowCount} records · ".strtoupper($format).' generated', 'wrap' => true],
                    ],
                    'actions' => [[
                        'type' => 'Action.OpenUrl',
                        'title' => 'Open report',
                        'url' => rtrim(config('app.url'), '/').'/?report='.$reportId,
                    ]],
                ],
            ]],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Microsoft Teams returned HTTP {$response->status()}.");
        }
    }
}
