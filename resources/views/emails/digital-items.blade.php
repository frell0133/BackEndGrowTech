<!doctype html>
<html>
  <body>
    <h2>Pesanan Digital Kamu</h2>
    <p>Order ID: <b>#{{ $order->id }}</b></p>
    <hr>

    <ul>
    @foreach($items as $item)
      <li style="margin-bottom: 12px;">
        @if(!empty($item['product_name']))
          <div><b>Produk:</b> {{ $item['product_name'] }}</div>
        @endif

        <div><b>License Key:</b> {{ $item['license_key'] ?? '(kosong)' }}</div>

        @if(!empty($item['payload']))
          <div style="margin-top:6px;">
            <b>Detail:</b>
            <pre style="background:#111;color:#ddd;padding:10px;border-radius:8px;white-space:pre-wrap;">
    {{ is_array($item['payload']) ? json_encode($item['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $item['payload'] }}
            </pre>
          </div>
        @endif
      </li>
    @endforeach
    </ul>

    <p>Terima kasih.</p>
  </body>
</html>
