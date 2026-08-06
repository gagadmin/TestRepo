<h2 style="margin:22px 0 8px;font-size:16px;color:#17324f">{{ $title }}</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-bottom:18px;font-size:12px">
    <thead>
        <tr>
            @foreach ($headers as $header)
                <th align="left" style="padding:9px;background:#146c94;color:#fff;border:1px solid #dce7e4">{{ $header }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                @foreach ($row as $cell)
                    <td style="padding:8px;border:1px solid #dce7e4;color:#334155">{{ $cell }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($headers) }}" style="padding:10px;border:1px solid #dce7e4;color:#64748b">No matching tickets.</td>
            </tr>
        @endforelse
    </tbody>
</table>
