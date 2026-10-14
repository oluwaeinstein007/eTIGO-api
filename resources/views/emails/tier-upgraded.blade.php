@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Rewards</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">You've levelled up!</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Congrats {{ $userName }},</p>
<p style="margin: 0 0 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    Your lower carbon footprint has been recognised. You've moved from <strong>{{ $previousTierName }}</strong> to <strong>{{ $newTierName }}</strong> tier.
</p>

{{-- Tier upgrade card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; margin-bottom: 24px;">
<tr>
<td style="padding: 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center" style="padding-bottom: 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
            <tr>
                <td style="text-align: center; padding: 0 12px;">
                    <p style="margin: 0 0 4px 0; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">From</p>
                    <span style="background-color: #f3f4f6; border-radius: 12px; padding: 6px 16px; font-size: 14px; font-weight: 600; color: #6b7280;">{{ $previousTierName }}</span>
                </td>
                <td style="text-align: center; padding: 0 8px; font-size: 18px; color: #0d7c3d;">&#10132;</td>
                <td style="text-align: center; padding: 0 12px;">
                    <p style="margin: 0 0 4px 0; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">Now</p>
                    <span style="background-color: #dcfce7; border-radius: 12px; padding: 6px 16px; font-size: 14px; font-weight: 700; color: #166534;">{{ $newTierName }}</span>
                </td>
            </tr>
            </table>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

{{-- Benefits --}}
<h2 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 700; color: #18181b;">Your new benefits</h2>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 8px; border-left: 3px solid #0d7c3d; margin-bottom: 24px;">
<tr>
<td style="padding: 16px 20px;">
    @foreach($benefits as $benefit)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="{{ !$loop->last ? 'margin-bottom: 12px;' : '' }}">
    <tr>
        <td valign="top" style="width: 24px; padding-right: 10px;">
            <div style="width: 22px; height: 22px; background-color: #dcfce7; border-radius: 50%; text-align: center; line-height: 22px; font-size: 11px; color: #166534;">&#10003;</div>
        </td>
        <td valign="middle">
            <p style="margin: 0; font-size: 14px; color: #18181b; line-height: 1.4;">{{ $benefit }}</p>
        </td>
    </tr>
    </table>
    @endforeach
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
            <a href="{{ $rewardsUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">View rewards</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>

<p style="margin: 0 0 24px 0; font-size: 13px; color: #6b7280; line-height: 1.5;">
    Keep riding green to unlock even more rewards. Every eco-friendly trip counts toward your next level.
</p>
@endsection
