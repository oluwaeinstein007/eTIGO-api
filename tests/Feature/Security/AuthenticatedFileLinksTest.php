<?php

use App\Models\Driver;
use App\Models\User;
use App\Support\UploadLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('filesystems.uploads'));
    config(['app.url' => 'https://api.etigo.test']);
});

it('links a private upload through the API, and only its user can open it', function () {
    Storage::disk(config('filesystems.uploads'))->put('driver-documents/d1/licence.pdf', '%PDF-1.4 licence');
    $owner = User::factory()->driver()->create();
    $other = User::factory()->driver()->create();

    $url = UploadLinks::url('driver-documents/d1/licence.pdf', $owner);

    expect($url)->toStartWith('https://api.etigo.test/api/v1/files/')
        ->and(UploadLinks::url('driver-documents/d1/licence.pdf', $owner))->toBe($url);

    $path = parse_url($url, PHP_URL_PATH);

    $response = $this->actingAs($owner)->get($path);
    $response->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="licence.pdf"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe('%PDF-1.4 licence')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    $this->actingAs($other)->get($path)->assertNotFound();
});

it('needs a signed-in user', function () {
    Storage::disk(config('filesystems.uploads'))->put('disputes/r1/photo.jpg', 'jpeg');
    $url = UploadLinks::url('disputes/r1/photo.jpg', User::factory()->passenger()->create());

    $this->getJson(parse_url($url, PHP_URL_PATH))->assertUnauthorized();
});

it('rejects a ref edited to point at another file', function () {
    Storage::disk(config('filesystems.uploads'))->put('disputes/r1/a.jpg', 'a');
    Storage::disk(config('filesystems.uploads'))->put('disputes/r1/b.jpg', 'b');
    $user = User::factory()->passenger()->create();

    [, $signature] = explode('.', UploadLinks::ref('disputes/r1/a.jpg', $user));
    $forged = rtrim(strtr(base64_encode('disputes/r1/b.jpg'), '+/', '-_'), '=').'.'.$signature;

    $this->actingAs($user)->get('/api/v1/files/'.$forged)->assertNotFound();
});

it('returns 404 for a file that no longer exists', function () {
    $user = User::factory()->passenger()->create();

    $this->actingAs($user)->get('/api/v1/files/'.UploadLinks::ref('lost-items/r1/gone.jpg', $user))->assertNotFound();
});

it('keeps profile photos on their public link', function () {
    expect(UploadLinks::url('profile-photos/u1/face.jpg', User::factory()->passenger()->create()))
        ->not->toContain('/api/v1/files/');
});

it('gives a driver an API link to the document they uploaded', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $fileUrl = $this->withToken($token)
        ->postJson('/api/v1/driver/documents', [
            'type' => 'driving_licence',
            'document' => UploadedFile::fake()->create('licence.pdf', 10, 'application/pdf'),
        ])
        ->assertCreated()
        ->json('document.file_url');

    expect($fileUrl)->toStartWith('https://api.etigo.test/api/v1/files/');

    $this->withToken($token)->get(parse_url($fileUrl, PHP_URL_PATH))->assertOk();

    // Each request should authenticate afresh from its own token.
    $this->app['auth']->forgetGuards();
    $otherDriver = Driver::factory()->create();
    $this->withToken($otherDriver->user->createToken('test', ['driver'])->plainTextToken)
        ->get(parse_url($fileUrl, PHP_URL_PATH))
        ->assertNotFound();
});
