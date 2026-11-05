<!DOCTYPE html>
<html lang="id">
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
    <p>Halo Admin,</p>
    <p>
        Stok produk <strong>{{ $alert->product->product_code }} — {{ $alert->product->product_name }}</strong>
        turun menjadi <strong>{{ $alert->stock_at_alert }}</strong>, di bawah batas minimum {{ $alert->threshold }}.
    </p>
    <p>Silakan lakukan restock atau penyesuaian stok.</p>
</body>
</html>
