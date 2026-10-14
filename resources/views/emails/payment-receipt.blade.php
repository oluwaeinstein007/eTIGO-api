@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Payment confirmation</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Your payment is complete.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    We've successfully received your {{ strtolower($paymentMethod) }} payment of {{ $currencySymbol }}{{ $totalAmount }}. Here are the transaction details for your records.
</p>

{{-- Payment card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="middle" style="padding-right: 12px; width: 28px;">
            <div style="width: 28px; height: 28px; background-color: #e5e7eb; border-radius: 6px; text-align: center; line-height: 28px; font-size: 14px;">&#128179;</div>
        </td>
        <td valign="middle">
            <p style="margin: 0; font-size: 13px; color: #6b7280;">Amount received &middot; {{ $currency }}</p>
        </td>
        <td align="right" valign="middle">
            <span style="background-color: #dcfce7; border-radius: 12px; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #166534;">Paid</span>
        </td>
    </tr>
    </table>
    <p style="margin: 12px 0 4px 0; font-size: 32px; font-weight: 800; color: #18181b; letter-spacing: -1px;">{{ $currencySymbol }}{{ $totalAmount }}</p>
    @if(!empty($paymentMethodDetail))
    <p style="margin: 0; font-size: 13px; color: #0d7c3d; font-weight: 600;">{{ $paymentMethodDetail }}</p>
    @endif
</td>
</tr>
</table>

{{-- Transaction details --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 8px; border-left: 3px solid #e5e7eb; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" class="info-table" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Payment reference</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $paymentReference }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Payment date</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $paymentDate }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Paid by</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $paidBy }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Payment for</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $paymentFor }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Payment method</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $paymentMethodDetail ?? $paymentMethod }}</td>
    </tr>
    <tr>
        <td class="info-label" style="padding: 6px 0; font-size: 13px; color: #6b7280; width: 140px; vertical-align: top;">Status</td>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #166534;">Successful</td>
    </tr>
    </table>
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
            <a href="{{ $receiptUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">View payment receipt</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Confirmation callout --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f0fdf4; border-radius: 8px; margin-bottom: 20px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="top" style="width: 32px; padding-right: 12px;">
            <div style="width: 28px; height: 28px; background-color: #dcfce7; border-radius: 50%; text-align: center; line-height: 28px; font-size: 14px;">&#10003;</div>
        </td>
        <td>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">This confirms your payment</p>
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">Your ride receipt is sent separately and includes the route and fare breakdown. This email records the successful {{ strtolower($paymentMethod) }} transaction.</p>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

<p style="margin: 0 0 24px 0; font-size: 13px; color: #6b7280;">
    If you don't recognise this payment, contact support with reference {{ $paymentReference }}.
</p>
@endsection
