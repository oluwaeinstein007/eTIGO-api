@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Account security</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Let's get you back in.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    We received a request to reset the password for your Etigo admin account. Use the button below to choose a new password.
</p>

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
    </table>
</td>
</tr>
</table>

{{-- CTA button --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 12px;">
<tr>
<td align="left">
    <table role="presentation" cellpadding="0" cellspacing="0" class="cta-button">
    <tr>
        <td style="background-color: #0d7c3d; border-radius: 8px;">
            <a href="{{ $resetUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">Reset password</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Fallback link --}}
<p style="margin: 0 0 20px 0; font-size: 13px;">
    <a href="{{ $resetUrl }}" style="color: #0d7c3d;">Button not working? Open {{ parse_url($resetUrl, PHP_URL_HOST) . parse_url($resetUrl, PHP_URL_PATH) }}</a>
</p>

{{-- Expiry note --}}
<p style="margin: 0 0 20px 0; font-size: 13px; color: #6b7280;">
    This single-use link expires at {{ $expiresAt }}. If it has expired, request another link from the sign-in page.
</p>

{{-- Security callout --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f0fdf4; border-radius: 8px; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="top" style="width: 32px; padding-right: 12px;">
            <div style="width: 28px; height: 28px; background-color: #dcfce7; border-radius: 50%; text-align: center; line-height: 28px; font-size: 14px;">&#9888;</div>
        </td>
        <td>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">Didn't request a reset?</p>
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">Ignore this email and your password will stay the same. If you're concerned about your account, contact support. Etigo will never ask you to share your password.</p>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endsection
