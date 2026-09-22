<?php

use Goldnead\PrivateMedia\Facades\PrivateMedia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    putMedia('videos/intro.mp4', '0123456789');
    $this->viewer = entitledViewer();
});

/**
 * A link signed by this app, with whatever path a template might have been
 * tricked into passing. Proves the path fences hold even behind a valid
 * signature, which is the case that matters: a template can sign anything.
 */
function signedFor($viewer, string $path, string $resource = 'cvt-101'): string
{
    return URL::temporarySignedRoute('private-media.show', now()->addHour(), [
        'resource' => $resource,
        'path' => $path,
        'viewer' => PrivateMedia::viewerToken((string) $viewer->getKey()),
    ]);
}

it('refuses a link whose signature was tampered with', function () {
    $link = mediaLink($this->viewer).'0';

    $this->actingAs($this->viewer)->getJson($link)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'signature');
});

it('refuses a link that was edited to point at another file', function () {
    putMedia('videos/other.mp4', 'secret');
    $link = str_replace('intro.mp4', 'other.mp4', mediaLink($this->viewer));

    $this->actingAs($this->viewer)->getJson($link)->assertJsonPath('error.code', 'signature');
});

it('refuses a link whose expiry was pushed back', function () {
    $link = preg_replace('/expires=\d+/', 'expires='.(time() + 999999), mediaLink($this->viewer));

    $this->actingAs($this->viewer)->getJson($link)->assertJsonPath('error.code', 'signature');
});

it('refuses an unsigned request', function () {
    $this->actingAs($this->viewer)->getJson('/private-media/cvt-101/videos/intro.mp4')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'signature');
});

it('refuses an expired link', function () {
    $link = mediaLink($this->viewer);

    Carbon::setTestNow(now()->addMinutes(241));

    try {
        $this->actingAs($this->viewer)->getJson($link)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'expired');
    } finally {
        Carbon::setTestNow();
    }
});

it('refuses another user\'s link, even when that user has access too', function () {
    $other = entitledViewer('cvt-101', 'b@example.test');

    $this->actingAs($other)->getJson(mediaLink($this->viewer))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'wrong_user');
});

it('refuses a guest holding a valid link', function () {
    $this->getJson(mediaLink($this->viewer))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('refuses path traversal behind a valid signature', function (string $path) {
    // Something worth stealing next to the container's root.
    file_put_contents(dirname(Storage::disk('private_media')->path('')).'/outside.txt', 'secret');

    $response = $this->actingAs($this->viewer)->getJson(signedFor($this->viewer, $path));

    expect($response->status())->toBeIn([403, 404]);
    expect($response->getContent())->not->toContain('secret');
})->with([
    'parent' => ['../outside.txt'],
    'nested parent' => ['videos/../../outside.txt'],
    'encoded' => ['%2e%2e/outside.txt'],
    'encoded slash' => ['videos%2F..%2F..%2Foutside.txt'],
    'double encoded' => ['%252e%252e%252foutside.txt'],
    'backslash' => ['..\\outside.txt'],
    'absolute' => ['/etc/passwd'],
    'scheme' => ['file:///etc/passwd'],
]);

it('refuses a raw encoded climb in the request URL', function () {
    $link = str_replace('videos/intro.mp4', 'videos/%2e%2e/%2e%2e/outside.txt', mediaLink($this->viewer));

    $response = $this->actingAs($this->viewer)->getJson($link);

    expect($response->status())->toBeIn([403, 404]);
});

it('refuses a symlink in the container that points out of it', function () {
    $root = Storage::disk('private_media')->path('');
    file_put_contents(dirname($root).'/outside-link-target.txt', 'secret');
    @symlink(dirname($root).'/outside-link-target.txt', $root.'escape.txt');

    try {
        $this->actingAs($this->viewer)->getJson(signedFor($this->viewer, 'escape.txt'))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    } finally {
        @unlink($root.'escape.txt');
        @unlink(dirname($root).'/outside-link-target.txt');
    }
});

it('serves nothing from outside the configured container', function () {
    Storage::fake('elsewhere');
    Storage::disk('elsewhere')->put('videos/intro.mp4', 'wrong');

    expect(PrivateMedia::url($this->viewer, 'cvt-101', 'elsewhere::videos/intro.mp4'))->toBeNull();
});

it('does not sign a link for a path that could never be a file', function () {
    expect(PrivateMedia::url($this->viewer, 'cvt-101', '../.env'))->toBeNull()
        ->and(PrivateMedia::url($this->viewer, '../cvt-101', 'videos/intro.mp4'))->toBeNull()
        ->and(PrivateMedia::url(null, 'cvt-101', 'videos/intro.mp4'))->toBeNull();
});

it('does not tell a viewer without access whether a file exists', function () {
    $stranger = viewer('c@example.test');

    $this->actingAs($stranger)->getJson(signedFor($stranger, 'videos/intro.mp4'))->assertJsonPath('error.code', 'no_access');
    $this->actingAs($stranger)->getJson(signedFor($stranger, 'videos/missing.mp4'))->assertJsonPath('error.code', 'no_access');
});
