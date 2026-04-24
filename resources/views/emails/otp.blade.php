<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Password Reset — Wudi</title>
</head>
<body style="margin:0;padding:0;background-color:#EDE7E0;font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#1A1A1A;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#EDE7E0;">
  <tr>
    <td align="center" style="padding:48px 16px 64px;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:540px;">

        <!-- Brand -->
        <tr>
          <td align="center" style="padding-bottom:24px;">
            <div style="font-size:22px;font-weight:900;letter-spacing:4px;color:#3D1C3B;text-transform:uppercase;">WUDI</div>
            <div style="font-size:12px;color:#9E8C88;margin-top:4px;letter-spacing:0.5px;">Master your time, achieve more.</div>
          </td>
        </tr>

        <!-- Card -->
        <tr>
          <td style="background:#ffffff;border-radius:28px;overflow:hidden;box-shadow:0 12px 48px rgba(61,28,59,0.14);">

            <!-- Hero -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background:linear-gradient(145deg,#3D1C3B 0%,#7B3E77 60%,#A0508B 100%);padding:44px 40px 40px;text-align:center;border-radius:28px 28px 0 0;">
                  <div style="width:64px;height:64px;background:rgba(255,255,255,0.15);border-radius:20px;margin:0 auto 20px;font-size:28px;line-height:64px;text-align:center;">🔐</div>
                  <h1 style="font-size:20px;font-weight:700;color:#ffffff;margin:0 0 8px 0;">Password Reset Verification</h1>
                  <p style="font-size:13px;color:rgba(255,255,255,0.75);line-height:1.6;margin:0;">We received a request to reset your Wudi account password.</p>
                </td>
              </tr>
            </table>

            <!-- Body -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding:40px 40px 36px;">

                  <p style="font-size:17px;font-weight:700;color:#2B1A2A;margin:0 0 10px 0;">Hey, {{ $userName }}!</p>
                  <p style="font-size:13.5px;color:#6B5E6A;line-height:1.75;margin:0 0 36px 0;">
                    Use the verification code below to continue with your password reset. This code is private — never share it with anyone.
                  </p>

                  <!-- OTP Box -->
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:linear-gradient(135deg,#F9F4F8 0%,#F3ECF2 100%);border:1px solid #E8D8E6;border-radius:20px;margin-bottom:28px;">
                    <tr>
                      <td style="padding:28px 24px 24px;text-align:center;">
                        <p style="font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#A07090;margin:0 0 16px 0;">Your Verification Code</p>

                        <!-- Digits -->
                        <table cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 18px auto;">
                          <tr>
                            @foreach (str_split($otp) as $digit)
                            <td style="padding:0 5px;">
                              <div style="width:56px;height:64px;background:#ffffff;border:2px solid #D4B8D0;border-radius:14px;font-size:28px;font-weight:900;color:#3D1C3B;text-align:center;line-height:64px;box-shadow:0 4px 12px rgba(61,28,59,0.10);">{{ $digit }}</div>
                            </td>
                            @endforeach
                          </tr>
                        </table>

                        <!-- Timer badge -->
                        <div style="display:inline-block;background:#FDE8E8;border:1px solid #F5C6C6;border-radius:50px;padding:6px 14px;font-size:12px;font-weight:600;color:#C62828;">
                          ● &nbsp;Valid for <strong>30 minutes</strong> &nbsp;·&nbsp; One-time use only
                        </div>
                      </td>
                    </tr>
                  </table>

                  <!-- Warning -->
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFFBF0;border:1px solid #F5E2A0;border-radius:14px;margin-bottom:28px;">
                    <tr>
                      <td style="padding:14px 16px;">
                        <table cellpadding="0" cellspacing="0" border="0" width="100%">
                          <tr>
                            <td width="28" valign="top" style="font-size:18px;padding-right:12px;padding-top:1px;">⚠️</td>
                            <td style="font-size:12.5px;color:#7A5A10;line-height:1.65;">
                              <strong style="color:#5A3D00;display:block;margin-bottom:2px;font-size:13px;">Never share this code with anyone.</strong>
                              The Wudi team will never ask for your verification code via phone, chat, or any other channel.
                            </td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>

                  <hr style="border:none;border-top:1px solid #F0E8EE;margin:32px 0;" />

                  <p style="font-size:12.5px;color:#9E8C88;line-height:1.7;text-align:center;margin:0;">
                    If you didn't request a password reset, you can safely ignore this email.<br />
                    Your account is secure — no changes have been made.
                  </p>

                </td>
              </tr>
            </table>

            <!-- Footer -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background:#FAF6F9;border-top:1px solid #EDE0EB;padding:24px 40px 28px;text-align:center;border-radius:0 0 28px 28px;">
                  <p style="font-size:11.5px;color:#A090A0;line-height:1.75;margin:0;">
                    This email was sent automatically by the <strong>Wudi</strong> system.<br />
                    Need help? Contact us at <a href="mailto:wudipdbl@gmail.com" style="color:#7B3E77;font-weight:600;text-decoration:none;">wudipdbl@gmail.com</a>
                  </p>
                  <p style="margin-top:10px;font-size:11px;color:#BBA8BB;">&copy; {{ date('Y') }} Wudi App. All rights reserved.</p>
                </td>
              </tr>
            </table>

          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
