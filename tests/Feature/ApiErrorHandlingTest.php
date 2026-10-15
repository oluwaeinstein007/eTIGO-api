<?php

use App\Exceptions\InvalidStateTransitionException;
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
