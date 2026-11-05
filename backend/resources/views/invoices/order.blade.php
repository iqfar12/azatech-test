<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->order_number }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; margin: 32px; }
        h1 { font-size: 22px; margin: 0; }
        .muted { color: #6b7280; font-size: 13px; }
        .row { display: flex; justify-content: space-between; margin-top: 24px; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 14px; }
        th, td { padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.num, th.num { text-align: right; }
        tfoot td { font-weight: bold; border-bottom: none; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; background: #dcfce7; color: #166534; font-size: 12px; }
    </style>
</head>
<body>
    <div class="row" style="margin-top:0">
        <div>
            <h1>INVOICE</h1>
            <div class="muted">PT Laksmada Aza Simetri — Online Shop</div>
        </div>
        <div style="text-align:right">
            <div><strong>{{ $order->order_number }}</strong></div>
            <div class="muted">Tanggal order: {{ $order->order_date->setTimezone($timezone)->format('d M Y H:i') }}</div>
            @if ($order->paid_at)
                <div class="muted">Dibayar: {{ $order->paid_at->setTimezone($timezone)->format('d M Y H:i') }}</div>
            @endif
            <span class="badge">{{ $order->status->value }}</span>
        </div>
    </div>

    <div class="row">
        <div>
            <div class="muted">Ditagihkan kepada</div>
            <div><strong>{{ $order->customer->customer_name }}</strong></div>
            <div>{{ $order->customer->customer_address }}</div>
            @if ($order->customer->phone)<div>{{ $order->customer->phone }}</div>@endif
            @if ($order->customer->email)<div>{{ $order->customer->email }}</div>@endif
        </div>
        <div style="text-align:right">
            <div class="muted">Kasir</div>
            <div>{{ $order->creator->name }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Produk</th>
                <th class="num">Qty</th>
                <th class="num">Harga Satuan</th>
                <th class="num">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->product_code }}</td>
                    <td>{{ $item->product_name }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">Rp {{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                    <td class="num">Rp {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="num">Total</td>
                <td class="num">Rp {{ number_format((float) $order->total_amount, 2, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="muted" style="margin-top:32px">Dokumen ini dibuat otomatis oleh sistem dan sah tanpa tanda tangan.</p>
</body>
</html>
