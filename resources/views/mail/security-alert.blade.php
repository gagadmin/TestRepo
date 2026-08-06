<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Security findings</title></head>
<body style="margin:0;padding:0;background:#eef2f6;font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:#1f2937">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f6">
    <tr><td align="center" style="padding:28px 14px">
        <table role="presentation" width="800" cellspacing="0" cellpadding="0" style="width:100%;max-width:800px;background:#fff;border:1px solid #dbe3ea;border-radius:12px;overflow:hidden">

            <tr>
                <td style="padding:24px 30px;background:{{ $criticalCount > 0 ? '#7a271a' : '#0f3d5c' }}">
                    <div style="color:#f3c9c4;font-size:11px;font-weight:bold;letter-spacing:1.6px;text-transform:uppercase">
                        Ask GAHolding &middot; Security Monitoring
                    </div>
                    <h1 style="margin:8px 0 4px;font-size:22px;color:#fff;font-weight:600">
                        {{ $events->count() }} security finding{{ $events->count() === 1 ? '' : 's' }} require review
                    </h1>
                    <div style="color:#e5d4d1;font-size:13px">
                        {{ $criticalCount }} critical &middot; {{ $highCount }} high &middot;
                        Detected {{ now(config('app.timezone'))->format('d M Y, H:i') }} ({{ config('app.timezone') }})
                    </div>
                </td>
            </tr>

            <tr><td style="padding:26px 30px">

                <p style="margin:0 0 18px;color:#374151;line-height:1.7;font-size:14px">
                    The security agent detected the findings below during its most recent scan.
                    No automated containment action has been taken &mdash; each finding needs a human decision.
                </p>

                @foreach ($events as $event)
                    @php
                        $accent = match ($event->severity) {
                            'critical' => '#b42318',
                            'high' => '#b54708',
                            'medium' => '#6941c6',
                            default => '#0e7490',
                        };
                    @endphp
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                           style="margin-bottom:14px;border:1px solid #e2e8f0;border-left:4px solid {{ $accent }};border-radius:6px">
                        <tr><td style="padding:14px 16px">
                            <div style="font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:.8px;color:{{ $accent }}">
                                {{ $event->severity }} &middot; {{ str($event->detector)->replace('_', ' ')->title() }}
                                @if ($event->occurrences > 1)
                                    &middot; seen {{ $event->occurrences }}&times;
                                @endif
                            </div>
                            <div style="margin:6px 0 8px;font-size:15px;font-weight:600;color:#111827">{{ $event->title }}</div>
                            <div style="color:#4b5563;font-size:13px;line-height:1.65">{{ $event->description }}</div>

                            @if (filled($event->recommendation))
                                <div style="margin-top:11px;padding-top:11px;border-top:1px solid #f1f5f9">
                                    <div style="font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:.6px;color:#64748b;margin-bottom:6px">
                                        Recommended actions
                                    </div>
                                    <ul style="margin:0;padding-left:18px;color:#4b5563;font-size:13px;line-height:1.7">
                                        @foreach ($event->recommendation as $step)
                                            <li>{{ $step }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </td></tr>
                    </table>
                @endforeach

                <p style="margin:22px 0 0">
                    <a href="{{ $dashboardUrl }}"
                       style="display:inline-block;padding:11px 22px;background:#0f3d5c;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:13px">
                        Open security dashboard
                    </a>
                </p>
            </td></tr>

            <tr>
                <td style="padding:16px 30px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:11px;line-height:1.6">
                    Sent by the Ask GAHolding security agent. This message contains security-sensitive information &mdash;
                    do not forward outside the security and IT teams.
                </td>
            </tr>
        </table>
    </td></tr>
</table>
</body>
</html>
