<?php

use App\Services\QoreIdKycGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

it('gets an access token from the root endpoint using the documented credential body', function () {
    Http::fake([
        'https://api.qoreid.com/token' => Http::response([
            'accessToken' => 'qoreid-access-token',
        ], 201),
        'https://api.qoreid.com/v1/ng/identities/nin-premium/*' => Http::response([
            'data' => [
                'id' => 'qoreid-verification-123',
                'firstname' => 'Ada',
                'lastname' => 'Okafor',
            ],
        ]),
    ]);

    $gateway = new QoreIdKycGateway('client-id', 'client-secret');
    $result = $gateway->verifyNin('12345678901', [
        'firstname' => 'Ada',
        'lastname' => 'Okafor',
    ]);

    expect($result['verified'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.qoreid.com/token'
        && $request['clientId'] === 'client-id'
        && $request['secret'] === 'client-secret');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.qoreid.com/v1/ng/identities/nin-premium/12345678901'
        && $request->hasHeader('Authorization', 'Bearer qoreid-access-token'));
});
