<?php

namespace App\Mail;

use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Report $report,
        public readonly string $format,
        private readonly string $contents,
        public readonly int $rowCount,
        public readonly array $summary = [],
        public readonly array $filters = [],
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->report->type === 'itsm_ticket_summary'
            ? 'Action required: '.$this->report->name.' - '.now(config('app.timezone'))->format('d-m-Y')
            : "Scheduled report: {$this->report->name}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.scheduled-report',
            with: [
                'reportUrl' => rtrim(config('app.url'), '/').'/?report='.$this->report->id,
                'itsm' => $this->summary['itsm'] ?? null,
                'periodLabel' => $this->periodLabel(),
            ]
        );
    }

    private function periodLabel(): ?string
    {
        $from = $this->filters['date_from'] ?? null;
        $to = $this->filters['date_to'] ?? null;

        if (blank($from) && blank($to)) {
            return null;
        }

        $format = fn (?string $date) => filled($date)
            ? CarbonImmutable::parse($date)->format('d-m-Y')
            : null;

        return match (true) {
            filled($from) && filled($to) => $format($from).' to '.$format($to),
            filled($from) => 'from '.$format($from),
            default => 'up to '.$format($to),
        };
    }

    public function attachments(): array
    {
        $mime = $this->format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return [
            Attachment::fromData(fn () => $this->contents, Str::slug($this->report->name).'.'.$this->format)
                ->withMime($mime),
        ];
    }
}
