<?php

use Goldnead\PrivateMedia\Models\AccessLog;
use Goldnead\PrivateMedia\ServiceProvider;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * Review round 2: an anonymous caller must not be able to fill the audit
 * table, and the route is throttled.
 */

beforeEach(function () {
    putMedia('videos/intro.mp4', '0123456789');
    $this->viewer = entitledViewer();
});

function remount(): void
{
    Route::setRoutes(new RouteCollection);
    app()->getProvider(ServiceProvider::class)?->bootAddon();
}

// 1. Audit flooding

it('writes no audit row for an anonymous request with a bad signature, only a log line', function () {
    Log::spy();

    $this->getJson('/private-media/cvt-101/videos/intro.mp4?signature=nope')->assertJsonPath('error.code', 'signature');
    $this->getJson('/private-media/cvt-101/'.str_repeat('a', 1000))->assertJsonPath('error.code', 'signature');

    expect(AccessLog::query()->count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => str_contains($message, 'private-media') && $context['reason'] === 'signature')->twice();
});

it('writes no audit row for a guest holding a valid link, only a log line', function () {
    Log::spy();

    $this->getJson(mediaLink($this->viewer))->assertJsonPath('error.code', 'unauthenticated');

    expect(AccessLog::query()->count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['reason'] === 'unauthenticated')->once();
});

it('sends refusal messages with raw umlauts and slashes, not \u escapes', function () {
    app()->setLocale('de');
    $other = viewer('b@example.test');

    $body = $this->actingAs($other)->getJson(mediaLink($this->viewer))
        ->assertJsonPath('error.code', 'wrong_user')
        ->getContent();

    expect($body)->toContain('gehört')->not->toContain(chr(92).'u00f6');
});

it('still writes a row for a signed-in user with a bad signature', function () {
    $this->actingAs($this->viewer)->getJson(mediaLink($this->viewer).'0')->assertJsonPath('error.code', 'signature');

    expect(AccessLog::query()->sole())->reason->toBe('signature')->user_id->toBe((string) $this->viewer->getKey());
});

it('throttles the route by default', function () {
    $route = Route::getRoutes()->getByName('private-media.show');

    expect($route->gatherMiddleware())->toContain('throttle:300,1');
});

it('answers 429 once the configured throttle is used up', function () {
    config()->set('private-media.routes.throttle', '2,1');
    remount();

    $this->getJson('/private-media/cvt-101/a.mp4')->assertForbidden();
    $this->getJson('/private-media/cvt-101/a.mp4')->assertForbidden();
    $this->getJson('/private-media/cvt-101/a.mp4')->assertStatus(429);
});

it('lets a site switch the throttle off', function () {
    config()->set('private-media.routes.throttle', null);
    remount();

    expect(collect(Route::getRoutes()->getByName('private-media.show')->gatherMiddleware())->filter(fn ($m) => str_starts_with($m, 'throttle')))->toBeEmpty();
});
