<?php

use App\Support\CloudinaryAdapter;
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
