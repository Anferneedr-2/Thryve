@php
    // Colours and type follow StartupLab_Brand_Design_System.html: Electric Blue #315EEF, Navy #0F172A, Ink #37404C,
    // Muted #64748B, Canvas #F8FAFC, Mist #EFF6FF, Pale Blue #EEF2FF, hairline #E2E8F0, DM Sans headings, pill buttons.
    // Email clients ignore most CSS, so everything is inline and laid out with tables.
    $display = "'DM Sans','Inter',Arial,Helvetica,sans-serif";
    $body = "'Inter',Arial,Helvetica,sans-serif";
    $mono = "'JetBrains Mono',Menlo,Consolas,'Courier New',monospace";
    $initial = mb_strtoupper(mb_substr(trim($company), 0, 1)) ?: 'T';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Your {{ $company }} account is ready</title>
</head>
<body style="margin:0;padding:0;background-color:#f8fafc;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f8fafc;">Your username and starting password are inside.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f8fafc;">
<tr><td align="center" style="padding:32px 16px;">
  <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;">

    <tr><td style="padding:0 4px 20px 4px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td width="44" height="44" align="center" valign="middle" bgcolor="#315eef" style="width:44px;height:44px;border-radius:12px;background-color:#315eef;color:#ffffff;font-family:{!! $display !!};font-size:20px;font-weight:800;line-height:44px;">{{ $initial }}</td>
        <td style="padding-left:12px;font-family:{!! $display !!};font-size:21px;font-weight:800;letter-spacing:-0.02em;color:#0f172a;">{{ $company }}</td>
      </tr></table>
    </td></tr>

    <tr><td style="background-color:#ffffff;border:1px solid #e2e8f0;border-radius:20px;padding:32px 28px;">

      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td bgcolor="#eef2ff" style="background-color:#eef2ff;border-radius:9999px;padding:6px 12px;font-family:{!! $body !!};font-size:12px;font-weight:600;letter-spacing:0.04em;color:#315eef;">WELCOME</td>
      </tr></table>

      <h1 style="margin:16px 0 12px 0;font-family:{!! $display !!};font-size:26px;font-weight:800;line-height:1.15;letter-spacing:-0.02em;color:#0f172a;">Your account is ready</h1>

      <p style="margin:0 0 6px 0;font-family:{!! $body !!};font-size:12.5px;line-height:1.5;color:#64748b;">[PLACEHOLDER MESSAGE: the final wording will replace this text.]</p>
      <p style="margin:0 0 20px 0;font-family:{!! $body !!};font-size:15px;line-height:1.6;color:#37404c;">Hello {{ $recipientName }}, an account has been created for you at {{ $company }}. Here is what you need to sign in.</p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;"><tr>
        <td bgcolor="#eff6ff" style="background-color:#eff6ff;border:1px solid #dbeafe;border-radius:16px;padding:18px 20px;">
          <p style="margin:0 0 2px 0;font-family:{!! $body !!};font-size:12px;font-weight:600;letter-spacing:0.04em;color:#64748b;">USERNAME</p>
          <p style="margin:0 0 14px 0;font-family:{!! $mono !!};font-size:16px;font-weight:600;color:#0f172a;word-break:break-all;">{{ $username }}</p>
          <p style="margin:0 0 2px 0;font-family:{!! $body !!};font-size:12px;font-weight:600;letter-spacing:0.04em;color:#64748b;">STARTING PASSWORD</p>
          <p style="margin:0;font-family:{!! $mono !!};font-size:16px;font-weight:600;color:#0f172a;word-break:break-all;">{{ $startingPassword }}</p>
        </td>
      </tr></table>

      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;"><tr>
        <td align="center" bgcolor="#315eef" style="background-color:#315eef;border-radius:9999px;">
          <a href="{{ $loginUrl }}" style="display:inline-block;padding:14px 28px;font-family:{!! $body !!};font-size:14px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;border-radius:9999px;">Sign in to {{ $company }}</a>
        </td>
      </tr></table>

      <p style="margin:0 0 6px 0;font-family:{!! $body !!};font-size:14px;line-height:1.6;color:#37404c;">The first time you sign in, you will be asked to choose your own password.</p>
      <p style="margin:0;font-family:{!! $body !!};font-size:12.5px;line-height:1.6;color:#64748b;">Button not working? Copy this link into your browser: <a href="{{ $loginUrl }}" style="color:#315eef;text-decoration:none;word-break:break-all;">{{ $loginUrl }}</a></p>

    </td></tr>

    <tr><td style="padding:20px 8px 0 8px;font-family:{!! $body !!};font-size:12.5px;line-height:1.6;color:#64748b;">
      Sent by {{ $company }}. If you were not expecting this, you can ignore it and nothing will happen to your account.
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
