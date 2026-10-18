@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Dispute update</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Your report has been reviewed.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    Our operations team has reviewed your report <strong>{{ $referenceNumber }}</strong> and reached a decision. Here's a summary.
</p>

{{-- Status card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: {{ $status === 'Resolved' ? '#f0fdf4' : '#f9fafb' }}; border: 1px solid {{ $status === 'Resolved' ? '#bbf7d0' : '#e5e7eb' }}; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="middle">
            <p style="margin: 0 0 2px 0; font-size: 13px; color: #6b7280;">Status</p>
            <p style="margin: 0; font-size: 16px; font-weight: 700; color: #18181b;">{{ $status }}</p>
        </td>
        <td align="right" valign="middle">
            @if($status === 'Resolved')
            <span style="background-color: #dcfce7; border-radius: 12px; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #166534;">Resolved</span>
            @elseif($status === 'Dismissed')
            <span style="background-color: #fef2f2; border-radius: 12px; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #991b1b;">Dismissed</span>
            @else
            <span style="background-color: #f3f4f6; border-radius: 12px; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #374151;">{{ $status }}</span>
            @endif
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
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 130px; vertical-align: top;">Reference</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $referenceNumber }}</td>
    </tr>
    @if($resolutionNotes)
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 130px; vertical-align: top;">Resolution</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; color: #18181b; line-height: 1.5;">{{ $resolutionNotes }}</td>
    </tr>
    @endif
    @if($refundAmount)
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 130px; vertical-align: top;">Refund</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #166534;">{{ $refundCurrency }} {{ number_format($refundAmount, 2) }}</td>
    </tr>
    @endif
    @if($waivedAmount ?? null)
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 130px; vertical-align: top;">Fee waived</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #166534;">{{ $refundCurrency }} {{ number_format($waivedAmount, 2) }}</td>
    </tr>
    @endif
    </table>
</td>
</tr>
</table>

{{-- Refund or waiver notice --}}
@if($refundAmount || ($waivedAmount ?? null))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f0fdf4; border-radius: 8px; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="top" style="width: 32px; padding-right: 12px;">
            <div style="width: 28px; height: 28px; background-color: #dcfce7; border-radius: 50%; text-align: center; line-height: 28px; font-size: 14px;">&#9989;</div>
        </td>
        <td>
            @if($refundAmount)
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">Refund issued</p>
            @if(($refundMethod ?? null) === 'card')
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">{{ $refundCurrency }} {{ number_format($refundAmount, 2) }} has been refunded to your card. It may take a few business days to appear.</p>
            @else
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">{{ $refundCurrency }} {{ number_format($refundAmount, 2) }} has been credited to your E-tiGo wallet.</p>
            @endif
            @else
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">Fee waived</p>
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">The {{ $refundCurrency }} {{ number_format($waivedAmount, 2) }} you owed for the late cancellation has been cancelled. You do not need to pay it.</p>
            @endif
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endif

{{-- CTA button --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
<tr>
<td align="left">
    <table role="presentation" cellpadding="0" cellspacing="0" class="cta-button">
    <tr>
        <td style="background-color: #0d7c3d; border-radius: 8px;">
            <a href="{{ $supportUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">View ride history</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

<p style="margin: 0 0 24px 0; font-size: 13px; color: #6b7280; line-height: 1.5;">
    Thank you for helping us improve your ride experience. If you have further questions about this report, please contact our support team.
</p>
@endsection
