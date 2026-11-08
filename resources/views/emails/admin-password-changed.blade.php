@extends('emails.layouts.base')

@section('content')
<p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 700; color: #0d7c3d; text-transform: uppercase; letter-spacing: 0.5px;">Security notice</p>

<h1 style="margin: 0 0 16px 0; font-size: 26px; font-weight: 700; color: #18181b; line-height: 1.2;">Your password was changed.</h1>

<p style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600; color: #18181b;">Hi {{ $userName }},</p>
<p style="margin: 0 0 20px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    The password for your Etigo admin account ({{ $recipientEmail }}) was changed on {{ $changedAt }}. Your other signed-in devices have been signed out.
</p>

<p style="margin: 0 0 20px 0; font-size: 15px; color: #4b5563; line-height: 1.6;">
    If you made this change, there is nothing more to do. If you did not, reset your password straight away and tell a system administrator.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom: 12px;">
<tr>
    <td style="background-color: #0d7c3d; border-radius: 8px;">
        <a href="{{ $resetUrl }}" style="display: inline-block; padding: 12px 28px; font-size: 14px; font-weight: 700; color: #ffffff; text-decoration: none;">Reset my password</a>
    </td>
</tr>
</table>

@include('emails.partials.fallback-link', ['url' => $resetUrl])
@endsection
