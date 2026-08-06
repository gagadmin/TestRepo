<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class SecurityAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Collection $events) {}

    public function envelope(): Envelope
    {
        $critical = $this->events->where('severity', 'critical')->count();
        $prefix = $critical > 0 ? 'CRITICAL' : 'Security';

        return new Envelope(
            subject: sprintf(
                '[%s] %d new security finding%s require review - %s',
                $prefix,
                $this->events->count(),
                $this->events->count() === 1 ? '' : 's',
                now(config('app.timezone'))->format('d M Y H:i'),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.security-alert',
            with: [
                'dashboardUrl' => rtrim(config('app.url'), '/').'/?view=security',
                'criticalCount' => $this->events->where('severity', 'critical')->count(),
                'highCount' => $this->events->where('severity', 'high')->count(),
            ],
        );
    }
}
