<?php

namespace App\Services\Security;

use App\Mail\SecurityAlertMail;
use App\Models\SecurityEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifies the security recipients about new findings.
 *
 * This dispatcher only informs people. It never disables an account, revokes a
 * session, or blocks an address -- containment is always a human decision.
 */
class SecurityAlertDispatcher
{
    /**
     * @return array{sent: int, channels: array<string, string>, skipped: ?string}
     */
    public function dispatch(Collection $events): array
    {
        if ($events->isEmpty()) {
            return ['sent' => 0, 'channels' => [], 'skipped' => 'No events required alerting.'];
        }

        if (! config('security.alerts.enabled')) {
            return ['sent' => 0, 'channels' => [], 'skipped' => 'Security alerting is disabled.'];
        }

        $recipients = config('security.alerts.recipients', []);
        $channels = [];

        if ($recipients === []) {
            $channels['email'] = 'skipped: SECURITY_ALERT_RECIPIENTS is empty';
        } else {
            try {
                Mail::to($recipients)->send(new SecurityAlertMail($events));
                $channels['email'] = 'sent to '.count($recipients).' recipient(s)';
            } catch (Throwable $exception) {
                $channels['email'] = 'failed: '.str($exception->getMessage())->limit(200);
                Log::error('Security alert email failed.', ['exception' => $exception->getMessage()]);
            }
        }

        if (config('security.alerts.teams_enabled')) {
            $channels['teams'] = $this->sendToTeams($events);
        }

        // Only mark as alerted if at least one channel actually delivered.
        $delivered = collect($channels)->contains(fn (string $result) => str_starts_with($result, 'sent'));

        if ($delivered) {
            SecurityEvent::whereIn('id', $events->pluck('id'))->update(['alerted' => true]);
        }

        return [
            'sent' => $delivered ? $events->count() : 0,
            'channels' => $channels,
            'skipped' => null,
        ];
    }

    private function sendToTeams(Collection $events): string
    {
        $webhook = config('services.teams.webhook_url');

        if (! $webhook || parse_url($webhook, PHP_URL_SCHEME) !== 'https') {
            return 'skipped: no secure Teams webhook configured';
        }

        $critical = $events->where('severity', 'critical')->count();
        $high = $events->where('severity', 'high')->count();

        $facts = $events->take(8)->map(fn (SecurityEvent $event) => [
            'type' => 'TextBlock',
            'text' => "**{$event->severity}** · {$event->title}",
            'wrap' => true,
            'spacing' => 'Small',
        ])->all();

        try {
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
                            [
                                'type' => 'TextBlock',
                                'text' => 'Security findings require review',
                                'weight' => 'Bolder',
                                'size' => 'Medium',
                                'color' => $critical > 0 ? 'Attention' : 'Warning',
                            ],
                            [
                                'type' => 'TextBlock',
                                'text' => "{$critical} critical · {$high} high · {$events->count()} total new finding(s)",
                                'wrap' => true,
                                'isSubtle' => true,
                            ],
                            ...$facts,
                        ],
                        'actions' => [[
                            'type' => 'Action.OpenUrl',
                            'title' => 'Open security dashboard',
                            'url' => rtrim(config('app.url'), '/').'/?view=security',
                        ]],
                    ],
                ]],
            ]);

            return $response->successful()
                ? 'sent'
                : "failed: Teams returned HTTP {$response->status()}";
        } catch (Throwable $exception) {
            Log::error('Security alert Teams delivery failed.', ['exception' => $exception->getMessage()]);

            return 'failed: '.str($exception->getMessage())->limit(200);
        }
    }
}
