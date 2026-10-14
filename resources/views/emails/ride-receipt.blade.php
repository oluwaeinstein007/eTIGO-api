@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Ride completed</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Thanks for riding with us.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 20px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    You've arrived. Here's the receipt for your ride from {{ $pickupArea }} to {{ $dropoffArea }} on {{ $rideDate }}.
</p>

{{-- Total paid --}}
<p style="margin: 0 0 4px 0; font-size: 13px; color: #6b7280;">Total paid &middot; {{ $currency }}</p>
<p style="margin: 0 0 12px 0; font-size: 36px; font-weight: 800; color: #18181b; letter-spacing: -1px;">{{ $currencySymbol }}{{ $totalPaid }}</p>

{{-- Payment badge --}}
@if(!empty($paymentMethodLabel))
<table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
<tr>
    <td style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 20px; padding: 6px 14px;">
        <span style="font-size: 13px; font-weight: 600; color: #166534;">{{ $paymentMethodLabel }}</span>
    </td>
</tr>
</table>
@endif

{{-- Route map placeholder --}}
@if(!empty($mapImageUrl))
<div class="hero-image" style="margin-bottom: 20px; border-radius: 8px; overflow: hidden;">
    <img src="{{ $mapImageUrl }}" alt="Route map" width="506" style="display: block; width: 100%; height: auto; border-radius: 8px;" />
</div>
@endif

{{-- Pickup & Dropoff --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 20px;">
<tr>
    <td valign="top" style="padding-right: 8px; width: 20px;">
        <div style="width: 10px; height: 10px; background-color: #0d7c3d; border-radius: 50%; margin-top: 4px;"></div>
    </td>
    <td>
        <p style="margin: 0 0 2px 0; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.3px;">PICKUP &middot; {{ $pickupTime }}</p>
        <p style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $pickupAddress }}</p>
    </td>
</tr>
<tr>
    <td valign="top" style="padding-right: 8px; width: 20px;">
        <div style="width: 10px; height: 10px; background-color: #18181b; border-radius: 50%; margin-top: 4px;"></div>
    </td>
    <td>
        <p style="margin: 0 0 2px 0; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.3px;">DROP-OFF &middot; {{ $dropoffTime }}</p>
        <p style="margin: 0 0 0 0; font-size: 14px; font-weight: 600; color: #18181b;">{{ $dropoffAddress }}</p>
    </td>
</tr>
</table>

{{-- Ride info card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="font-size: 14px; font-weight: 600; color: #18181b; padding-bottom: 4px;">{{ $rideReference }}</td>
        <td align="right">
            <span style="background-color: #dcfce7; border-radius: 12px; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #166534;">Completed</span>
        </td>
    </tr>
    </table>
    <p style="margin: 8px 0 2px 0; font-size: 13px; color: #6b7280;">{{ $pickupArea }} &rarr; {{ $dropoffArea }}</p>
    <p style="margin: 2px 0 0 0; font-size: 13px; color: #6b7280;">
        &#128100; {{ $passengerName }} &nbsp;&nbsp; &#128663; {{ $driverName }} &middot; {{ $vehicleType }}
    </p>
    <p style="margin: 6px 0 0 0; font-size: 13px; color: #6b7280;">{{ $duration }} &middot; {{ $distance }}</p>
</td>
</tr>
</table>

{{-- Fare breakdown --}}
<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 700; color: #18181b;">Fare breakdown</h2>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 4px;">
@foreach($fareItems as $item)
<tr>
    <td style="padding: 6px 0; font-size: 14px; color: #4b5563;">
        <span style="color: #9ca3af; margin-right: 6px;">&#9632;</span> {{ $item['label'] }}
    </td>
    <td align="right" style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #18181b;">
        @if(!empty($item['negative']))
        <span style="color: #0d7c3d;">-{{ $currencySymbol }}{{ $item['amount'] }}</span>
        @else
        {{ $currencySymbol }}{{ $item['amount'] }}
        @endif
    </td>
</tr>
@endforeach
</table>

{{-- Total --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top: 2px solid #18181b; margin-bottom: 8px;">
<tr>
    <td style="padding: 12px 0; font-size: 15px; font-weight: 700; color: #18181b;">Total paid</td>
    <td align="right" style="padding: 12px 0; font-size: 15px; font-weight: 700; color: #18181b;">{{ $currencySymbol }}{{ $totalPaid }}</td>
</tr>
</table>

<p style="margin: 0 0 24px 0; font-size: 12px; color: #9ca3af;">
    Taxes included. {{ $paymentNote }}
</p>

{{-- CTA button --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
<tr>
<td align="left">
    <table role="presentation" cellpadding="0" cellspacing="0" class="cta-button">
    <tr>
        <td style="background-color: #0d7c3d; border-radius: 8px;">
            <a href="{{ $receiptUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">View ride receipt</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

<p style="margin: 0 0 24px 0; font-size: 13px; color: #6b7280;">
    Questions about this ride? Share ride ID {{ $rideReference }} with support.
</p>
@endsection
