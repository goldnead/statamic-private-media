<?php

use Goldnead\PrivateMedia\Models\AccessLog;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/*
 * Review round 2: active content must not render same-origin, and a stream
 * that cannot be opened must not go out as a truncated 200.
 */

beforeEach(function () {
    putMedia('videos/intro.mp4', '0123456789');
    $this->viewer = entitledViewer();
});

it('sends an svg as an attachment inside a sandbox', function () {
    putMedia('docs/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $response = $this->actingAs($this->viewer)->get(mediaLink($this->viewer, 'docs/logo.svg'));

    $response->assertOk()->assertHeader('Content-Security-Policy', 'sandbox');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('sends html as an attachment', function () {
    putMedia('docs/page.html', '<script>alert(1)</script>');

    $response = $this->actingAs($this->viewer)->get(mediaLink($this->viewer, 'docs/page.html'));

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('keeps video inline, and sandboxed too', function () {
    $response = $this->actingAs($this->viewer)->get(mediaLink($this->viewer));

    $response->assertHeader('Content-Security-Policy', 'sandbox')
        ->assertHeader('Content-Disposition', 'inline; filename=intro.mp4');
});

it('names a UTF-8 file with an ASCII fallback and an RFC 5987 name', function () {
    putMedia('videos/Übung für Chöre "1".mp4', 'abc');

    $disposition = $this->actingAs($this->viewer)
        ->get(mediaLink($this->viewer, 'videos/Übung für Chöre "1".mp4'))
        ->assertOk()
        ->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('inline; filename=')
        ->toContain("filename*=utf-8''".rawurlencode('Übung für Chöre "1".mp4'))
        ->and(preg_match('/^[\x20-\x7e]*$/', (string) $disposition))->toBe(1);
});

it('refuses with storage_unavailable when the stream cannot be opened', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturnTrue();
    $disk->shouldReceive('size')->andReturn(10);
    $disk->shouldReceive('mimeType')->andReturn('video/mp4');
    $disk->shouldReceive('readStream')->andReturnNull();
    Storage::set('broken', $disk);
    config()->set('private-media.source.container', null);
    config()->set('private-media.source.disk', 'broken');
    config()->set('private-media.delivery.strategy', 'stream');

    $this->actingAs($this->viewer)->getJson(mediaLink($this->viewer))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'storage_unavailable');

    expect(AccessLog::query()->sole())->allowed->toBeFalse()->reason->toBe('storage_unavailable');
});
