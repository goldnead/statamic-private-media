<?php

use Goldnead\PrivateMedia\Models\AccessLog;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use Statamic\Facades\AssetContainer;

/*
 * The bucket path of adriangoldner.com's AccountMediaController
 * (signiertAusliefern) and its tests in ProtectedMediaApiTest:
 * test_base64url_video_asset_key_without_padding_is_decoded_and_served,
 * test_ein_leeres_objekt_wird_nicht_als_video_ausgeliefert.
 *
 * A "bucket" here is a non-local Laravel adapter over a temp directory that
 * signs URLs, which is what S3 looks like from this addon's side.
 */

beforeEach(function () {
    $this->root = $root = sys_get_temp_dir().'/private-media-bucket-'.getmypid();
    app('files')->deleteDirectory($root);
    app('files')->ensureDirectoryExists($root);

    $adapter = new LocalAdapter($root);
    $bucket = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['driver' => 'bucket']);
    $bucket->buildTemporaryUrlsUsing(fn (string $path, $expiration) => 'https://bucket.example/'.$path.'?exp='.$expiration->getTimestamp());

    Storage::set('bucket', $bucket);
    config()->set('filesystems.disks.bucket', ['driver' => 'bucket']);
    AssetContainer::make('private')->disk('bucket')->save();

    $bucket->put('videos/intro.mp4', '0123456789');

    $this->viewer = entitledViewer();
});

it('redirects to a temporary URL when the disk can make one', function () {
    $response = $this->actingAs($this->viewer)->get(mediaLink($this->viewer));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://bucket.example/videos/intro.mp4?exp=');

    expect(AccessLog::query()->sole())
        ->allowed->toBeTrue()
        ->reason->toBe('served')
        ->delivery->toBe('redirect');
});

it('gives the temporary URL the configured lifetime', function () {
    config()->set('private-media.delivery.temporary_url_ttl', 5);

    $location = $this->actingAs($this->viewer)->get(mediaLink($this->viewer))->headers->get('Location');

    expect((int) substr((string) $location, strrpos((string) $location, '=') + 1))->toBe(now()->addMinutes(5)->getTimestamp());
});

it('streams the same file when told to', function () {
    config()->set('private-media.delivery.strategy', 'stream');

    $response = $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=2-4'])->get(mediaLink($this->viewer));

    $response->assertStatus(206);
    expect($response->streamedContent())->toBe('234');
});

it('does not hand out a signed URL for an empty object', function () {
    Storage::disk('bucket')->put('videos/empty.mp4', '');

    $this->actingAs($this->viewer)->getJson(mediaLink($this->viewer, 'videos/empty.mp4'))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'file_empty');

    expect(AccessLog::query()->sole())->allowed->toBeFalse()->reason->toBe('file_empty');
});

it('refuses the redirect strategy on a disk that cannot sign', function () {
    config()->set('private-media.delivery.strategy', 'redirect');
    $adapter = new LocalAdapter($this->root);
    Storage::set('plain', new FilesystemAdapter(new Flysystem($adapter), $adapter, ['driver' => 'plain']));
    config()->set('filesystems.disks.plain', ['driver' => 'plain']);
    AssetContainer::make('private')->disk('plain')->save();

    $this->actingAs($this->viewer)->getJson(mediaLink($this->viewer))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'storage_unavailable');
});

it('names a silent storage as storage_unavailable, not as a missing file', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andThrow(UnableToCheckFileExistence::forLocation('videos/intro.mp4'));
    Storage::set('bucket', $disk);
    config()->set('private-media.source.container', null);
    config()->set('private-media.source.disk', 'bucket');

    $this->actingAs($this->viewer)->getJson(mediaLink($this->viewer))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'storage_unavailable');
});

it('lets a programming error surface instead of calling it an outage', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andThrow(new BadMethodCallException('missing expectation'));
    Storage::set('bucket', $disk);
    config()->set('private-media.source.container', null);
    config()->set('private-media.source.disk', 'bucket');

    $this->withoutExceptionHandling();

    $this->actingAs($this->viewer)->get(mediaLink($this->viewer));
})->throws(BadMethodCallException::class);

it('refuses with storage_unavailable when no source is configured', function () {
    config()->set('private-media.source.container', 'nope');

    $this->actingAs($this->viewer)->getJson(mediaLink($this->viewer))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'storage_unavailable');
});
