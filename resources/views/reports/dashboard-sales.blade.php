
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Report</title>

    <style>
        @page {
            margin: 24px 20px;
        }

        * {
            font-family: DejaVu Sans, sans-serif;
        }

        body {
            font-size: 10px;
            color: #111827;
            margin: 0;
        }

        h1 {
            font-size: 20px;
            margin: 0 0 3px;
        }

        h2 {
            font-size: 13px;
            margin: 18px 0 6px;
            page-break-after: avoid;
        }

        .muted {
            color: #6b7280;
        }

        .r {
            text-align: right;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f1f5f9;
            text-align: left;
            font-size: 9px;
            padding: 5px 6px;
            border-bottom: 1px solid #cbd5e1;
        }

        td {
            padding: 4px 6px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .kv td:first-child {
            width: 40%;
            color: #4b5563;
        }

        .kv td:last-child {
            font-weight: bold;
        }

        .small td,
        .small th {
            font-size: 8px;
            padding: 4px 4px;
        }

        .small thead {
            display: table-header-group;
        }

        .small tr {
            page-break-inside: avoid;
        }

        .note {
            margin-top: 8px;
            font-size: 9px;
            color: #6b7280;
        }

        .empty {
            text-align: center;
            padding: 12px;
            color: #6b7280;
        }
    </style>
</head>

<body>
    <h1>Sales Report</h1>

    <div class="muted">
        {{ $periodText }} &middot; {{ $branchName }}
    </div>

    {{-- Summary --}}
    <h2>Summary</h2>

    <table class="kv">
        <tbody>
            @foreach ($summary as $item)
                @php
                    [$label, $value, $type] = $item;
                @endphp

                <tr>
                    <td>{{ $label }}</td>
                    <td>
                        @if ($type === 'money')
                            {{ $fmt($value) }}
                        @elseif ($type === 'int')
                            {{ number_format((float) $value) }}
                        @else
                            {{ $value }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Breakdown --}}
    <h2>Breakdown</h2>

    <table>
        <thead>
            <tr>
                <th>Period</th>
                <th class="r">Sales</th>
                <th class="r">Bills</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($breakdown as $item)
                @php
                    [$label, $total, $billTotal] = $item;
                @endphp

                <tr>
                    <td>{{ $label }}</td>
                    <td class="r">{{ $fmt($total) }}</td>
                    <td class="r">{{ $billTotal }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="empty">
                        No breakdown data available.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Bills --}}
    <h2>Bills</h2>

    <table class="small">
        <thead>
            <tr>
                <th>Bill No</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Branch</th>
                <th class="r">Subtotal</th>
                <th class="r">GST</th>
                <th class="r">Total</th>
                <th class="r">Paid</th>
                <th class="r">Due</th>
                <th class="r">Profit</th>
                <th>Status</th>
                <th>Payment</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($billRows as $b)
                <tr>
                    <td>{{ $b['bill_no'] ?? '-' }}</td>
                    <td>{{ $b['date'] ?? '-' }}</td>
                    <td>{{ $b['customer'] ?? '-' }}</td>
                    <td>{{ $b['branch'] ?? '-' }}</td>

                    <td class="r">
                        {{ $fmt($b['subtotal'] ?? 0) }}
                    </td>

                    <td class="r">
                        {{ $fmt($b['gst'] ?? 0) }}
                    </td>

                    <td class="r">
                        {{ $fmt($b['total'] ?? 0) }}
                    </td>

                    <td class="r">
                        {{ $fmt($b['paid'] ?? 0) }}
                    </td>

                    <td class="r">
                        {{ $fmt($b['due'] ?? 0) }}
                    </td>

                    <td class="r">
                        {{ $fmt($b['profit'] ?? 0) }}
                    </td>

                    <td>
                        {{ $b['payment_status'] ?? '-' }}
                    </td>

                    <td>
                        {{ $b['methods'] ?? '-' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="empty">
                        No bills found for the selected period.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Bill count note --}}
    @if ($billCount > count($billRows))
        <div class="note">
            Showing the first {{ count($billRows) }} of
            {{ number_format($billCount) }} bills.
            Download the Excel report to get every bill.
        </div>
    @endif

    <div class="note">
        Generated on {{ $generatedAt }}
    </div>
</body>
</html>
