<?php

use App\Contracts\SmsGateway;
use App\Services\LogSmsGateway;
use App\Services\SendchampGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

const SENDCHAMP_SMS = 'https://api.sendchamp.com/api/v1/sms/send';
const SENDCHAMP_WHATSAPP = 'https://api.sendchamp.com/api/v1/whatsapp/message/send';

function sendchampOk(string $reference = 'MN-SMS-1'): array
{
    return ['code' => 200, 'data' => ['reference' => $reference, 'status' => 'processing'], 'errors' => null, 'message' => 'processing', 'status' => 'success'];
}

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.sendchamp.access_key' => 'sendchamp_live_test',
        'services.sendchamp.base_url' => 'https://api.sendchamp.com/api/v1',
        'services.sendchamp.sender_name' => 'Etigo',
        'services.sendchamp.sms_route' => 'dnd',
        'services.sendchamp.whatsapp_sender' => null,
        'services.sendchamp.whatsapp_template' => null,
        'services.sendchamp.whatsapp_otp_template' => null,
    ]);
});

function enableSendchampWhatsApp(): void
{
    config([
        'services.sendchamp.whatsapp_sender' => '+2348120678278',
        'services.sendchamp.whatsapp_template' => 'TPL-NOTIFY',
        'services.sendchamp.whatsapp_otp_template' => 'TPL-OTP',
    ]);
}

it('is the gateway the app uses once an access key is set', function () {
    expect(app(SmsGateway::class))->toBeInstanceOf(SendchampGateway::class);

    config(['services.sendchamp.access_key' => '']);

    expect(app(SmsGateway::class))->toBeInstanceOf(LogSmsGateway::class);
});

it('sends SMS in the documented shape when WhatsApp is not set up', function () {
    Http::fake([SENDCHAMP_SMS => Http::response(sendchampOk())]);

    expect((new SendchampGateway)->deliver('+2348012345678', 'Hello'))->toBe('sms');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === SENDCHAMP_SMS
        && $request->hasHeader('Authorization', 'Bearer sendchamp_live_test')
        && $request['to'] === ['2348012345678']
        && $request['message'] === 'Hello'
        && $request['sender_name'] === 'Etigo'
        && $request['route'] === 'dnd');
});

it('uses the international route for numbers outside Nigeria', function () {
    Http::fake([SENDCHAMP_SMS => Http::response(sendchampOk())]);

    (new SendchampGateway)->send('+447700900123', 'Hello');

    Http::assertSent(fn (Request $request) => $request['to'] === ['447700900123'] && $request['route'] === 'international');
});

it('keeps SMS text in the GSM alphabet so it is billed at 160 characters a part', function () {
    Http::fake([SENDCHAMP_SMS => Http::response(sendchampOk())]);

    (new SendchampGateway)->sendSms('+2348012345678', "SOS ALERT \u{2014} it\u{2019}s \u{201C}urgent\u{201D}\u{2026} \u{20A6}500");

    Http::assertSent(fn (Request $request) => $request['message'] === 'SOS ALERT - it\'s "urgent"... N500');
});

it('sends WhatsApp first through the notification template, joining lines', function () {
    enableSendchampWhatsApp();
    Http::fake([SENDCHAMP_WHATSAPP => Http::response(['code' => 200, 'data' => ['provider_reference' => 'MN-WHATSAPP-1', 'provider_status' => 'success'], 'errors' => null, 'message' => 'whatsapp message sent', 'status' => 'success'])]);

    expect((new SendchampGateway)->deliver('+2348012345678', "E-tiGo: line one.\n\nTrack:   https://etigo.test/track/1/abc\nLast"))->toBe('whatsapp');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === SENDCHAMP_WHATSAPP
        && $request['sender'] === '2348120678278'
        && $request['recipient'] === '2348012345678'
        && $request['type'] === 'template'
        && $request['template_code'] === 'TPL-NOTIFY'
        && $request['custom_data'] === ['body' => ['1' => 'E-tiGo: line one. | Track: https://etigo.test/track/1/abc | Last']]);
});

it('falls back to SMS with the original text when WhatsApp refuses the message', function () {
    enableSendchampWhatsApp();
    Http::fake([
        SENDCHAMP_WHATSAPP => Http::response(['code' => 400, 'data' => null, 'errors' => null, 'message' => 'invalid template', 'status' => 'failed'], 400),
        SENDCHAMP_SMS => Http::response(sendchampOk()),
    ]);

    expect((new SendchampGateway)->deliver('+2348012345678', "Line one\nLine two"))->toBe('sms');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === SENDCHAMP_SMS && $request['message'] === "Line one\nLine two");
});

it('reports no channel when WhatsApp and SMS both refuse', function () {
    enableSendchampWhatsApp();
    Http::fake([
        SENDCHAMP_WHATSAPP => Http::response(['status' => 'failed', 'message' => 'invalid template'], 400),
        SENDCHAMP_SMS => Http::response(['code' => 400, 'data' => null, 'errors' => 'Message required', 'message' => 'Message required', 'status' => 'failed'], 400),
    ]);

    $gateway = new SendchampGateway;

    expect($gateway->deliver('+2348012345678', 'Hello'))->toBeNull()
        ->and($gateway->send('+2348012345678', 'Hello'))->toBeFalse()
        ->and($gateway->lastError())->toBe('Message required');
});

it('treats a success HTTP status with a failed body as refused', function () {
    Http::fake([SENDCHAMP_SMS => Http::response(['code' => 200, 'data' => null, 'message' => 'failed', 'status' => 'failed'])]);

    expect((new SendchampGateway)->sendSms('+2348012345678', 'Hello'))->toBeFalse();
});

it('logs a critical error when the Sendchamp wallet is empty', function () {
    Log::spy();
    Http::fake([SENDCHAMP_SMS => Http::response(['code' => 407, 'data' => null, 'errors' => null, 'message' => 'Low balance, fund your wallet', 'status' => 'failed'], 407)]);

    expect((new SendchampGateway)->sendSms('+2348012345678', 'Hello'))->toBeFalse();

    Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message) => str_contains($message, 'out of funds'));
});

it('returns false when Sendchamp cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect((new SendchampGateway)->sendSms('+2348012345678', 'Hello'))->toBeFalse();
});

it('sends codes through the OTP template with only the code as the variable', function () {
    enableSendchampWhatsApp();
    Http::fake([SENDCHAMP_WHATSAPP => Http::response(['status' => 'success', 'data' => ['provider_reference' => 'MN-WHATSAPP-2']])]);

    expect((new SendchampGateway)->sendOtp('+2348012345678', '482910', 'Your E-tiGo verification code is 482910.'))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['template_code'] === 'TPL-OTP'
        && $request['custom_data'] === ['body' => ['1' => '482910']]);
});

it('sends codes by SMS with the full text when there is no OTP template', function () {
    config(['services.sendchamp.whatsapp_sender' => '2348120678278', 'services.sendchamp.whatsapp_template' => 'TPL-NOTIFY']);
    Http::fake([SENDCHAMP_SMS => Http::response(sendchampOk())]);

    expect((new SendchampGateway)->sendOtp('+2348012345678', '482910', 'Your E-tiGo verification code is 482910.'))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === SENDCHAMP_SMS
        && $request['message'] === 'Your E-tiGo verification code is 482910.');
});

it('reads the wallet balance', function () {
    Http::fake(['https://api.sendchamp.com/api/v1/wallet-balance' => Http::response(['code' => 200, 'data' => ['business_name' => 'EtiGo Rides', 'wallet_balance' => '0'], 'errors' => null, 'message' => 'Success', 'status' => 'success'])]);

    expect((new SendchampGateway)->walletBalance())->toBe(['business_name' => 'EtiGo Rides', 'wallet_balance' => '0']);
});

it('registers a sender name for approval', function () {
    Http::fake(['https://api.sendchamp.com/api/v1/sms/create-sender-id' => Http::response(['code' => 200, 'data' => ['name' => 'Etigo', 'status' => 'pending'], 'errors' => null, 'message' => 'sms sender created', 'status' => 'success'])]);

    $this->artisan('sendchamp:register-sender', ['name' => 'Etigo'])
        ->expectsOutputToContain("Sender name 'Etigo' submitted (status: pending)")
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['name'] === 'Etigo'
        && $request['use_case'] === 'Transactional'
        && str_contains($request['sample'], '123456'));
});

it('shows the setup and warns when the wallet is empty', function () {
    Http::fake(['https://api.sendchamp.com/api/v1/wallet-balance' => Http::response(['status' => 'success', 'data' => ['business_name' => 'EtiGo Rides', 'wallet_balance' => '0']])]);

    $this->artisan('sendchamp:status')
        ->expectsOutputToContain('Account: EtiGo Rides. Wallet balance: 0.')
        ->expectsOutputToContain('The wallet is empty')
        ->assertSuccessful();
});

it('sends a test SMS from the console', function () {
    Http::fake([SENDCHAMP_SMS => Http::response(sendchampOk())]);

    $this->artisan('sendchamp:test', ['phone' => '+2348012345678', '--channel' => 'sms'])
        ->expectsOutputToContain('Accepted by Sendchamp (sms)')
        ->assertSuccessful();

    $this->artisan('sendchamp:test', ['phone' => '08012345678'])->assertFailed();
});
