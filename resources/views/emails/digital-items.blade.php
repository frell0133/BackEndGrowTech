<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pesanan Digital Kamu</title>
</head>

<body style="margin:0;padding:0;background-color:#f5f3ef;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f3ef;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">

          {{-- Header --}}
          <tr>
            <td style="padding:40px 40px 0;text-align:center;">
              <div style="display:inline-block;width:56px;height:56px;background-color:#2d2a24;border-radius:16px;line-height:56px;text-align:center;">
                <span style="color:#f5f3ef;font-size:24px;font-weight:bold;">📦</span>
              </div>

              <h1 style="margin:16px 0 0;font-size:24px;font-weight:700;color:#2d2a24;letter-spacing:-0.025em;">
                Pesanan Digital Kamu
              </h1>

              <p style="margin:8px 0 0;font-size:14px;color:#888580;line-height:1.5;">
                Terima kasih atas pembelianmu! Berikut detail pesanan digitalmu.
              </p>
            </td>
          </tr>

          {{-- Invoice Badge --}}
          <tr>
            <td style="padding:24px 40px 0;text-align:center;">
              <div style="display:inline-block;background-color:#f5f3ef;border:1px solid #e8e5df;border-radius:999px;padding:8px 20px;">
                <span style="margin-right:8px;">📄</span>
                <span style="font-size:13px;color:#888580;">Invoice:&nbsp;</span>
                <span style="font-size:13px;font-weight:700;color:#2d2a24;">{{ $order->invoice_number }}</span>
              </div>
            </td>
          </tr>

          {{-- Divider --}}
          <tr>
            <td style="padding:24px 40px;">
              <div style="height:1px;background-color:#e8e5df;"></div>
            </td>
          </tr>

          {{-- =========================
               ITEMS (GROUPED PER PRODUCT)
               ========================= --}}
          <tr>
            <td style="padding:0 40px;">

              @php
                // Group items per product.
                // Prefer product_id if exists, fallback to product_name.
                $grouped = collect($items)->groupBy(function ($it) {
                    return $it['product_id'] ?? ($it['product_name'] ?? 'Produk');
                });
              @endphp

              @foreach($grouped as $groupKey => $rows)
                @php
                  // product name: take from first row
                  $first = $rows->first();
                  $productName = $first['product_name'] ?? 'Produk';

                  // license keys: list keys according to qty (each row expected per license)
                  $licenseKeys = collect($rows)
                      ->pluck('license_key')
                      ->filter(fn($k) => !empty($k))
                      ->values();

                  // payload: if multiple, pick first non-empty
                  $payload = collect($rows)
                      ->pluck('payload')
                      ->first(fn($p) => !empty($p));

                  $qty = $rows->count(); // qty per product = number of rows/licenses
                @endphp

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="margin-bottom:20px;border:1px solid #e8e5df;border-radius:12px;overflow:hidden;">

                  {{-- Product Name --}}
                  <tr>
                    <td style="padding:14px 20px;background-color:#faf9f7;border-bottom:1px solid #e8e5df;">
                      <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#888580;">
                        Produk
                      </span>
                      <div style="margin-top:4px;font-size:14px;font-weight:600;color:#2d2a24;">
                        {{ $productName }}
                        <span style="font-size:12px;color:#888580;font-weight:500;">(Qty: {{ $qty }})</span>
                      </div>
                    </td>
                  </tr>

                  {{-- License Keys (LIST) --}}
                  <tr>
                    <td style="padding:16px 20px;">
                      <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#888580;">
                        License Key
                      </span>

                      @if($licenseKeys->isEmpty())
                        <div style="margin-top:8px;padding:10px 14px;background-color:#faf9f7;border:1px dashed #e8e5df;border-radius:8px;font-family:'Courier New',Courier,monospace;font-size:14px;font-weight:600;color:#2d2a24;letter-spacing:0.025em;word-break:break-all;">
                          (kosong)
                        </div>
                      @else
                        <div style="margin-top:8px;">
                          @foreach($licenseKeys as $key)
                            <div style="margin-bottom:8px;padding:10px 14px;background-color:#faf9f7;border:1px dashed #e8e5df;border-radius:8px;font-family:'Courier New',Courier,monospace;font-size:14px;font-weight:600;color:#2d2a24;letter-spacing:0.025em;word-break:break-all;">
                              {{ $key }}
                            </div>
                          @endforeach
                        </div>
                      @endif
                    </td>
                  </tr>

                  {{-- Payload --}}
                  @if(!empty($payload))
                    <tr>
                      <td style="padding:0 20px 16px;">
                        <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#888580;">
                          Detail Tambahan
                        </span>
                        <pre style="margin:8px 0 0;padding:14px 16px;background-color:#1a1a2e;color:#e0e0e8;border-radius:8px;font-family:'Courier New',Courier,monospace;font-size:12px;line-height:1.6;white-space:pre-wrap;word-break:break-all;overflow-x:auto;">{{ is_array($payload) ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $payload }}</pre>
                      </td>
                    </tr>
                  @endif

                </table>
              @endforeach

            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="padding:8px 40px 40px;text-align:center;">
              <div style="height:1px;background-color:#e8e5df;margin-bottom:24px;"></div>

              <p style="margin:0;font-size:14px;font-weight:500;color:#2d2a24;">
                Terima kasih atas kepercayaanmu!
              </p>

              <p style="margin:6px 0 0;font-size:12px;color:#888580;line-height:1.5;">
                Simpan email ini sebagai bukti pembelian. Jika ada kendala, jangan ragu untuk menghubungi kami.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>