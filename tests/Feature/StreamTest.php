<?php

use Goldnead\PrivateMedia\Events\MediaServed;
use Illuminate\Support\Facades\Event;

/*
 * Ported from adriangoldner.com tests/Feature/ProtectedMediaApiTest.php
 * (test_entitled_user_can_stream_private_video_with_range_requests) and
 * extended by the range cases the source answered with a full 200.
 */

beforeEach(function () {
    putMedia('videos/intro.mp4', '0123456789');
    $this->viewer = entitledViewer();
    $this->link = mediaLink($this->viewer);
});

it('streams the whole file to an entitled viewer', function () {
    $response = $this->actingAs($this->viewer)->get($this->link);

    $response->assertOk()
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Length', '10');

    expect($response->streamedContent())->toBe('0123456789');
});

it('answers a byte range with 206 and Content-Range', function () {
    $response = $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=0-3'])->get($this->link);

    $response->assertStatus(206)
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Content-Range', 'bytes 0-3/10')
        ->assertHeader('Content-Length', '4');

    expect($response->streamedContent())->toBe('0123');
});

it('answers a suffix range with the last bytes', function () {
    $response = $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=-3'])->get($this->link);

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 7-9/10');

    expect($response->streamedContent())->toBe('789');
});

it('answers an open range from the middle', function () {
    $response = $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=6-'])->get($this->link);

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 6-9/10');

    expect($response->streamedContent())->toBe('6789');
});

it('clamps a range reaching past the end', function () {
    $response = $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=8-500'])->get($this->link);

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 8-9/10');

    expect($response->streamedContent())->toBe('89');
});

it('refuses a range past the end with 416 and the size', function () {
    $response = $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=10-20'])->get($this->link);

    $response->assertStatus(416)
        ->assertHeader('Content-Range', 'bytes */10')
        ->assertJsonPath('error.code', 'range_not_satisfiable');
});

it('sends the whole file for an inverted range', function () {
    $response = $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=5-2'])->get($this->link);

    $response->assertOk();

    expect($response->streamedContent())->toBe('0123456789');
});

it('sends the whole file for a multi-range request', function () {
    $response = $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=0-1,4-5'])->get($this->link);

    $response->assertOk()->assertHeaderMissing('Content-Range');

    expect($response->streamedContent())->toBe('0123456789');
});

it('marks only the first request of a playback as an opening', function () {
    Event::fake([MediaServed::class]);

    $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=0-1'])->get($this->link)->assertStatus(206);
    $this->actingAs($this->viewer)->withHeaders(['Range' => 'bytes=5-'])->get($this->link)->assertStatus(206);

    Event::assertDispatched(MediaServed::class, fn (MediaServed $e) => $e->opening && $e->delivery === 'stream' && $e->path === 'videos/intro.mp4');
    Event::assertDispatched(MediaServed::class, fn (MediaServed $e) => ! $e->opening && $e->range === 'bytes=5-');
});

it('does not let the browser cache a private file', function () {
    $this->actingAs($this->viewer)->get($this->link)
        ->assertHeader('Cache-Control', 'no-store, private');
});
