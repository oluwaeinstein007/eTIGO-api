@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Welcome aboard</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">A smoother way to manage mobility.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 20px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    You're all set. Your Etigo admin account is active, and you now have access to the {{ $workspaceName }} workspace.
</p>

{{-- Status badge --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom: 20px;">
<tr>
    <td style="background-color: #dcfce7; border-radius: 20px; padding: 6px 16px;">
        <span style="font-size: 13px; font-weight: 600; color: #166534;">Account active</span>
    </td>
</tr>
</table>

{{-- Info card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 8px; border-left: 3px solid #e5e7eb; margin-bottom: 28px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" class="info-table" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Workspace</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $workspaceName }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Access</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $roleName }}</td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Make yourself at home --}}
<h2 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 700; color: #18181b;">Make yourself at home</h2>

{{-- Step 1 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">01</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Check your workspace</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Review your organisation details and account settings.</p>
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
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Bring your team together</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Invite colleagues and assign the right access.</p>
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
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Keep everything in view</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Find rides, drivers and billing in your dashboard.</p>
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
            <a href="{{ $dashboardUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">Open dashboard</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endsection
