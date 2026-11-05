@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Account security</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Your sign-in code</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    Enter this code on the Etigo admin sign-in screen to finish signing in.
</p>

{{-- Code --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
<tr>
<td align="center" style="background-color: #f0fdf4; border-radius: 8px; padding: 20px;">
    <p style="margin: 0; font-size: 32px; font-weight: 700; color: #0d7c3d; letter-spacing: 8px; font-family: 'Courier New', monospace;">{{ $code }}</p>
</td>
</tr>
</table>

{{-- Info card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 8px; border-left: 3px solid #e5e7eb; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" class="info-table" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Account</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $recipientEmail }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Requested</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $requestedAt }}</td>
    </tr>
    @if ($ipAddress)
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">From IP</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $ipAddress }}</td>
    </tr>
    @endif
    </table>
</td>
</tr>
</table>

{{-- Expiry note --}}
<p style="margin: 0 0 20px 0; font-size: 13px; color: #6b7280;">
    The code expires in {{ $expiryMinutes }} minutes and works once. If it has expired, sign in again for a new one.
</p>

{{-- Security callout --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fef2f2; border-radius: 8px; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="top" style="width: 32px; padding-right: 12px;">
            <div style="width: 28px; height: 28px; background-color: #fee2e2; border-radius: 50%; text-align: center; line-height: 28px; font-size: 14px;">&#9888;</div>
        </td>
        <td>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">Wasn't you?</p>
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">Someone has your password. Don't share this code. Reset your password from the sign-in page now and tell a super admin. Etigo will never ask you for this code.</p>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endsection
