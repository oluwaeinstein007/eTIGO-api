@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Document reminder</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Keep your {{ strtolower($documentType) }} up to date.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    The {{ strtolower($documentType) }} on your Etigo driver profile expires in {{ $daysRemaining }} days. Please renew it and upload the updated document before {{ $expiryDate }}.
</p>

{{-- Expiry card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fefce8; border: 1px solid #fde68a; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="middle" style="padding-right: 12px; width: 28px;">
            <div style="width: 28px; height: 28px; background-color: #fef9c3; border-radius: 6px; text-align: center; line-height: 28px; font-size: 14px;">&#128196;</div>
        </td>
        <td valign="middle">
            <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #166534;">{{ $documentType }}</p>
        </td>
    </tr>
    </table>
    <p style="margin: 12px 0 4px 0; font-size: 16px; font-weight: 700; color: #b45309;">Expires {{ $expiryDate }}</p>
    <p style="margin: 0; font-size: 13px; color: #92400e;">{{ $daysRemaining }} days remaining @if(!empty($documentEnding))&middot; Ending in {{ $documentEnding }}@endif</p>
</td>
</tr>
</table>

{{-- Info card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 8px; border-left: 3px solid #e5e7eb; margin-bottom: 24px;">
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
            <a href="{{ $updateDocumentUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">Update document</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Steps heading --}}
<h2 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 700; color: #18181b;">A quick update keeps you moving</h2>

{{-- Step 1 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">01</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Upload your renewed {{ strtolower($documentType) }}</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">In Etigo Driver, open Account &gt; Documents &gt; {{ $documentType }}.</p>
    </td>
</tr>
</table>

{{-- Step 2 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">02</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Check the photo before sending</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Make sure all details and the new expiry date are clearly readable.</p>
    </td>
</tr>
</table>

{{-- Warning callout --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fef2f2; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="top" style="width: 32px; padding-right: 12px;">
            <div style="width: 28px; height: 28px; background-color: #fee2e2; border-radius: 50%; text-align: center; line-height: 28px; font-size: 14px;">&#9888;</div>
        </td>
        <td>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">Don't let an expired document pause your rides</p>
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">You won't be able to accept rides with an expired {{ strtolower($documentType) }}. Upload the renewed document in time for review.</p>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

<p style="margin: 0 0 24px 0; font-size: 13px; color: #6b7280;">
    Already submitted a replacement? Check its review status in the app. You don't need to upload it again.
</p>
@endsection
