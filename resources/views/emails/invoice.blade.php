<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice Pesanan</title>
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
                <span style="color:#f5f3ef;font-size:24px;font-weight:bold;">🧾</span>
              </div>

              <h1 style="margin:16px 0 0;font-size:24px;font-weight:700;color:#2d2a24;letter-spacing:-0.025em;">
                Invoice Pesanan
              </h1>

              <p style="margin:8px 0 0;font-size:14px;color:#888580;line-height:1.5;">
                Terima kasih! Berikut detail invoice pesanan kamu.
              </p>
            </td>
          </tr>

          {{-- Invoice Badge --}}
          <tr>
            <td style="padding:24px 40px 0;text-align:center;">
              <div style="display:inline-block;background-color:#f5f3ef;border:1px solid #e8e5df;border-radius:999px;padding:8px 20px;">
                <span style="margin-right:8px;">📄</span>
                <span style="font-size:13px;color:#888580;">Invoice:&nbsp;</span>
                <span style="font-size:13px;font-weight:700;color:#2d2a24;">{{ $order->invoice_number ?? ('INV-' . $order->id) }}</span>
              </div>
            </td>
          </tr>

          {{-- Meta --}}
          <tr>
            <td style="padding:24px 40px 0;">
              <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;width:160px;">Tanggal</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;">
                    {{ optional($order->created_at)->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB
                  </td>
                </tr>
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;">Status Order</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;">
                    {{ is_object($order->status) ? ($order->status->value ?? '-') : ($order->status ?? '-') }}
                  </td>
                </tr>
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;">Metode Pembayaran</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;">
                    {{ $paymentMethod ?? ($order->payment_gateway_code ?? '-') }}
                  </td>
                </tr>
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;">Status Pembayaran</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;">
                    {{ $paymentStatus ?? '-' }}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Divider --}}
          <tr>
            <td style="padding:24px 40px;">
              <div style="height:1px;background-color:#e8e5df;"></div>
            </td>
          </tr>

          {{-- Items --}}
          <tr>
            <td style="padding:0 40px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e8e5df;border-radius:12px;overflow:hidden;">
                <thead>
                  <tr style="background-color:#faf9f7;">
                    <th style="padding:12px;border-bottom:1px solid #e8e5df;font-size:12px;color:#888580;text-align:left;">Produk</th>
                    <th style="padding:12px;border-bottom:1px solid #e8e5df;font-size:12px;color:#888580;text-align:center;width:70px;">Qty</th>
                    <th style="padding:12px;border-bottom:1px solid #e8e5df;font-size:12px;color:#888580;text-align:right;width:130px;">Harga</th>
                    <th style="padding:12px;border-bottom:1px solid #e8e5df;font-size:12px;color:#888580;text-align:right;width:140px;">Subtotal</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse($items as $item)
                    <tr>
                      <td style="padding:12px;border-bottom:1px solid #f0eee9;font-size:13px;color:#2d2a24;">
                        {{ $item['name'] ?? 'Product' }}
                      </td>
                      <td style="padding:12px;border-bottom:1px solid #f0eee9;font-size:13px;color:#2d2a24;text-align:center;">
                        {{ (int) ($item['qty'] ?? 1) }}
                      </td>
                      <td style="padding:12px;border-bottom:1px solid #f0eee9;font-size:13px;color:#2d2a24;text-align:right;">
                        Rp {{ number_format((float) ($item['price'] ?? 0), 0, ',', '.') }}
                      </td>
                      <td style="padding:12px;border-bottom:1px solid #f0eee9;font-size:13px;color:#2d2a24;text-align:right;">
                        Rp {{ number_format((float) ($item['subtotal'] ?? 0), 0, ',', '.') }}
                      </td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="4" style="padding:16px;text-align:center;color:#888580;font-size:13px;">
                        Tidak ada item.
                      </td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </td>
          </tr>

          {{-- Summary --}}
          @php
            $subtotal = (float) ($order->subtotal ?? 0);
            $discount = (float) ($order->discount_total ?? 0);
            $taxPercent = (float) ($order->tax_percent ?? 0);
            $taxAmount = (float) ($order->tax_amount ?? 0);

            // base total (tanpa fee gateway) -> wallet total
            $baseTotal = (float) ($order->amount ?? 0);

            $feePercent = (float) ($order->gateway_fee_percent ?? 0);
            $feeAmount = (float) ($order->gateway_fee_amount ?? 0);

            // customer bayar base + fee jika via gateway
            $gatewayTotal = $baseTotal + $feeAmount;

            $method = strtolower((string) ($paymentMethod ?? ($order->payment_gateway_code ?? '')));
            $isGateway = $method !== '' && $method !== '-';
            $showFee = $isGateway && $feeAmount > 0;
          @endphp

          <tr>
            <td style="padding:20px 40px 0;">
              <table style="margin-left:auto;min-width:320px;border-collapse:collapse;">
                <tr>
                  <td style="padding:6px 0;font-size:13px;color:#888580;">Subtotal</td>
                  <td style="padding:6px 0 6px 24px;font-size:13px;color:#2d2a24;text-align:right;">
                    Rp {{ number_format($subtotal, 0, ',', '.') }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:6px 0;font-size:13px;color:#888580;">Diskon</td>
                  <td style="padding:6px 0 6px 24px;font-size:13px;color:#2d2a24;text-align:right;">
                    - Rp {{ number_format($discount, 0, ',', '.') }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:6px 0;font-size:13px;color:#888580;">Pajak ({{ $taxPercent }}%)</td>
                  <td style="padding:6px 0 6px 24px;font-size:13px;color:#2d2a24;text-align:right;">
                    Rp {{ number_format($taxAmount, 0, ',', '.') }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:10px 0 0;font-size:14px;color:#2d2a24;font-weight:700;">
                    Total
                  </td>
                  <td style="padding:10px 0 0 24px;font-size:14px;color:#2d2a24;text-align:right;font-weight:700;">
                    Rp {{ number_format($baseTotal, 0, ',', '.') }}
                  </td>
                </tr>

                @if($showFee)
                  <tr>
                    <td style="padding:6px 0;font-size:13px;color:#888580;">
                      Biaya Payment Gateway ({{ $feePercent }}%)
                    </td>
                    <td style="padding:6px 0 6px 24px;font-size:13px;color:#2d2a24;text-align:right;">
                      Rp {{ number_format($feeAmount, 0, ',', '.') }}
                    </td>
                  </tr>

                  <tr>
                    <td style="padding:10px 0 0;font-size:14px;color:#2d2a24;font-weight:800;">
                      Total Dibayar
                    </td>
                    <td style="padding:10px 0 0 24px;font-size:14px;color:#2d2a24;text-align:right;font-weight:800;">
                      Rp {{ number_format($gatewayTotal, 0, ',', '.') }}
                    </td>
                  </tr>
                @endif
              </table>
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="padding:24px 40px 40px;text-align:center;">
              <div style="height:1px;background-color:#e8e5df;margin-bottom:20px;"></div>
              <p style="margin:0;font-size:12px;color:#888580;line-height:1.6;">
                Simpan email ini sebagai bukti transaksi. Jika ada kendala, silakan hubungi tim support GrowTech.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>