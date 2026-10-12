<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .sub { color: #555; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 7px 8px; border-bottom: 1.5px solid #999; background: #f3f4f6; }
        td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; }
        .summary { margin-top: 14px; }
        .summary div { margin-bottom: 3px; }
        .footer { margin-top: 18px; color: #888; font-size: 9px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    @if(!empty($subtitle))
        <div class="sub">{{ $subtitle }}</div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach($headings as $i => $heading)
                    @php $numeric = isset($rows[0][$i]) && is_numeric($rows[0][$i]); @endphp
                    <th class="{{ $numeric ? 'num' : '' }}">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td class="{{ is_numeric($cell) ? 'num' : '' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headings) }}">No data found.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(!empty($summary))
        <div class="summary">
            @foreach($summary as $label => $value)
                <div><strong>{{ $label }}:</strong> {{ $value }}</div>
            @endforeach
        </div>
    @endif

    <div class="footer">Generated on {{ now()->format('d M Y, h:i A') }}</div>
</body>
</html>