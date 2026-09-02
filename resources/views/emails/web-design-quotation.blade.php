<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
</head>
<body style="margin:0;padding:20px;background:#f5f7fa;font-family:Arial,sans-serif;">
  <div style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,.12);">
    <div style="background:linear-gradient(135deg,#020617,#0f172a);padding:28px 20px;text-align:center;color:#fff;">
      <h2 style="margin:0;font-size:20px;font-weight:600;">Web Design Quotation Request</h2>
      <p style="margin:8px 0 0;opacity:.85;font-size:13px;">Pending Quotation · Sales</p>
    </div>

    <div style="padding:28px 24px;color:#1f2937;font-size:14px;line-height:1.6;">
      <div style="margin-bottom:12px;">
        <strong style="display:inline-block;min-width:140px;color:#374151;">Invoice #:</strong>
        {{ $transaction->transaction_no }}
      </div>
      <div style="margin-bottom:12px;">
        <strong style="display:inline-block;min-width:140px;color:#374151;">Customer:</strong>
        {{ $transaction->customer_name }}
      </div>
      <div style="margin-bottom:12px;">
        <strong style="display:inline-block;min-width:140px;color:#374151;">Email:</strong>
        {{ $transaction->customer_email }}
      </div>
      <div style="margin-bottom:12px;">
        <strong style="display:inline-block;min-width:140px;color:#374151;">Status:</strong>
        Pending Quotation
      </div>

      <div style="margin-top:18px;">
        <strong style="color:#374151;">Requested packages</strong>
        <ul style="margin:8px 0 0;padding-left:18px;">
          @forelse (($transaction->items ?? []) as $item)
            <li>{{ $item->name }} (qty {{ $item->quantity }})</li>
          @empty
            <li>No line items</li>
          @endforelse
        </ul>
      </div>

      @if ($transaction->notes)
        <div style="margin-top:18px;">
          <strong style="color:#374151;">Notes</strong>
          <div style="margin-top:8px;padding:14px;background:#f1f5f9;border-left:4px solid #2563eb;border-radius:6px;white-space:pre-line;">{{ $transaction->notes }}</div>
        </div>
      @endif

      <p style="margin:22px 0 0;color:#64748b;font-size:13px;">
        @if ($assigneeName)
          Auto-assigned to <strong>{{ $assigneeName }}</strong>. They should upload the proposal quotation in Commerce Admin → Deals.
        @else
          Assign an active Sales Staff member in Commerce Admin → Deals, then upload the proposal quotation.
        @endif
      </p>
    </div>
  </div>
</body>
</html>
