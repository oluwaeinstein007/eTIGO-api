@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Billing access</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Your invoices, all in one place.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    Your workspace administrator has activated invoice access for your Etigo account. You can now view and download invoices for {{ $workspaceName }}.
</p>

{{-- Access active card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="middle" style="padding-right: 12px; width: 28px;">
            <div style="width: 28px; height: 28px; background-color: #e5e7eb; border-radius: 6px; text-align: center; line-height: 28px; font-size: 14px;">&#128196;</div>
        </td>
        <td valign="middle">
            <p style="margin: 0; font-size: 14px; font-weight: 700; color: #166534;">Invoice access is switched on.</p>
        </td>
        <td align="right" valign="middle">
            <span style="background-color: #dcfce7; border: 1px solid #bbf7d0; border-radius: 12px; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #166534;">Access active</span>
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
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Account</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $recipientEmail }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Workspace</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $workspaceName }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Activated by</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $activatedBy }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 120px; vertical-align: top;">Active from</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $activeFrom }}</td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Steps heading --}}
<h2 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 700; color: #18181b;">Find the paperwork you need</h2>

{{-- Step 1 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">01</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Open Billing &rarr; Invoices</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Sign in to your Etigo account and select your workspace.</p>
    </td>
</tr>
</table>

{{-- Step 2 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 28px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">02</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">View or download an invoice</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Choose an available invoice to review its details or save a copy.</p>
    </td>
</tr>
</table>

{{-- CTA button --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 20px;">
<tr>
<td align="left">
    <table role="presentation" cellpadding="0" cellspacing="0" class="cta-button">
    <tr>
        <td style="background-color: #0d7c3d; border-radius: 8px;">
            <a href="{{ $invoicesUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">View invoices</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

<p style="margin: 0 0 24px 0; font-size: 13px; color: #6b7280;">
    This email confirms access only. It isn't an invoice or a payment request. If an invoice isn't listed yet, check with your workspace administrator.
</p>
@endsection
