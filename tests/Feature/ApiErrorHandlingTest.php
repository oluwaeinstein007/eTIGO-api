<?php

use App\Enums\RideStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('reports a missing resource as "Resource not found." rather than a missing endpoint', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/rides/'.Str::uuid())
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.', 'error_code' => 'NOT_FOUND']);
});

it('still reports an unknown route as a missing endpoint', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('message', 'The requested endpoint does not exist.');
});

it('keeps the reason a controller gives when it returns 404', function () {
    $passenger = User::factory()->passenger()->create();
    $ride = Ride::factory()->create(['passenger_id' => $passenger->id, 'status' => RideStatus::InProgress]);

    $this->actingAs($passenger)
        ->getJson("/api/v1/rides/{$ride->id}/receipt")
        ->assertNotFound()
        ->assertExactJson(['message' => 'Receipt is only available for completed rides.', 'error_code' => 'NOT_FOUND']);
});

it('includes the Retry-After header when a request is throttled', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'nobody@etigo.test', 'password' => 'wrong-password']);
    }

    $this->postJson('/api/v1/admin/auth/login', ['email' => 'nobody@etigo.test', 'password' => 'wrong-password'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});

it('returns 422 instead of 500 when a malformed identifier reaches the database', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/rides/pending?city_id=not-a-uuid')
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'INVALID_PARAMETER');
});

it('renders an invalid state transition as 409 Conflict', function () {
    $response = app(ExceptionHandler::class)->render(
        Request::create('/api/v1/rides', 'POST'),
        new InvalidStateTransitionException("Invalid ride state transition from 'completed' to 'cancelled'.", 'completed', 'cancelled'),
    );

    expect($response->getStatusCode())->toBe(409)
        ->and(json_decode($response->getContent(), true))->toBe([
            'message' => "Invalid ride state transition from 'completed' to 'cancelled'.",
            'error_code' => 'INVALID_STATE_TRANSITION',
            'current_status' => 'completed',
        ]);
});

it('answers a guest with 401 even without a JSON Accept header', function () {
    // A WhatsApp link preview of the estimate URL: the GET matches rides/{ride}, which needs a token.
    $this->get('/api/v1/rides/estimate', ['User-Agent' => 'WhatsApp/2.23.20.0'])
        ->assertUnauthorized()
        ->assertJsonPath('error_code', 'UNAUTHENTICATED');

    $this->post('/api/v1/rides/estimate')
        ->assertUnauthorized()
        ->assertJsonPath('error_code', 'UNAUTHENTICATED');

    $this->post('/broadcasting/auth')->assertUnauthorized();
});
