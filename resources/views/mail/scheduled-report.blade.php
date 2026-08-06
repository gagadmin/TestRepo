<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $report->name }}</title>
</head>
<body style="margin:0;padding:0;background:#eef2f6;font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:#1f2937">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f6">
    <tr>
        <td align="center" style="padding:28px 14px">
            <table role="presentation" width="860" cellspacing="0" cellpadding="0" style="width:100%;max-width:860px;background:#ffffff;border:1px solid #dbe3ea;border-radius:12px;overflow:hidden">

                {{-- Header --}}
                <tr>
                    <td style="padding:26px 32px;background:#0f3d5c">
                        <div style="color:#8fd3e8;font-size:11px;font-weight:bold;letter-spacing:1.6px;text-transform:uppercase">Ask GAHolding &middot; IT Service Management</div>
                        <h1 style="margin:8px 0 4px;font-size:23px;color:#ffffff;font-weight:600">{{ $report->name }}</h1>
                        <div style="color:#b9d6e5;font-size:13px">
                            Reporting date: {{ $itsm['meta']['report_date'] ?? now(config('app.timezone'))->format('d-m-Y') }}
                            @if (filled($periodLabel ?? null)) &middot; Period: {{ $periodLabel }} @endif
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 32px">

                    @if ($itsm)
                        @php
                            $s = $itsm['summary'] ?? [];
                            $unresolvedCount = (int) ($s['unresolved'] ?? 0);
                            $slaCount = (int) ($s['sla_breached'] ?? 0);
                            $dueTodayCount = (int) ($s['due_today'] ?? 0);
                            $onHoldCount = (int) ($s['on_hold'] ?? 0);
                            $overdueCount = (int) ($s['overdue'] ?? 0);
                            $unassignedCount = (int) ($s['unassigned'] ?? 0);
                        @endphp

                        {{-- Greeting --}}
                        <p style="margin:0 0 10px;line-height:1.6;font-size:14px">Dear Team,</p>
                        <p style="margin:0 0 14px;line-height:1.6;font-size:14px">Greetings for the day!</p>

                        <p style="margin:0 0 14px;font-size:15px;font-weight:bold;color:#b42318;letter-spacing:.3px">For Your Immediate Action!!!</p>

                        <p style="margin:0 0 12px;color:#374151;line-height:1.7;font-size:14px">
                            Thank you all for your continued support in attentively handling the tickets. As of now, we stand with
                            <strong>{{ number_format($unresolvedCount) }} pending/unresolved tickets</strong>, with
                            <strong>{{ number_format($slaCount) }} SLA-violated tickets</strong> and an additional
                            <strong>{{ number_format($dueTodayCount) }}</strong> that are about to violate the SLA today.
                            I strongly recommend prioritizing these tickets and arranging for their resolution ASAP to keep our SLA compliance intact.
                        </p>
                        <p style="margin:0 0 22px;color:#374151;line-height:1.7;font-size:14px">
                            Furthermore, we have <strong>{{ number_format($onHoldCount) }} tickets On-Hold</strong>, which should be updated
                            periodically to ensure service quality is not adversely affected and effective communication with end-users is in place.
                        </p>

                        {{-- KPI cards --}}
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 8px">
                            <tr>
                                @foreach ([
                                    ['Unresolved', $unresolvedCount, '#0f3d5c', '#eaf2f7'],
                                    ['SLA breached', $slaCount, '#b42318', '#fdeceb'],
                                    ['Due today', $dueTodayCount, '#b54708', '#fef4e6'],
                                ] as [$label, $value, $colour, $bg])
                                    <td width="33%" style="padding:0 5px 10px 0">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:{{ $bg }};border:1px solid #e2e8f0;border-radius:8px">
                                            <tr><td style="padding:14px 16px">
                                                <div style="font-size:24px;font-weight:bold;color:{{ $colour }};line-height:1.1">{{ number_format($value) }}</div>
                                                <div style="font-size:11px;color:#64748b;margin-top:5px;text-transform:uppercase;letter-spacing:.6px">{{ $label }}</div>
                                            </td></tr>
                                        </table>
                                    </td>
                                @endforeach
                            </tr>
                            <tr>
                                @foreach ([
                                    ['On hold', $onHoldCount, '#6941c6', '#f4f0fd'],
                                    ['Overdue', $overdueCount, '#b42318', '#fdeceb'],
                                    ['Unassigned', $unassignedCount, '#0e7490', '#e8f6f8'],
                                ] as [$label, $value, $colour, $bg])
                                    <td width="33%" style="padding:0 5px 0 0">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:{{ $bg }};border:1px solid #e2e8f0;border-radius:8px">
                                            <tr><td style="padding:14px 16px">
                                                <div style="font-size:24px;font-weight:bold;color:{{ $colour }};line-height:1.1">{{ number_format($value) }}</div>
                                                <div style="font-size:11px;color:#64748b;margin-top:5px;text-transform:uppercase;letter-spacing:.6px">{{ $label }}</div>
                                            </td></tr>
                                        </table>
                                    </td>
                                @endforeach
                            </tr>
                        </table>

                        {{-- 1. Overall Ticket Summary --}}
                        <h2 style="margin:30px 0 10px;font-size:16px;color:#0f3d5c;border-bottom:2px solid #0f3d5c;padding-bottom:6px">
                            Overall Ticket Summary ({{ $itsm['meta']['report_date'] ?? now(config('app.timezone'))->format('d-m-Y') }})
                        </h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-bottom:20px;font-size:13px">
                            <thead>
                                <tr>
                                    <th align="left" style="padding:9px 11px;background:#0f3d5c;color:#fff;border:1px solid #cbd5e1;width:70%">Category</th>
                                    <th align="right" style="padding:9px 11px;background:#0f3d5c;color:#fff;border:1px solid #cbd5e1">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="background:#f1f5f9">
                                    <td style="padding:9px 11px;border:1px solid #e2e8f0;font-weight:bold">Total Tickets</td>
                                    <td align="right" style="padding:9px 11px;border:1px solid #e2e8f0;font-weight:bold">{{ number_format($s['total'] ?? 0) }}</td>
                                </tr>

                                <tr><td colspan="2" style="padding:8px 11px;border:1px solid #e2e8f0;background:#e8eef4;font-weight:bold;color:#0f3d5c">Status</td></tr>
                                @forelse ($itsm['overall_ticket_summary'] ?? [] as $row)
                                    <tr>
                                        <td style="padding:8px 11px 8px 26px;border:1px solid #e2e8f0;color:#334155">{{ $row['label'] ?? 'Unknown' }}</td>
                                        <td align="right" style="padding:8px 11px;border:1px solid #e2e8f0;color:#334155">{{ number_format($row['value'] ?? 0) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" style="padding:9px 11px;border:1px solid #e2e8f0;color:#64748b">No status data available.</td></tr>
                                @endforelse

                                @if (filled($itsm['unresolved_by_type'] ?? []))
                                    <tr><td colspan="2" style="padding:8px 11px;border:1px solid #e2e8f0;background:#e8eef4;font-weight:bold;color:#0f3d5c">Type (unresolved)</td></tr>
                                    @foreach ($itsm['unresolved_by_type'] as $row)
                                        <tr>
                                            <td style="padding:8px 11px 8px 26px;border:1px solid #e2e8f0;color:#334155">{{ $row['label'] ?? 'Uncategorised' }}</td>
                                            <td align="right" style="padding:8px 11px;border:1px solid #e2e8f0;color:#334155">{{ number_format($row['value'] ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                @endif

                                @if (filled($itsm['unresolved_by_priority'] ?? []))
                                    <tr><td colspan="2" style="padding:8px 11px;border:1px solid #e2e8f0;background:#e8eef4;font-weight:bold;color:#0f3d5c">Priority (unresolved)</td></tr>
                                    @foreach ($itsm['unresolved_by_priority'] as $row)
                                        <tr>
                                            <td style="padding:8px 11px 8px 26px;border:1px solid #e2e8f0;color:#334155">{{ $row['label'] ?? 'Unknown' }}</td>
                                            <td align="right" style="padding:8px 11px;border:1px solid #e2e8f0;color:#334155">{{ number_format($row['value'] ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                @endif

                                @if (filled($itsm['unresolved_by_group'] ?? []))
                                    <tr><td colspan="2" style="padding:8px 11px;border:1px solid #e2e8f0;background:#e8eef4;font-weight:bold;color:#0f3d5c">Group (unresolved)</td></tr>
                                    @foreach ($itsm['unresolved_by_group'] as $row)
                                        <tr>
                                            <td style="padding:8px 11px 8px 26px;border:1px solid #e2e8f0;color:#334155">{{ $row['label'] ?? 'No group' }}</td>
                                            <td align="right" style="padding:8px 11px;border:1px solid #e2e8f0;color:#334155">{{ number_format($row['value'] ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>

                        {{-- 2. Unresolved P1 & P2 --}}
                        <h2 style="margin:30px 0 10px;font-size:16px;color:#0f3d5c;border-bottom:2px solid #0f3d5c;padding-bottom:6px">
                            Unresolved P1 &amp; P2 Tickets
                        </h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-bottom:20px;font-size:12px">
                            <thead>
                                <tr>
                                    @foreach (['Ticket Id', 'Group', 'Priority', 'Agent', 'Status', 'Age (days)', 'Subject'] as $header)
                                        <th align="left" style="padding:8px 9px;background:#b42318;color:#fff;border:1px solid #cbd5e1;white-space:nowrap">{{ $header }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($itsm['critical_tickets'] ?? [] as $ticket)
                                    <tr>
                                        <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155;white-space:nowrap">{{ $ticket['id'] }}</td>
                                        <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155">{{ $ticket['group'] }}</td>
                                        <td style="padding:7px 9px;border:1px solid #e2e8f0;white-space:nowrap;font-weight:bold;color:{{ strcasecmp($ticket['priority'], 'Urgent') === 0 ? '#b42318' : '#b54708' }}">{{ $ticket['priority'] }}</td>
                                        <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155">{{ $ticket['agent'] }}</td>
                                        <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155;white-space:nowrap">{{ $ticket['status'] }}</td>
                                        <td align="right" style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155">{{ number_format($ticket['pending_days']) }}</td>
                                        <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155">{{ $ticket['subject'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" style="padding:9px;border:1px solid #e2e8f0;color:#64748b">No unresolved Urgent or High priority tickets. Well done.</td></tr>
                                @endforelse
                            </tbody>
                        </table>

                        {{-- 3. SLA breached by group & agent --}}
                        <h2 style="margin:30px 0 10px;font-size:16px;color:#0f3d5c;border-bottom:2px solid #0f3d5c;padding-bottom:6px">
                            SLA Breached Tickets by Group &amp; Agent
                        </h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-bottom:20px;font-size:12px">
                            <thead>
                                <tr>
                                    @foreach (['Group', 'Agent', 'Ticket Id', 'Pending Days'] as $header)
                                        <th align="left" style="padding:8px 9px;background:#0f3d5c;color:#fff;border:1px solid #cbd5e1;white-space:nowrap">{{ $header }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($itsm['sla_breached_detail'] ?? [] as $group)
                                    @php $groupPrinted = false; @endphp
                                    @foreach ($group['agents'] as $agent)
                                        @php $agentPrinted = false; @endphp
                                        @foreach ($agent['tickets'] as $ticket)
                                            <tr>
                                                <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#0f3d5c;font-weight:{{ $groupPrinted ? 'normal' : 'bold' }}">{{ $groupPrinted ? '' : $group['group'] }}</td>
                                                <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155">{{ $agentPrinted ? '' : $agent['agent'] }}</td>
                                                <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155;white-space:nowrap">{{ $ticket['id'] }}</td>
                                                <td align="right" style="padding:7px 9px;border:1px solid #e2e8f0;font-weight:bold;color:{{ $ticket['pending_days'] >= 30 ? '#b42318' : ($ticket['pending_days'] >= 14 ? '#b54708' : '#334155') }}">{{ number_format($ticket['pending_days']) }}</td>
                                            </tr>
                                            @php $groupPrinted = true; $agentPrinted = true; @endphp
                                        @endforeach
                                    @endforeach
                                    <tr style="background:#f1f5f9">
                                        <td colspan="3" style="padding:7px 9px;border:1px solid #e2e8f0;font-weight:bold;color:#0f3d5c">{{ $group['group'] }} total</td>
                                        <td align="right" style="padding:7px 9px;border:1px solid #e2e8f0;font-weight:bold;color:#0f3d5c">{{ number_format($group['total']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" style="padding:9px;border:1px solid #e2e8f0;color:#64748b">No SLA breached tickets.</td></tr>
                                @endforelse
                            </tbody>
                        </table>

                        {{-- 4. All unresolved by agent (pivot) --}}
                        @php $matrix = $itsm['agent_status_matrix'] ?? ['columns' => [], 'rows' => [], 'grand_total' => 0]; @endphp
                        <h2 style="margin:30px 0 10px;font-size:16px;color:#0f3d5c;border-bottom:2px solid #0f3d5c;padding-bottom:6px">
                            All Unresolved Tickets by Agent
                        </h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-bottom:20px;font-size:12px">
                            <thead>
                                <tr>
                                    <th align="left" style="padding:8px 9px;background:#0f3d5c;color:#fff;border:1px solid #cbd5e1">Agent</th>
                                    @foreach ($matrix['columns'] as $column)
                                        <th align="right" style="padding:8px 9px;background:#0f3d5c;color:#fff;border:1px solid #cbd5e1;white-space:nowrap">{{ $column }}</th>
                                    @endforeach
                                    <th align="right" style="padding:8px 9px;background:#134e6f;color:#fff;border:1px solid #cbd5e1">Grand Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($matrix['rows'] as $row)
                                    <tr>
                                        <td style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155">{{ $row['agent'] }}</td>
                                        @foreach ($matrix['columns'] as $column)
                                            <td align="right" style="padding:7px 9px;border:1px solid #e2e8f0;color:#334155">{{ $row['counts'][$column] !== null ? number_format($row['counts'][$column]) : '' }}</td>
                                        @endforeach
                                        <td align="right" style="padding:7px 9px;border:1px solid #e2e8f0;font-weight:bold;color:#0f3d5c">{{ number_format($row['total']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ count($matrix['columns']) + 2 }}" style="padding:9px;border:1px solid #e2e8f0;color:#64748b">No unresolved tickets.</td></tr>
                                @endforelse
                                @if (filled($matrix['rows']))
                                    <tr style="background:#f1f5f9">
                                        <td style="padding:8px 9px;border:1px solid #e2e8f0;font-weight:bold;color:#0f3d5c">Grand Total</td>
                                        @foreach ($matrix['columns'] as $column)
                                            <td align="right" style="padding:8px 9px;border:1px solid #e2e8f0;font-weight:bold;color:#0f3d5c">{{ number_format(collect($matrix['rows'])->sum(fn ($r) => (int) ($r['counts'][$column] ?? 0))) }}</td>
                                        @endforeach
                                        <td align="right" style="padding:8px 9px;border:1px solid #e2e8f0;font-weight:bold;color:#0f3d5c">{{ number_format($matrix['grand_total']) }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>

                        {{-- Closing instructions --}}
                        <div style="margin:26px 0 0;padding:18px 20px;background:#fdf6e8;border-left:4px solid #b54708;border-radius:4px">
                            <p style="margin:0 0 12px;color:#374151;line-height:1.7;font-size:14px">
                                I kindly request all of you to look into the <strong>&ldquo;SLA Breached&rdquo;</strong> and
                                <strong>&ldquo;On-Hold&rdquo;</strong> tickets with topmost priority and arrange for their resolution as soon as possible.
                            </p>
                            <p style="margin:0 0 8px;color:#374151;line-height:1.7;font-size:14px">
                                Furthermore, kindly take some time each day before you leave to review all the tickets assigned to your queue:
                            </p>
                            <ul style="margin:0;padding-left:20px;color:#374151;line-height:1.75;font-size:14px">
                                <li style="margin-bottom:7px">Ensure proper housekeeping and ticket hygiene by adding notes for actions taken, updating the current status or progress with end users, and handling reassignments effectively.</li>
                                <li style="margin-bottom:7px">If no action is required, do not keep tickets in your queue. Reassign them to the appropriate action group or agent immediately, including a note with your comments, additional information, diagnosis, or troubleshooting steps taken.</li>
                                <li>Capture all communications with requesters and IT teams in the tickets for better tracking and analysis.</li>
                            </ul>
                        </div>

                        @if ($itsm['meta']['unresolved_ticket_limit_reached'] ?? false)
                            <p style="margin:16px 0 0;padding:11px 14px;background:#fdeceb;border-left:4px solid #b42318;color:#7a271a;font-size:12px;line-height:1.6">
                                Note: the ticket volume exceeded the analysis safety limit, so some tickets are not represented in the breakdowns above.
                                Refer to the attached {{ strtoupper($format) }} and Freshservice for the complete list.
                            </p>
                        @endif
                    @else
                        <p style="margin:0 0 20px;color:#475569;line-height:1.6;font-size:14px">
                            Your scheduled report has been refreshed and is attached as {{ strtoupper($format) }}.
                        </p>
                    @endif

                        {{-- Attachment note --}}
                        <div style="margin:22px 0 0;padding:13px 16px;background:#eef6f5;border-left:4px solid #19a7a0;font-size:13px;color:#334155">
                            <strong>{{ number_format($rowCount) }} report rows</strong> are included in the attached {{ strtoupper($format) }} file.
                        </div>

                        <p style="margin:22px 0 0">
                            <a href="{{ $reportUrl }}" style="display:inline-block;padding:11px 22px;background:#0f3d5c;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:13px">Open Ask GAHolding</a>
                        </p>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:16px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:11px;line-height:1.6">
                        Generated automatically by Ask GAHolding on
                        {{ now(config('app.timezone'))->format('d M Y, H:i') }} ({{ config('app.timezone') }}).
                        This report contains internal business information &mdash; please do not forward outside the organisation.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
