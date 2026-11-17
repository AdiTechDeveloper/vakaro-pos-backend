<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sales Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h2, p { text-align: center; margin: 5px 0; }
        .summary { margin: 18px 0; }
        .summary td { padding: 5px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 5px; }
        th { background: #eee; }
        .number { text-align: right; }
        .channels { width: 55%; margin: 0 0 14px; }
    </style>
</head>
<body>
    <h2>Sales Report</h2>
    <p>
        Period: {{ $from }} to {{ $to }}
    </p>

    <table class="summary">
        <tr>
            <td>Total Bills: {{ $kpis['total_bills'] }}</td>
            <td>Gross Sales: ₹{{ number_format($kpis['gross_sales'], 2) }}</td>
            <td>Profit: ₹{{ number_format($kpis['total_profit'], 2) }}</td>
        </tr>
        <tr>
            <td>Collected: ₹{{ number_format($kpis['total_collected'], 2) }}</td>
            <td>Due: ₹{{ number_format($kpis['total_due'], 2) }}</td>
            <td>GST: ₹{{ number_format($kpis['total_gst'], 2) }}</td>
        </tr>
    </table>

    <table class="channels">
        <thead>
            <tr>
                <th>Channel</th>
                <th>Bills</th>
                <th>Sales</th>
                <th>Profit</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($channelSummary as $name => $c)
                <tr>
                    <td>{{ $name === 'POS' ? 'POS (store)' : 'Online' }}</td>
                    <td class="number">{{ $c['bills'] }}</td>
                    <td class="number">₹{{ number_format($c['total'], 2) }}</td>
                    <td class="number">₹{{ number_format($c['profit'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th>Bill No</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Channel</th>
                <th>Subtotal</th>
                <th>GST</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Due</th>
                <th>Profit</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row->bill_no }}</td>
                    <td>{{ $row->created_at }}</td>
                    <td>{{ $row->customer_name ?? 'Walk-in Customer' }}</td>
                    <td>{{ $row->channel ?? 'POS' }}</td>
                    <td class="number">{{ number_format((float) $row->subtotal, 2) }}</td>
                    <td class="number">{{ number_format((float) $row->total_gst, 2) }}</td>
                    <td class="number">{{ number_format((float) $row->total_amount, 2) }}</td>
                    <td class="number">{{ number_format((float) $row->paid_amount, 2) }}</td>
                    <td class="number">{{ number_format((float) $row->due_amount, 2) }}</td>
                    <td class="number">{{ number_format((float) $row->total_profit, 2) }}</td>
                    <td>{{ $row->payment_status }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11">No sales found for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>