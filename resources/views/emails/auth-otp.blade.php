<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode OTP Grow Tech</title>
</head>
<body style="margin:0;padding:0;background-color:#0b0d10;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0b0d10;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#111418;border:1px solid #1f2937;border-radius:20px;overflow:hidden;">

                    {{-- Header --}}
                    <tr>
                        <td style="padding:40px 40px 0 40px;text-align:center;">
                            <p style="margin:0;font-size:12px;line-height:1.6;letter-spacing:0.22em;text-transform:uppercase;color:#9ca3af;">
                                Secure Verification
                            </p>

                            <h1 style="margin:12px 0 0 0;font-size:30px;line-height:1.25;font-weight:700;color:#f9fafb;letter-spacing:-0.03em;">
                                Grow Tech
                            </h1>

                            <p style="margin:10px 0 0 0;font-size:14px;line-height:1.7;color:#94a3b8;">
                                {{ $purposeLabel }}
                            </p>
                        </td>
                    </tr>

                    {{-- Divider --}}
                    <tr>
                        <td style="padding:28px 40px 0 40px;">
                            <div style="height:1px;background:linear-gradient(90deg,#111418 0%,#2b3440 50%,#111418 100%);"></div>
                        </td>
                    </tr>

                    {{-- Intro --}}
                    <tr>
                        <td style="padding:28px 40px 0 40px;text-align:center;">
                            <p style="margin:0;font-size:15px;line-height:1.8;color:#d1d5db;">
                                Gunakan kode OTP berikut untuk melanjutkan proses verifikasi akun kamu.
                            </p>
                        </td>
                    </tr>

                    {{-- OTP Card --}}
                    <tr>
                        <td style="padding:28px 40px 0 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(180deg,#161b22 0%,#101419 100%);border:1px solid #2a3340;border-radius:18px;">
                                <tr>
                                    <td style="padding:28px 24px 20px 24px;text-align:center;">
                                        <p style="margin:0 0 10px 0;font-size:12px;line-height:1.6;letter-spacing:0.18em;text-transform:uppercase;color:#6b7280;">
                                            One Time Password
                                        </p>

                                        <span style="display:inline-block;font-family:'Courier New',Courier,monospace;font-size:36px;line-height:1.1;font-weight:700;letter-spacing:0.28em;color:#f9fafb;">
                                            {{ $otp }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 20px;border-top:1px solid #26303b;text-align:center;background-color:#0f1318;border-bottom-left-radius:18px;border-bottom-right-radius:18px;">
                                        <span style="font-size:13px;line-height:1.6;color:#9ca3af;">
                                            Berlaku selama <strong style="color:#ffffff;">{{ $expiresInMinutes }} menit</strong>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Warning --}}
                    <tr>
                        <td style="padding:24px 40px 0 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#16120c;border:1px solid #3a2d16;border-radius:14px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0;font-size:14px;line-height:1.6;font-weight:700;color:#fbbf24;">
                                            Penting untuk keamanan akun
                                        </p>
                                        <p style="margin:8px 0 0 0;font-size:13px;line-height:1.7;color:#f3e8c8;">
                                            Jangan bagikan kode ini kepada siapa pun, termasuk pihak yang mengaku sebagai admin, tim Grow Tech, atau customer service.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:32px 40px 40px 40px;text-align:center;">
                            <div style="height:1px;background:linear-gradient(90deg,#111418 0%,#2b3440 50%,#111418 100%);margin-bottom:24px;"></div>

                            <p style="margin:0;font-size:12px;line-height:1.8;color:#94a3b8;">
                                Jika kamu tidak merasa melakukan permintaan ini, abaikan email ini. Akun kamu tetap aman.
                            </p>

                            <p style="margin:16px 0 0 0;font-size:12px;line-height:1.6;color:#6b7280;">
                                &copy; {{ date('Y') }} Grow Tech. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>