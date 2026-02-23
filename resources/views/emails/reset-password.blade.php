<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password</title>
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
                <span style="color:#f5f3ef;font-size:24px;">&#128274;</span>
              </div>
              <h1 style="margin:16px 0 0;font-size:24px;font-weight:700;color:#2d2a24;letter-spacing:-0.025em;">
                Reset Password
              </h1>
              <p style="margin:12px 0 0;font-size:14px;color:#888580;line-height:1.6;">
                Kami menerima permintaan untuk mereset password akunmu.<br>
                Klik tombol di bawah untuk membuat password baru.
              </p>
            </td>
          </tr>

          {{-- Divider --}}
          <tr>
            <td style="padding:28px 40px;">
              <div style="height:1px;background-color:#e8e5df;"></div>
            </td>
          </tr>

          {{-- Info Card --}}
          <tr>
            <td style="padding:0 40px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e8e5df;border-radius:12px;overflow:hidden;">

                {{-- Account Info (optional) --}}
                @if(isset($user) && !empty($user->email))
                <tr>
                  <td style="padding:14px 20px;background-color:#faf9f7;border-bottom:1px solid #e8e5df;">
                    <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#888580;">Informasi Akun</span>
                    <div style="margin-top:4px;font-size:14px;font-weight:600;color:#2d2a24;">{{ $user->email }}</div>
                  </td>
                </tr>
                @endif

                {{-- Expiry + CTA --}}
                <tr>
                  <td style="padding:24px 20px;text-align:center;">
                    <p style="margin:0 0 20px;font-size:14px;color:#888580;line-height:1.6;">
                      Link reset password ini hanya berlaku selama
                      <strong style="color:#2d2a24;">60 menit</strong>.
                      Setelah itu, kamu perlu membuat permintaan baru.
                    </p>

                    {{-- Button --}}
                    <a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 32px;background-color:#2d2a24;color:#f5f3ef;font-size:14px;font-weight:600;text-decoration:none;border-radius:12px;letter-spacing:0.01em;">
                      Reset Password Sekarang
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Alternative Link --}}
          <tr>
            <td style="padding:20px 40px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px dashed #e8e5df;border-radius:12px;background-color:#faf9f7;">
                <tr>
                  <td style="padding:16px 20px;">
                    <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#888580;">Atau salin link berikut</span>
                    <div style="margin-top:8px;padding:10px 14px;background-color:#f0ede8;border-radius:8px;font-family:'Courier New',Courier,monospace;font-size:12px;color:#888580;line-height:1.5;word-break:break-all;">
                      {{ $resetUrl }}
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Security Notice --}}
          <tr>
            <td style="padding:20px 40px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #f5deb3;border-radius:12px;background-color:#fef9ec;">
                <tr>
                  <td style="padding:16px 20px;">
                    <table role="presentation" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="vertical-align:top;padding-right:12px;">
                          <div style="width:24px;height:24px;background-color:#fdefc3;border-radius:50%;text-align:center;line-height:24px;">
                            <span style="font-size:12px;font-weight:700;color:#92680a;">!</span>
                          </div>
                        </td>
                        <td>
                          <p style="margin:0;font-size:13px;font-weight:600;color:#92680a;">
                            Perhatian Keamanan
                          </p>
                          <p style="margin:6px 0 0;font-size:12px;color:#a07c1c;line-height:1.5;">
                            Jika kamu tidak merasa meminta reset password, abaikan email ini. Akunmu akan tetap aman dan tidak ada perubahan yang dilakukan.
                          </p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="padding:28px 40px 40px;text-align:center;">
              <div style="height:1px;background-color:#e8e5df;margin-bottom:24px;"></div>
              <p style="margin:0;font-size:14px;font-weight:500;color:#2d2a24;">
                Butuh bantuan?
              </p>
              <p style="margin:6px 0 0;font-size:12px;color:#888580;line-height:1.5;">
                Hubungi tim support kami jika kamu mengalami kendala. Kami siap membantu kapan saja.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>