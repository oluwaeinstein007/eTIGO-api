<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class SyncPostmanCollection extends Command
{
    protected $signature = 'postman:sync {--dry-run : Show what would be sent without updating}';

    protected $description = 'Sync Postman collection and environments to Postman cloud';

    public function handle(): int
    {
        $apiKey = config('services.postman.api_key');
        $collectionId = config('services.postman.collection_id');
        $workspaceId = config('services.postman.workspace_id');

        if (! $apiKey || ! $collectionId) {
            $this->error('POSTMAN_API_KEY and POSTMAN_COLLECTION_ID must be set in .env');

            return self::FAILURE;
        }

        $failed = false;

        if (! $this->syncCollection($apiKey, $collectionId)) {
            $failed = true;
        }

        if (! $this->syncEnvironments($apiKey, $workspaceId)) {
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function syncCollection(string $apiKey, string $collectionId): bool
    {
        $path = base_path('doc/postman_collection.json');

        if (! file_exists($path)) {
            $this->error("Collection file not found: {$path}");

            return false;
        }

        $collection = json_decode(file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON in collection file: '.json_last_error_msg());

            return false;
        }

        if ($this->option('dry-run')) {
            $this->info("Collection: {$collection['info']['name']} ({$collectionId})");
            $this->info('Folders: '.count($collection['item'] ?? []));

            return true;
        }

        $this->info('Syncing collection...');

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $apiKey,
            ])->timeout(30)->retry(3, 500, fn ($e) => $e instanceof ConnectionException, throw: false)->put("https://api.getpostman.com/collections/{$collectionId}", [
                'collection' => $collection,
            ]);
        } catch (ConnectionException|RequestException $e) {
            $this->error("Collection sync failed: {$e->getMessage()}");

            return false;
        }

        if ($response->successful()) {
            $this->info('Collection synced.');

            return true;
        }

        $this->error("Collection sync failed ({$response->status()}): {$response->body()}");

        return false;
    }

    private function syncEnvironments(string $apiKey, ?string $workspaceId): bool
    {
        $envDir = base_path('doc/postman_environments');

        if (! is_dir($envDir)) {
            return true;
        }

        $files = glob("{$envDir}/*.json");
        $existing = $this->fetchExistingEnvironments($apiKey, $workspaceId);

        if ($existing === null) {
            $this->error('Failed to fetch existing environments — skipping environment sync.');

            return false;
        }

        $allOk = true;

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON in '.basename($file));
                $allOk = false;

                continue;
            }

            $name = $data['environment']['name'] ?? basename($file, '.json');

            if ($this->option('dry-run')) {
                $action = isset($existing[$name]) ? 'update' : 'create';
                $this->info("Environment: {$name} (would {$action})");

                continue;
            }

            if (isset($existing[$name])) {
                $allOk = $this->updateEnvironment($apiKey, $existing[$name], $data, $name) && $allOk;
            } else {
                $allOk = $this->createEnvironment($apiKey, $data, $name, $workspaceId) && $allOk;
            }
        }

        return $allOk;
    }

    /**
     * @return array<string, string>|null Null on API failure, map of name => id on success.
     */
    private function fetchExistingEnvironments(string $apiKey, ?string $workspaceId): ?array
    {
        $query = $workspaceId ? ['workspace' => $workspaceId] : [];

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $apiKey,
            ])->timeout(30)->retry(3, 500, fn ($e) => $e instanceof ConnectionException, throw: false)->get('https://api.getpostman.com/environments', $query);
        } catch (ConnectionException|RequestException $e) {
            $this->error("Failed to fetch environments: {$e->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $map = [];
        foreach ($response->json('environments', []) as $env) {
            $map[$env['name']] = $env['id'];
        }

        return $map;
    }

    private function createEnvironment(string $apiKey, array $data, string $name, ?string $workspaceId): bool
    {
        $this->info("Creating environment: {$name}...");

        $query = $workspaceId ? ['workspace' => $workspaceId] : [];

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $apiKey,
            ])->timeout(30)->retry(3, 500, fn ($e) => $e instanceof ConnectionException, throw: false)->post('https://api.getpostman.com/environments?'.http_build_query($query), $data);
        } catch (ConnectionException|RequestException $e) {
            $this->error("Failed to create \"{$name}\": {$e->getMessage()}");

            return false;
        }

        if ($response->successful()) {
            $this->info("Environment \"{$name}\" created.");

            return true;
        }

        $this->error("Failed to create \"{$name}\" ({$response->status()}): {$response->body()}");

        return false;
    }

    private function updateEnvironment(string $apiKey, string $envId, array $data, string $name): bool
    {
        $this->info("Updating environment: {$name}...");

        try {
            $remote = Http::withHeaders([
                'X-Api-Key' => $apiKey,
            ])->timeout(30)->retry(3, 500, fn ($e) => $e instanceof ConnectionException, throw: false)->get("https://api.getpostman.com/environments/{$envId}");
        } catch (ConnectionException|RequestException $e) {
            $this->error("Failed to fetch current \"{$name}\" for merge: {$e->getMessage()}");

            return false;
        }

        if (! $remote->successful()) {
            $this->error("Failed to fetch current \"{$name}\" for merge ({$remote->status()})");

            return false;
        }

        $remoteValues = collect($remote->json('environment.values', []))->keyBy('key');
        $localValues = collect($data['environment']['values'] ?? []);

        $merged = $localValues->map(function (array $var) use ($remoteValues) {
            $key = $var['key'];
            if (($var['value'] ?? '') === '' && $remoteValues->has($key)) {
                $var['value'] = $remoteValues[$key]['value'] ?? '';
            }

            return $var;
        });

        $remoteOnly = $remoteValues->diffKeys($localValues->keyBy('key'));
        $merged = $merged->merge($remoteOnly->values());

        $data['environment']['values'] = $merged->values()->all();

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $apiKey,
            ])->timeout(30)->retry(3, 500, fn ($e) => $e instanceof ConnectionException, throw: false)->put("https://api.getpostman.com/environments/{$envId}", $data);
        } catch (ConnectionException|RequestException $e) {
            $this->error("Failed to update \"{$name}\": {$e->getMessage()}");

            return false;
        }

        if ($response->successful()) {
            $this->info("Environment \"{$name}\" updated.");

            return true;
        }

        $this->error("Failed to update \"{$name}\" ({$response->status()}): {$response->body()}");

        return false;
    }
}
