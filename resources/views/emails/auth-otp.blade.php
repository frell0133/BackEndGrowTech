<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kode OTP</title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="background:#111827;color:#ffffff;padding:24px 32px;">
                            <h1 style="margin:0;font-size:22px;">{{ $appName }}</h1>
                            <p style="margin:8px 0 0 0;font-size:14px;opacity:.9;">{{ $purposeLabel }}</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px 0;font-size:15px;color:#111827;">
                                Gunakan kode OTP berikut untuk melanjutkan proses autentikasi akun kamu:
                            </p>

                            <div style="margin:24px 0;padding:18px 20px;border-radius:12px;background:#f3f4f6;text-align:center;">
                                <span style="font-size:34px;letter-spacing:8px;font-weight:700;color:#111827;">{{ $otp }}</span>
                            </div>

                            <p style="margin:0 0 12px 0;font-size:14px;color:#374151;">
                                Kode ini berlaku selama <strong>{{ $expiresInMinutes }} menit</strong>.
                            </p>

                            <p style="margin:0 0 12px 0;font-size:14px;color:#374151;">
                                Jangan bagikan kode ini kepada siapa pun, termasuk admin.
                            </p>

                            <p style="margin:24px 0 0 0;font-size:13px;color:#6b7280;">
                                Jika kamu tidak merasa melakukan login atau registrasi ini, abaikan email ini.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 32px;background:#f9fafb;font-size:12px;color:#6b7280;">
                            © {{ date('Y') }} {{ $appName }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>