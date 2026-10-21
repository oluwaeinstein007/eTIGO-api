<?php

use App\Console\Commands\SyncPostmanCollection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'services.postman.api_key' => 'test-key',
        'services.postman.collection_id' => 'collection-uid',
        'services.postman.workspace_id' => 'workspace-id',
    ]);
    Sleep::fake();
});

function fakeEnvironments(): array
{
    return [
        'api.getpostman.com/environments?*' => Http::response(['environments' => []]),
        'api.getpostman.com/environments' => Http::response(['environments' => []]),
    ];
}

it('waits out a 409 while Postman is still applying an earlier update', function () {
    Http::fake([
        'api.getpostman.com/collections/*' => Http::sequence()
            ->push(['error' => ['name' => 'conflict', 'message' => 'Resource is currently being modified by deepupdate.']], 409)
            ->push(['error' => ['name' => 'conflict']], 409)
            ->push(['collection' => ['uid' => 'collection-uid']]),
        ...fakeEnvironments(),
    ]);

    $this->artisan('postman:sync')
        ->expectsOutputToContain('Postman is still applying an earlier update')
        ->expectsOutputToContain('Collection synced.')
        ->assertSuccessful();

    Http::assertSentCount(3 + 1 + 2);
    Sleep::assertSlept(fn ($duration) => in_array($duration->totalMilliseconds, [5000.0, 10000.0], true), 2);
});

it('retries when Postman cannot be reached, then succeeds', function () {
    $attempts = 0;

    Http::fake(function (Request $request) use (&$attempts) {
        if (str_contains($request->url(), '/collections/') && $attempts++ < 2) {
            throw new ConnectionException('cURL error 6: Could not resolve host: api.getpostman.com');
        }

        return str_contains($request->url(), '/collections/')
            ? Http::response(['collection' => ['uid' => 'collection-uid']])
            : Http::response(['environments' => []]);
    });

    $this->artisan('postman:sync')
        ->expectsOutputToContain("Can't reach Postman")
        ->expectsOutputToContain('Collection synced.')
        ->assertSuccessful();
});

it('gives up after the last retry and fails', function () {
    Http::fake([
        'api.getpostman.com/collections/*' => Http::response(['error' => ['name' => 'conflict']], 409),
        ...fakeEnvironments(),
    ]);

    $this->artisan('postman:sync')
        ->expectsOutputToContain('Collection sync failed (409)')
        ->assertFailed();

    // Every attempt at the collection, then the environment list and two creates.
    Http::assertSentCount(count(SyncPostmanCollection::RETRY_DELAYS_MS) + 1 + 1 + 2);
});

it('does not retry a client error that will not clear up', function () {
    Http::fake([
        'api.getpostman.com/collections/*' => Http::response(['error' => ['name' => 'instanceNotFoundError']], 404),
        ...fakeEnvironments(),
    ]);

    $this->artisan('postman:sync')
        ->expectsOutputToContain('Collection sync failed (404)')
        ->assertFailed();

    Sleep::assertNeverSlept();
});
