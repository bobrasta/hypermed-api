<!doctype html>
<html>
<body style="margin:0;padding:24px;background:#f4f5f4;font-family:Arial,Helvetica,sans-serif;color:#1d2521">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e3e6e4;border-radius:10px">
    <tr><td style="padding:22px 26px 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6b7570">Hypermed</td></tr>
    <tr><td style="padding:0 26px;font-size:18px;font-weight:bold">{{ $title }}</td></tr>
    <tr><td style="padding:14px 26px 4px;font-size:14px;line-height:1.55">Hello {{ $name }},</td></tr>
    <tr><td style="padding:4px 26px 18px;font-size:14px;line-height:1.55">{{ $body }}</td></tr>
    <tr><td style="padding:0 26px 24px">
      <a href="{{ $url }}" style="display:inline-block;padding:10px 18px;background:#0f8b6d;color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px">Open in Hypermed</a>
    </td></tr>
    <tr><td style="padding:14px 26px 20px;border-top:1px solid #e3e6e4;font-size:12px;color:#6b7570">You get this because the same notice is in your Hypermed notifications. This mailbox isn't monitored — please don't reply.</td></tr>
  </table>
</body>
</html>
