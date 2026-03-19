<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice Topup Wallet</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f3ef;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f3ef;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">

          <tr>
            <td style="padding:40px 40px 0;text-align:center;">
              <div style="display:inline-block;width:56px;height:56px;background-color:#2d2a24;border-radius:16px;line-height:56px;text-align:center;">
                <span style="color:#f5f3ef;font-size:24px;font-weight:bold;">💳</span>
              </div>

              <h1 style="margin:16px 0 0;font-size:24px;font-weight:700;color:#2d2a24;letter-spacing:-0.025em;">
                Invoice Topup Wallet
              </h1>

              <p style="margin:8px 0 0;font-size:14px;color:#888580;line-height:1.5;">
                Topup wallet kamu berhasil diproses. Berikut detail transaksinya.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding:24px 40px 0;text-align:center;">
              <div style="display:inline-block;background-color:#f5f3ef;border:1px solid #e8e5df;border-radius:999px;padding:8px 20px;">
                <span style="margin-right:8px;">📄</span>
                <span style="font-size:13px;color:#888580;">Order ID:&nbsp;</span>
                <span style="font-size:13px;font-weight:700;color:#2d2a24;">{{ $topup->order_id ?? ('TOPUP-' . $topup->id) }}</span>
              </div>
            </td>
          </tr>

          <tr>
            <td style="padding:24px 40px 0;">
              <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;width:180px;">Tanggal Dibuat</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;">
                    {{ $topup->created_at ? \Carbon\Carbon::parse($topup->created_at)->timezone('Asia/Jakarta')->format('d M Y H:i') . ' WIB' : '-' }}
                  </td>
                </tr>
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;">Tanggal Dibayar</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;">
                    {{ $topup->paid_at ? \Carbon\Carbon::parse($topup->paid_at)->timezone('Asia/Jakarta')->format('d M Y H:i') . ' WIB' : '-' }}
                  </td>
                </tr>
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;">Metode Pembayaran</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;">
                    {{ $paymentMethod ?? ($topup->gateway_code ?? '-') }}
                  </td>
                </tr>
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;">Status Pembayaran</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;text-transform:capitalize;">
                    {{ $paymentStatus ?? ($topup->status ?? '-') }}
                  </td>
                </tr>
                <tr>
                  <td style="padding:6px 0;color:#888580;font-size:13px;">External ID</td>
                  <td style="padding:6px 0;color:#2d2a24;font-size:13px;font-weight:600;">
                    {{ $topup->external_id ?? '-' }}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:24px 40px;">
              <div style="height:1px;background-color:#e8e5df;"></div>
            </td>
          </tr>

          <tr>
            <td style="padding:0 40px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e8e5df;border-radius:12px;overflow:hidden;">
                <thead>
                  <tr style="background-color:#faf9f7;">
                    <th style="padding:12px;border-bottom:1px solid #e8e5df;font-size:12px;color:#888580;text-align:left;">Deskripsi</th>
                    <th style="padding:12px;border-bottom:1px solid #e8e5df;font-size:12px;color:#888580;text-align:right;width:180px;">Nominal</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td style="padding:14px;border-bottom:1px solid #f0eee9;font-size:13px;color:#2d2a24;">
                      Topup saldo wallet
                    </td>
                    <td style="padding:14px;border-bottom:1px solid #f0eee9;font-size:13px;color:#2d2a24;text-align:right;">
                      Rp {{ number_format((float) ($topup->amount ?? 0), 0, ',', '.') }}
                    </td>
                  </tr>
                  @if ((float) ($topup->gateway_fee_amount ?? 0) > 0)
                  <tr>
                    <td style="padding:14px;border-bottom:1px solid #f0eee9;font-size:13px;color:#2d2a24;">
                      Fee admin / gateway
                      @if ((float) ($topup->gateway_fee_percent ?? 0) > 0)
                        ({{ rtrim(rtrim(number_format((float) $topup->gateway_fee_percent, 3, '.', ''), '0'), '.') }}%)
                      @endif
                    </td>
                    <td style="padding:14px;border-bottom:1px solid #f0eee9;font-size:13px;color:#2d2a24;text-align:right;">
                      Rp {{ number_format((float) ($topup->gateway_fee_amount ?? 0), 0, ',', '.') }}
                    </td>
                  </tr>
                  @endif
                </tbody>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:20px 40px 0;">
              <table style="margin-left:auto;min-width:320px;border-collapse:collapse;">
                <tr>
                  <td style="padding:2px 0;font-size:13px;color:#888580;">Nominal Masuk Wallet</td>
                  <td style="padding:2px 0 2px 24px;font-size:13px;color:#2d2a24;text-align:right;font-weight:600;">
                    Rp {{ number_format((float) ($topup->amount ?? 0), 0, ',', '.') }}
                  </td>
                </tr>
                @if ((float) ($topup->gateway_fee_amount ?? 0) > 0)
                <tr>
                  <td style="padding:2px 0;font-size:13px;color:#888580;">Fee Admin</td>
                  <td style="padding:2px 0 2px 24px;font-size:13px;color:#2d2a24;text-align:right;font-weight:600;">
                    Rp {{ number_format((float) ($topup->gateway_fee_amount ?? 0), 0, ',', '.') }}
                  </td>
                </tr>
                @endif
                <tr>
                  <td style="padding:10px 0 0;font-size:14px;color:#2d2a24;font-weight:800;">
                    Total Dibayar
                  </td>
                  <td style="padding:10px 0 0 24px;font-size:14px;color:#2d2a24;text-align:right;font-weight:800;">
                    Rp {{ number_format((float) (($topup->amount ?? 0) + ($topup->gateway_fee_amount ?? 0)), 0, ',', '.') }}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:24px 40px 40px;text-align:center;">
              <div style="height:1px;background-color:#e8e5df;margin-bottom:20px;"></div>
              <p style="margin:0;font-size:12px;color:#888580;line-height:1.6;">
                Simpan email ini sebagai bukti transaksi topup wallet. Jika ada kendala, silakan hubungi tim support GrowTech.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>