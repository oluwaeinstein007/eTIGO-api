<?php

use App\Enums\PaymentStatus;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\Request;

test('payment model normalizes legacy successful status to settled', function () {
    $payment = new Payment;
    $payment->setRawAttributes([
        'status' => 'successful',
        'method' => 'card',
    ]);

    expect($payment->status)->toBe(PaymentStatus::Settled);
});

test('payment model preserves standard payment statuses', function () {
    $payment = new Payment;
    $payment->setRawAttributes([
        'status' => 'pending',
        'method' => 'cash',
    ]);

    expect($payment->status)->toBe(PaymentStatus::Pending);
});

test('payment resource serializes payment correctly with normalized status', function () {
    $payment = new Payment;
    $payment->setRawAttributes([
        'id' => '11111111-1111-1111-1111-111111111111',
        'ride_id' => '22222222-2222-2222-2222-222222222222',
        'amount' => '3500.00',
        'currency' => 'NGN',
        'method' => 'card',
        'status' => 'successful',
    ]);

    $resource = new PaymentResource($payment);
    $data = $resource->toArray(Request::create('/api/v1/rides', 'GET'));

    expect($data['status'])->toBe('settled')
        ->and($data['status_label'])->toBe('Settled')
        ->and($data['method'])->toBe('card')
        ->and($data['method_label'])->toBe('Card');
});

test('payment resource safely handles null resource', function () {
    $resource = new PaymentResource(null);
    $data = $resource->toArray(Request::create('/api/v1/rides', 'GET'));

    expect($data)->toBeArray()->toBeEmpty();
});
