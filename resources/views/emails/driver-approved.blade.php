@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Ready to drive</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">The road is yours.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    Good news: our admin team has approved your driver documents. Your Etigo driver account is now active. Complete any remaining setup steps in the app to start accepting rides.
</p>

{{-- Approval status card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="middle" style="padding-right: 12px; width: 28px;">
            <div style="width: 28px; height: 28px; background-color: #dcfce7; border-radius: 6px; text-align: center; line-height: 28px; font-size: 14px;">&#128196;</div>
        </td>
        <td valign="middle">
            <p style="margin: 0; font-size: 14px; font-weight: 700; color: #166534;">Documents checked. You're ready.</p>
        </td>
        <td align="right" valign="middle">
            <span style="background-color: #dcfce7; border-radius: 12px; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #166534;">Approved</span>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Info card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 8px; border-left: 3px solid #e5e7eb; margin-bottom: 28px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" class="info-table" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Driver</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $driverName }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Driver ID</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $driverId }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Approved on</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $approvedDate }}</td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Before your first ride --}}
<h2 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 700; color: #18181b;">Before your first ride</h2>

{{-- Step 1 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">01</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Open Etigo Driver</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Sign in with the account you used to register.</p>
    </td>
</tr>
</table>

{{-- Step 2 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">02</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Get road-ready</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Check your vehicle and keep your documents with you.</p>
    </td>
</tr>
</table>

{{-- Step 3 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 28px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">03</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Go online</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">When you're ready, go online to receive ride requests.</p>
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
            <a href="{{ $driverAppUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">Open Etigo Driver</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endsection
