<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
</head>
<body style="
  margin: 0;
  padding: 20px;
  background-color: #f5f7fa;
  font-family: Arial, sans-serif;
">

  <div style="
    max-width: 600px;
    margin: 0 auto;
    background-color: #ffffff;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 6px 20px rgba(0,0,0,0.12);
  ">

    <!-- HEADER -->
    <div style="
      background: linear-gradient(135deg, #020617, #0f172a);
      padding: 30px 20px;
      text-align: center;
      color: #ffffff;
    ">
      <img
        src="https://admin.webfocus.ph/files/2/Settings/logo-light.png"
        alt="Company Logo"
        style="
          max-width: 160px;
          height: auto;
          margin-bottom: 16px;
          display: block;
          margin-left: auto;
          margin-right: auto;
        "
      />

      <h2 style="
        margin: 0;
        font-size: 22px;
        font-weight: 600;
        letter-spacing: 0.3px;
      ">
        New Inquiry Received
      </h2>
    </div>

    <!-- CONTENT -->
    <div style="
      padding: 30px 25px;
      color: #1f2937;
      background-color: #ffffff;
      font-size: 14px;
      line-height: 1.6;
    ">

      <div style="margin-bottom: 14px;">
        <strong style="color: #374151; min-width: 120px; display: inline-block;">
          Inquiry Type:
        </strong>
        {{ $data['inquiry_type'] }}
      </div>

      <div style="margin-bottom: 14px;">
        <strong style="color: #374151; min-width: 120px; display: inline-block;">
          Name:
        </strong>
        {{ $data['first_name'] }} {{ $data['last_name'] }}
      </div>

      <div style="margin-bottom: 14px;">
        <strong style="color: #374151; min-width: 120px; display: inline-block;">
          Email:
        </strong>
        {{ $data['email'] }}
      </div>

      <div style="margin-bottom: 14px;">
        <strong style="color: #374151; min-width: 120px; display: inline-block;">
          Contact Number:
        </strong>
        {{ $data['contact_number'] }}
      </div>

      <div style="margin-top: 18px;">
        <strong style="color: #374151;">
          Message:
        </strong>

        <div style="
          margin-top: 8px;
          padding: 15px;
          background-color: #f1f5f9;
          border-left: 4px solid #0d6efd;
          border-radius: 6px;
          color: #111827;
          white-space: pre-line;
        ">
          {{ $data['message'] }}
        </div>
      </div>

    </div>

    <!-- FOOTER -->
    <div style="
      background-color: #f1f5f9;
      padding: 16px;
      text-align: center;
      font-size: 12px;
      color: #64748b;
    ">
      This message was sent from the
      <span style="color: #0d6efd;">Contact Us</span> form.
    </div>

  </div>

</body>
</html>
