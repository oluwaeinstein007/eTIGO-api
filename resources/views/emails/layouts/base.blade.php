<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <title>{{ $subject ?? config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="color-scheme" content="light" />
    <meta name="supported-color-schemes" content="light" />
    <style>
        body, table, td, p, a, li, blockquote {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            -webkit-font-smoothing: antialiased;
        }
        table {
            border-collapse: collapse !important;
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }
        img {
            border: 0;
            outline: none;
            text-decoration: none;
            -ms-interpolation-mode: bicubic;
        }
        a { text-decoration: none; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .content-cell { padding: 24px 20px !important; }
            .header-cell { padding: 20px !important; }
            .info-table td { display: block !important; width: 100% !important; padding: 6px 0 !important; }
            .info-label { color: #6b7280 !important; font-size: 13px !important; padding-bottom: 2px !important; }
            .info-value { font-weight: 600 !important; padding-bottom: 12px !important; }
            .cta-button { width: 100% !important; }
            .cta-button a { display: block !important; width: 100% !important; text-align: center !important; }
            .step-number { width: 32px !important; }
            .hero-image img { width: 100% !important; height: auto !important; }
            .footer-cell { padding: 24px 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

@if(!empty($preheader))
<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
    {{ $preheader }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
<tr>
<td align="center" style="padding: 24px 16px;">

{{-- Preheader banner --}}
@if(!empty($preheaderLabel) || !empty($preheaderTitle) || !empty($preheaderSub))
<table role="presentation" class="email-container" width="570" cellpadding="0" cellspacing="0" style="max-width: 570px; margin: 0 auto;">
<tr>
<td style="padding: 16px 24px 16px 24px;">
    @if(!empty($preheaderLabel))
    <p style="margin: 0 0 4px 0; font-size: 12px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">{{ $preheaderLabel }}</p>
    @endif
    @if(!empty($preheaderTitle))
    <p style="margin: 0 0 2px 0; font-size: 15px; font-weight: 600; color: #18181b;">{{ $preheaderTitle }}</p>
    @endif
    @if(!empty($preheaderSub))
    <p style="margin: 0; font-size: 13px; color: #6b7280;">{{ $preheaderSub }}</p>
    @endif
</td>
</tr>
</table>
@endif

{{-- Main card --}}
<table role="presentation" class="email-container" width="570" cellpadding="0" cellspacing="0" style="max-width: 570px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">

{{-- Logo header --}}
<tr>
<td class="header-cell" style="padding: 24px 32px 16px 32px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="left" valign="middle" style="width: 50%;">
            <a href="{{ config('app.url') }}">
                <img src="https://res.cloudinary.com/mt0hsxma/image/upload/v1791937893/email-assets/etigo-logo.png" alt="Etigo" width="100" style="display: block; height: auto; max-height: 40px; width: auto; max-width: 100px;" />
            </a>
        </td>
        <td align="right" valign="middle" style="width: 50%;">
            <span style="font-size: 13px; color: #9ca3af; font-style: italic;">Keep moving.</span>
        </td>
    </tr>
    </table>
</td>
</tr>

{{-- Green accent bar --}}
<tr>
<td style="padding: 0 0 0 0;">
    <div style="height: 4px; background: linear-gradient(90deg, #0d7c3d 0%, #34d399 100%);"></div>
</td>
</tr>

{{-- Body content --}}
<tr>
<td class="content-cell" style="padding: 32px 32px 24px 32px;">
    @yield('content')
</td>
</tr>

{{-- Sign-off --}}
<tr>
<td style="padding: 0 32px 32px 32px;">
    <p style="margin: 0 0 4px 0; font-size: 15px; color: #6b7280;">See you along the way,</p>
    <p style="margin: 0; font-size: 15px; font-weight: 600; color: #18181b;">The Etigo team</p>
</td>
</tr>

</table>

{{-- Help section --}}
<table role="presentation" class="email-container" width="570" cellpadding="0" cellspacing="0" style="max-width: 570px; margin: 0 auto;">
<tr>
<td class="footer-cell" style="padding: 28px 32px 12px 32px;">
    <p style="margin: 0 0 6px 0; font-size: 14px; font-weight: 600; color: #374151;">Need a hand?</p>
    <p style="margin: 0 0 0 0; font-size: 13px; color: #6b7280;">
        Visit the Help Centre or email support@etigo.ng.
    </p>
    <p style="margin: 6px 0 0 0; font-size: 13px;">
        <a href="{{ config('app.url') }}/help" style="color: #0d7c3d; font-weight: 600;">Help Centre</a>
        <span style="color: #d1d5db; margin: 0 6px;">&middot;</span>
        <a href="mailto:support@etigo.ng" style="color: #0d7c3d; font-weight: 600;">Contact support</a>
    </p>
</td>
</tr>
</table>

{{-- Legal footer --}}
<table role="presentation" class="email-container" width="570" cellpadding="0" cellspacing="0" style="max-width: 570px; margin: 0 auto;">
<tr>
<td style="padding: 16px 32px 8px 32px; border-top: 1px solid #e5e7eb;">
    <p style="margin: 0 0 8px 0; font-size: 12px; color: #9ca3af; line-height: 1.5;">
        Sent to {{ $recipientEmail ?? '' }} about your Etigo account. This is a service email, not a marketing message.
    </p>
    <p style="margin: 0 0 8px 0; font-size: 12px;">
        <a href="{{ config('app.url') }}/privacy" style="color: #9ca3af;">Privacy policy</a>
        <span style="color: #d1d5db; margin: 0 4px;">&middot;</span>
        <a href="{{ config('app.url') }}/terms" style="color: #9ca3af;">Terms of service</a>
    </p>
    <p style="margin: 0 0 0 0; font-size: 12px; color: #9ca3af;">
        &copy; {{ date('Y') }} Etigo. All rights reserved.
    </p>
</td>
</tr>
</table>

</td>
</tr>
</table>

</body>
</html>
