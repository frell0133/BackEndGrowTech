<!doctype html>
<html>
  <body>
    <h3>Pesanan Digital Kamu</h3>
    <p>Order ID: <b>#{{ $order->id }}</b></p>

    <hr/>

    <p>Berikut data digital kamu:</p>

    <ul>
      @foreach($items as $item)
        <li style="margin-bottom:10px;">
          <b>Item</b><br/>
          @if(!empty($item['license_key']))
            <div><b>License Key:</b> {{ $item['license_key'] }}</div>
          @endif

          @if(!empty($item['payload']))
            <div><b>Payload:</b></div>
            <pre style="background:#f3f3f3;padding:10px;">{{ json_encode($item['payload'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
          @endif
        </li>
      @endforeach
    </ul>

    <hr/>
    <p>Terima kasih.</p>
  </body>
</html>
