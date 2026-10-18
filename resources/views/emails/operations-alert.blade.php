@extends('emails.layouts.base')

@section('content')
{{-- Section label --}}
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #b45309; text-transform: uppercase; letter-spacing: 0.5px;">{{ $label }}</p>

{{-- Headline --}}
<h1 style="margin: 0 0 16px 0; font-size: 24px; font-weight: 700; color: #18181b; line-height: 1.25;">{{ $headline }}</h1>

{{-- Body --}}
<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 20px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">{{ $body }}</p>

@if(count($lines) > 0)
{{-- One row per car or shift --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 8px; border-left: 3px solid #e5e7eb; margin-bottom: 28px;">
<tr>
<td style="padding: 16px 20px;">
    <table role="presentation" class="info-table" width="100%" cellpadding="0" cellspacing="0">
    @foreach($lines as $line)
    <tr>
        <td class="info-value" style="padding: 6px 0; font-size: 14px; color: #18181b; line-height: 1.5;">{{ $line }}</td>
    </tr>
    @endforeach
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
            <a href="{{ $actionUrl }}" target="_blank" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">{{ $actionLabel }}</a>
        </td>
    </tr>
    </table>
</td>
</tr>
</table>
@endsection
