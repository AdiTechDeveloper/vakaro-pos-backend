
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

    <table>
        <thead>
            <tr>
                <th>Bill No</th>
                <th>Date</th>
                <th>Customer</th>
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
                    <td colspan="10">No sales found for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
