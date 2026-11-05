<?php

use App\Support\CloudinaryAdapter;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;

function cloudinaryAdapter(): CloudinaryAdapter
{
    return new CloudinaryAdapter(new Cloudinary([
        'cloud' => ['cloud_name' => 'demo-cloud', 'api_key' => '123', 'api_secret' => 'secret'],
        'url' => ['secure' => true],
    ]), ['profile-photos/']);
}

it('keeps profile photos on plain public links', function () {
    $adapter = cloudinaryAdapter();
    $url = $adapter->getUrl('profile-photos/u1/face.jpg');

    expect($url)->toBe('https://res.cloudinary.com/demo-cloud/image/upload/profile-photos/u1/face.jpg')
        ->and($adapter->getTemporaryUrl('profile-photos/u1/face.jpg', new DateTimeImmutable('+30 minutes')))->toBe($url);
});

it('serves private files through signed links that expire', function (string $path, string $resourceType) {
    $expires = new DateTimeImmutable('+30 minutes');
    $url = cloudinaryAdapter()->getTemporaryUrl($path, $expires);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith("https://api.cloudinary.com/v1_1/demo-cloud/{$resourceType}/download")
        ->and($query['type'])->toBe('authenticated')
        ->and((int) $query['expires_at'])->toBe($expires->getTimestamp())
        ->and($query)->toHaveKey('signature');
})->with([
    'driver document image' => ['driver-documents/d1/licence.jpg', 'image'],
    'driver document PDF' => ['driver-documents/d1/licence.pdf', 'raw'],
    'dispute audio' => ['disputes/r1/audio/clip.m4a', 'video'],
    'lost-item photo' => ['lost-items/r1/bag.png', 'image'],
]);

it('never hands out a public link for a private file', function () {
    $url = cloudinaryAdapter()->getUrl('driver-documents/d1/licence.jpg');

    expect($url)->not->toContain('/upload/')
        ->and($url)->toContain('type=authenticated')
        ->and($url)->toContain('expires_at=');
});

function apiResponse(array $body): ApiResponse
{
    return new ApiResponse($body, []);
}

function mockedCloudinaryAdapter(?AdminApi $adminApi = null, ?UploadApi $uploadApi = null): CloudinaryAdapter
{
    $cloudinary = Mockery::mock(Cloudinary::class, [[
        'cloud' => ['cloud_name' => 'demo-cloud', 'api_key' => '123', 'api_secret' => 'secret'],
        'url' => ['secure' => true],
    ]])->makePartial();

    if ($adminApi) {
        $cloudinary->shouldReceive('adminApi')->andReturn($adminApi);
    }

    return new CloudinaryAdapter($cloudinary, ['profile-photos/'], $uploadApi ?? Mockery::mock(UploadApi::class));
}

it('deletes a private file that has not been moved yet from public delivery', function () {
    $upload = Mockery::mock(UploadApi::class);
    $upload->shouldReceive('destroy')->once()->with('driver-documents/d1/licence', ['resource_type' => 'image', 'type' => 'authenticated'])
        ->andReturn(apiResponse(['result' => 'not found']));
    $upload->shouldReceive('destroy')->once()->with('driver-documents/d1/licence', ['resource_type' => 'image', 'type' => 'upload'])
        ->andReturn(apiResponse(['result' => 'ok']));

    mockedCloudinaryAdapter(uploadApi: $upload)->delete('driver-documents/d1/licence.jpg');
});

it('finds a private file that is still on public delivery', function () {
    $admin = Mockery::mock(AdminApi::class);
    $admin->shouldReceive('asset')->with('disputes/r1/photo', ['resource_type' => 'image', 'type' => 'authenticated'])
        ->andThrow(new RuntimeException('Resource not found'));
    $admin->shouldReceive('asset')->with('disputes/r1/photo', ['resource_type' => 'image', 'type' => 'upload'])
        ->andReturn(apiResponse(['public_id' => 'disputes/r1/photo']));

    expect(mockedCloudinaryAdapter($admin)->fileExists('disputes/r1/photo.png'))->toBeTrue();
});

it('keeps moving files when one cannot be made private', function () {
    $admin = Mockery::mock(AdminApi::class);
    $admin->shouldReceive('assets')->andReturnUsing(fn (array $options) => $options['resource_type'] === 'image'
        ? apiResponse(['resources' => [
            ['public_id' => 'repairs/a/one', 'format' => 'jpg'],
            ['public_id' => 'repairs/a/two', 'format' => 'jpg'],
        ]])
        : apiResponse(['resources' => []]));

    $upload = Mockery::mock(UploadApi::class);
    $upload->shouldReceive('rename')->with('repairs/a/one', 'repairs/a/one', Mockery::any())->andThrow(new RuntimeException('Rate limited'));
    $upload->shouldReceive('rename')->with('repairs/a/two', 'repairs/a/two', Mockery::any())->andReturn(apiResponse([]));

    $result = mockedCloudinaryAdapter($admin, $upload)->movePublicFilesToPrivate(['repairs/']);

    expect($result['moved'])->toBe(['repairs/a/two.jpg'])
        ->and($result['failed'])->toBe(['repairs/a/one.jpg' => 'Rate limited']);
});
