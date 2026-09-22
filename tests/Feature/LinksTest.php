<?php

use Goldnead\PrivateMedia\Facades\PrivateMedia;
use Goldnead\PrivateMedia\ServiceProvider;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\Antlers;
use Statamic\Facades\AssetContainer;

beforeEach(function () {
    putMedia('videos/intro.mp4', '0123456789');
    $this->viewer = entitledViewer();
});

function renderTag(string $template, array $data = []): string
{
    return (string) Antlers::parse($template, $data, true);
}

it('renders a signed link for the signed-in viewer that opens the file', function () {
    $this->actingAs($this->viewer);

    $url = renderTag('{{ private_media:url resource="cvt-101" asset="videos/intro.mp4" }}');

    expect($url)->toStartWith('http://localhost/private-media/cvt-101/videos/intro.mp4?')
        ->toContain('signature=')
        ->toContain('expires=')
        ->not->toContain('viewer='.$this->viewer->getKey().'&');

    $this->get(str_replace('&amp;', '&', $url))->assertOk();
});

it('renders nothing for a guest', function () {
    expect(renderTag('{{ private_media:url resource="cvt-101" asset="videos/intro.mp4" }}'))->toBe('');
});

it('accepts a container::path id and a Statamic asset', function () {
    $this->actingAs($this->viewer);
    $asset = AssetContainer::find('private')->asset('videos/intro.mp4');

    expect(renderTag('{{ private_media:url resource="cvt-101" asset="private::videos/intro.mp4" }}'))->toContain('/videos/intro.mp4?')
        ->and(renderTag('{{ private_media:url resource="cvt-101" :asset="video" }}', ['video' => $asset]))->toContain('/videos/intro.mp4?');
});

it('renders nothing for an asset from another container', function () {
    $this->actingAs($this->viewer);

    expect(renderTag('{{ private_media:url resource="cvt-101" asset="assets::videos/intro.mp4" }}'))->toBe('');
});

it('uses the configured link lifetime, and a ttl parameter over it', function () {
    Carbon::setTestNow('2026-09-22 12:00:00');
    config()->set('private-media.links.ttl', 30);
    $this->actingAs($this->viewer);

    try {
        $default = renderTag('{{ private_media:url resource="cvt-101" asset="videos/intro.mp4" }}');
        $short = renderTag('{{ private_media:url resource="cvt-101" asset="videos/intro.mp4" ttl="2" }}');

        expect($default)->toContain('expires='.Carbon::now()->addMinutes(30)->getTimestamp())
            ->and($short)->toContain('expires='.Carbon::now()->addMinutes(2)->getTimestamp());
    } finally {
        Carbon::setTestNow();
    }
});

it('mounts the route under the configured prefix', function () {
    config()->set('private-media.routes.prefix', 'members/media');
    Route::setRoutes(new RouteCollection);
    app()->getProvider(ServiceProvider::class)?->bootAddon();

    expect(PrivateMedia::url($this->viewer, 'cvt-101', 'videos/intro.mp4'))->toStartWith('http://localhost/members/media/cvt-101/');
});

it('mounts no route and signs no link while switched off', function () {
    config()->set('private-media.routes.enabled', false);
    Route::setRoutes(new RouteCollection);
    app()->getProvider(ServiceProvider::class)?->bootAddon();

    expect(Route::has('private-media.show'))->toBeFalse()
        ->and(PrivateMedia::url($this->viewer, 'cvt-101', 'videos/intro.mp4'))->toBeNull();
});
