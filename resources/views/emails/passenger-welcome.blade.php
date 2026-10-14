@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Welcome to Etigo</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Your city. Your next stop.</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    Thanks for joining Etigo. Your passenger account is ready, so you can book a ride whenever you're ready to head out.
</p>

{{-- Hero image --}}
<div class="hero-image" style="margin-bottom: 28px; border-radius: 12px; overflow: hidden;">
    <img src="https://res.cloudinary.com/mt0hsxma/image/upload/v1791937856/email-assets/city-ride-hero.jpg" alt="Etigo ride" width="506" style="display: block; width: 100%; height: auto; border-radius: 12px;" />
</div>

{{-- Steps heading --}}
<h2 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 700; color: #18181b;">Your first ride, made simple</h2>

{{-- Step 1 --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
<tr>
    <td class="step-number" valign="top" style="width: 40px; padding-right: 12px;">
        <div style="width: 32px; height: 32px; background-color: #0d7c3d; border-radius: 50%; text-align: center; line-height: 32px; font-size: 13px; font-weight: 700; color: #ffffff;">01</div>
    </td>
    <td valign="top">
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Choose where you're going</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Set your pickup location and destination in the Etigo app.</p>
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
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Pick the ride that fits</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Review the available ride options and fare before booking.</p>
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
        <p style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #18181b;">Meet your driver</p>
        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;">Follow your driver's arrival and check the vehicle details.</p>
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
            <a href="{{ $bookRideUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">Book your first ride</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Safety callout --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f0fdf4; border-radius: 8px; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td valign="top" style="width: 32px; padding-right: 12px;">
            <div style="width: 28px; height: 28px; background-color: #dcfce7; border-radius: 50%; text-align: center; line-height: 28px; font-size: 14px;">&#10003;</div>
        </td>
        <td>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #18181b;">A little check before you go</p>
            <p style="margin: 0; font-size: 13px; color: #4b5563; line-height: 1.5;">Before getting in, match the driver and number plate with the details shown in your app.</p>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endsection
