<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
</head>
<body style="margin:0;padding:20px;background:#f5f7fa;font-family:Arial,sans-serif;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,0.12);">
    <div style="background:linear-gradient(135deg,#020617,#0f172a);padding:28px 20px;text-align:center;color:#fff;">
      <h2 style="margin:0;font-size:20px;font-weight:600;">Reply from WebFocus Customer Care</h2>
    </div>
    <div style="padding:28px 24px;color:#1f2937;font-size:14px;line-height:1.6;">
      <p style="margin:0 0 16px;">Hello {{ $name }},</p>
      <p style="margin:0 0 12px;">Thank you for contacting us regarding <strong>{{ $inquiryType }}</strong>. Our team replied:</p>
      <div style="margin:16px 0;padding:15px;background:#f1f5f9;border-left:4px solid #2563eb;border-radius:6px;white-space:pre-line;">{{ $replyMessage }}</div>
      <p style="margin:16px 0 0;color:#64748b;font-size:13px;">You can reply to this email if you have follow-up questions.</p>
    </div>
    <div style="background:#f1f5f9;padding:14px;text-align:center;font-size:12px;color:#64748b;">
      WebFocus Solutions, Inc. · Customer Care
    </div>
  </div>
</body>
</html>
