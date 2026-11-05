<!DOCTYPE html>
<html lang="id">
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
    <p>Halo {{ $order->customer->customer_name }},</p>
    <p>
        Terima kasih, pembayaran untuk order <strong>{{ $order->order_number }}</strong>
        sebesar <strong>Rp {{ number_format((float) $order->total_amount, 2, ',', '.') }}</strong> telah kami terima.
    </p>
    <p>Invoice terlampir pada e-mail ini.</p>
    <p>Salam,<br>Azatech Online Shop</p>
</body>
</html>
