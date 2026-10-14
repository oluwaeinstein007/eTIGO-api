@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Account update</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Your account has been deactivated.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    Your Etigo account has been deactivated by an administrator. You will no longer be able to sign in or use Etigo services with this account.
</p>

{{-- Status card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="middle" style="padding-right: 12px; width: 28px;">
            <div style="width: 28px; height: 28px; background-color: #fee2e2; border-radius: 6px; text-align: center; line-height: 28px; font-size: 14px;">&#128683;</div>
        </td>
        <td valign="middle">
            <p style="margin: 0; font-size: 14px; font-weight: 700; color: #991b1b;">Account deactivated</p>
        </td>
        <td align="right" valign="middle">
            <span style="background-color: #fee2e2; border-radius: 12px; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #991b1b;">Inactive</span>
        </td>
    </tr>
    </table>
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
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Deactivated on</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $deactivatedAt }}</td>
    </tr>
    @if(!empty($reason))
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Reason</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $reason }}</td>
    </tr>
    @endif
    </table>
</td>
</tr>
</table>

{{-- What this means --}}
<h2 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 700; color: #18181b;">What this means</h2>
<p style="margin: 0 0 8px 0; font-size: 14px; color: #4b5563; line-height: 1.6;">
    &bull; &nbsp;You can no longer sign in to your Etigo account.
</p>
<p style="margin: 0 0 8px 0; font-size: 14px; color: #4b5563; line-height: 1.6;">
    &bull; &nbsp;Any active rides or pending transactions have been cancelled.
</p>
<p style="margin: 0 0 24px 0; font-size: 14px; color: #4b5563; line-height: 1.6;">
    &bull; &nbsp;Outstanding balances or refunds will be processed separately.
</p>

{{-- Contact callout --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f0fdf4; border-radius: 8px; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="top" style="width: 32px; padding-right: 12px;">
            <div style="width: 28px; height: 28px; background-color: #dcfce7; border-radius: 50%; text-align: center; line-height: 28px; font-size: 14px;">&#9993;</div>
        </td>
        <td>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">Think this is a mistake?</p>
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">Contact our support team and we'll look into it. Include your account email so we can find your records quickly.</p>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- CTA button --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
<tr>
<td align="left">
    <table role="presentation" cellpadding="0" cellspacing="0" class="cta-button">
    <tr>
        <td style="background-color: #0d7c3d; border-radius: 8px;">
            <a href="mailto:support@etigo.ng" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">Contact support</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endsection
