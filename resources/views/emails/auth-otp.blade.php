<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode OTP</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f3ef;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f3ef;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">
                    
                    {{-- Header --}}
                    <tr>
                        <td style="padding:40px 40px 0 40px;text-align:center;">
                            <table role="presentation" cellpadding="0" cellspacing="0" align="center">
                                <tr>
                                    <td align="center">
                                        <div style="display:inline-block;width:56px;height:56px;background-color:#2d2a24;border-radius:16px;line-height:56px;text-align:center;">
                                            <span style="color:#f5f3ef;font-size:24px;">&#128737;</span>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <h1 style="margin:16px 0 0 0;font-size:24px;line-height:1.3;font-weight:700;color:#2d2a24;letter-spacing:-0.02em;">
                                {{ $appName }}
                            </h1>

                            <p style="margin:8px 0 0 0;font-size:14px;line-height:1.6;color:#888580;">
                                {{ $purposeLabel }}
                            </p>
                        </td>
                    </tr>

                    {{-- Divider --}}
                    <tr>
                        <td style="padding:24px 40px;">
                            <div style="height:1px;background-color:#e8e5df;"></div>
                        </td>
                    </tr>

                    {{-- Message --}}
                    <tr>
                        <td style="padding:0 40px;text-align:center;">
                            <p style="margin:0;font-size:15px;line-height:1.7;color:#2d2a24;">
                                Gunakan kode OTP berikut untuk melanjutkan proses verifikasi akun kamu:
                            </p>
                        </td>
                    </tr>

                    {{-- OTP Box --}}
                    <tr>
                        <td style="padding:24px 40px;text-align:center;">
                            <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="border:1px solid #e8e5df;border-radius:16px;overflow:hidden;">
                                <tr>
                                    <td style="padding:24px 32px;background-color:#faf9f7;text-align:center;">
                                        <span style="font-family:'Courier New',Courier,monospace;font-size:36px;line-height:1;font-weight:700;letter-spacing:0.30em;color:#2d2a24;">
                                            {{ $otp }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 20px;background-color:#f5f3ef;border-top:1px solid #e8e5df;text-align:center;">
                                        <span style="font-size:13px;line-height:1.5;color:#888580;">
                                            Berlaku <strong style="color:#2d2a24;">{{ $expiresInMinutes }} menit</strong>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Warning --}}
                    <tr>
                        <td style="padding:0 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fef9e7;border:1px solid #fde68a;border-radius:12px;overflow:hidden;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td width="44" valign="top" style="padding-right:12px;">
                                                    <div style="width:32px;height:32px;background-color:#fde68a;border-radius:8px;line-height:32px;text-align:center;">
                                                        <span style="font-size:16px;">&#9888;</span>
                                                    </div>
                                                </td>
                                                <td valign="top">
                                                    <p style="margin:0;font-size:14px;line-height:1.5;font-weight:700;color:#92400e;">
                                                        Jaga Kerahasiaan Kode
                                                    </p>
                                                    <p style="margin:6px 0 0 0;font-size:13px;line-height:1.6;color:#a16207;">
                                                        Jangan bagikan kode ini kepada siapa pun, termasuk pihak yang mengaku sebagai admin atau customer service.
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
                        <td style="padding:32px 40px;text-align:center;">
                            <div style="height:1px;background-color:#e8e5df;margin-bottom:24px;"></div>

                            <p style="margin:0;font-size:12px;line-height:1.7;color:#888580;">
                                Jika kamu tidak merasa melakukan permintaan ini, abaikan email ini. Akun kamu tetap aman.
                            </p>

                            <p style="margin:16px 0 0 0;font-size:12px;line-height:1.6;color:#888580;">
                                &copy; {{ date('Y') }} {{ $appName }}
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>