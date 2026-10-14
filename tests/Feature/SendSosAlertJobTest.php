<?php

use App\Contracts\SmsGateway;
use App\Enums\SosIncidentStatus;
use App\Enums\UserType;
use App\Jobs\SendSosWhatsAppAlertJob;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->driver = User::factory()->create(['type' => UserType::Driver]);

    $this->ride = Ride::factory()->inProgress()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driver->id,
    ]);

    $this->incident = SosIncident::factory()->create([
        'ride_id' => $this->ride->id,
        'triggered_by_user_id' => $this->passenger->id,
        'status' => SosIncidentStatus::Triggered,
        'gps_lat' => 9.0579,
        'gps_lng' => 7.4951,
    ]);
});

describe('SendSosWhatsAppAlertJob', function () {
    it('sends alert to safety number via gateway', function () {
        config([
            'services.termii.api_key' => 'test-key',
            'services.termii.safety_number' => '+2348012345678',
        ]);

        $gateway = Mockery::mock(SmsGateway::class);
        $gateway->shouldReceive('send')
            ->once()
            ->with('+2348012345678', Mockery::on(fn ($msg) => str_contains($msg, 'SOS ALERT') && str_contains($msg, '9.0579') && str_contains($msg, '7.4951')
            ))
            ->andReturn(true);

        $this->app->instance(SmsGateway::class, $gateway);

        $job = new SendSosWhatsAppAlertJob($this->incident->id);
        $job->handle($gateway);
    });

    it('includes incident details in alert body', function () {
        config([
            'services.termii.api_key' => 'test-key',
            'services.termii.safety_number' => '+2348012345678',
        ]);

        $capturedMessage = null;

        $gateway = Mockery::mock(SmsGateway::class);
        $gateway->shouldReceive('send')
            ->once()
            ->andReturnUsing(function ($phone, $msg) use (&$capturedMessage) {
                $capturedMessage = $msg;

                return true;
            });

        $job = new SendSosWhatsAppAlertJob($this->incident->id);
        $job->handle($gateway);

        expect($capturedMessage)
            ->toContain("Incident #{$this->incident->id}")
            ->toContain("Phone: {$this->passenger->phone}")
            ->toContain("Ride: #{$this->ride->id}")
            ->toContain('maps.google.com')
            ->toContain('9.0579')
            ->toContain('7.4951');
    });

    it('throws exception on delivery failure for retry', function () {
        config([
            'services.termii.api_key' => 'test-key',
            'services.termii.safety_number' => '+2348012345678',
        ]);

        $gateway = Mockery::mock(SmsGateway::class);
        $gateway->shouldReceive('send')->once()->andReturn(false);

        $job = new SendSosWhatsAppAlertJob($this->incident->id);

        expect(fn () => $job->handle($gateway))->toThrow(RuntimeException::class);
    });

    it('skips when no api key is configured', function () {
        config([
            'services.termii.api_key' => null,
            'services.termii.safety_number' => '+2348012345678',
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('SOS alert skipped — no messaging gateway configured', Mockery::any());

        $gateway = Mockery::mock(SmsGateway::class);
        $gateway->shouldNotReceive('send');

        $job = new SendSosWhatsAppAlertJob($this->incident->id);
        $job->handle($gateway);
    });

    it('skips when no safety number is configured', function () {
        config([
            'services.termii.api_key' => 'test-key',
            'services.termii.safety_number' => null,
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('SOS alert skipped — no safety number configured', Mockery::any());

        $gateway = Mockery::mock(SmsGateway::class);
        $gateway->shouldNotReceive('send');

        $job = new SendSosWhatsAppAlertJob($this->incident->id);
        $job->handle($gateway);
    });

    it('returns silently when incident does not exist', function () {
        config([
            'services.termii.api_key' => 'test-key',
            'services.termii.safety_number' => '+2348012345678',
        ]);

        $gateway = Mockery::mock(SmsGateway::class);
        $gateway->shouldNotReceive('send');

        $job = new SendSosWhatsAppAlertJob((string) Str::uuid());
        $job->handle($gateway);
    });

    it('dispatches from SOS trigger endpoint', function () {
        Queue::fake();

        $ride = Ride::factory()->inProgress()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driver->id,
        ]);

        $token = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

        $this->postJson(
            "/api/v1/rides/{$ride->id}/sos",
            ['gps_lat' => 9.0579, 'gps_lng' => 7.4951],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(201);

        Queue::assertPushed(SendSosWhatsAppAlertJob::class);
    });
});
