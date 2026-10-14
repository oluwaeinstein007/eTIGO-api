@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Driver registration</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">You're one step closer.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 20px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    Thanks for registering to drive with Etigo. We've received your profile and KYC documents, and our team is reviewing your application.
</p>

{{-- Status banner --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px; background-color: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="middle" style="padding-right: 12px; width: 28px;">
            <div style="width: 28px; height: 28px; background-color: #e5e7eb; border-radius: 6px; text-align: center; line-height: 28px; font-size: 14px;">&#128196;</div>
        </td>
        <td valign="middle">
            <p style="margin: 0; font-size: 14px; font-weight: 700; color: #18181b;">Registration received &middot; Review pending</p>
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
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Application ID</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $applicationId }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Submitted</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $submittedDate }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Documents received</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $documentsReceived }}</td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- What happens next --}}
<h2 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 700; color: #18181b;">What happens next</h2>

{{-- Step 1 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">01</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">We review your application</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Our team checks your identity and driver documents.</p>
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
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Watch your inbox</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">We'll email you if anything needs updating, or when you're approved.</p>
    </td>
</tr>
</table>

{{-- Step 3 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">03</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Get ready to drive</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">After approval, you can go online in the Etigo Driver app.</p>
    </td>
</tr>
</table>

{{-- Warning callout --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fefce8; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="top" style="width: 32px; padding-right: 12px;">
            <div style="width: 28px; height: 28px; background-color: #fef9c3; border-radius: 50%; text-align: center; line-height: 28px; font-size: 14px;">&#9201;</div>
        </td>
        <td>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">Not approved to drive yet</p>
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">You can check your application now, but you won't be able to accept rides until your registration is approved.</p>
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
            <a href="{{ $applicationUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">View application</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endsection
