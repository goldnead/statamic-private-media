<?php

use Goldnead\PrivateMedia\Access\ClosedMediaAccess;
use Goldnead\PrivateMedia\PrivateMedia;
use Goldnead\PrivateMedia\ServiceProvider;
use Illuminate\Support\Facades\Log;

it('logs once per process that no MediaAccess is bound and every file is closed', function () {
    (new ReflectionProperty(ServiceProvider::class, 'closedWarned'))->setValue(null, false);
    Log::spy();
    $provider = new ServiceProvider($this->app);
    $resolve = fn () => $this->mediaAccess(app(), false);

    $first = Closure::bind($resolve, $provider, ServiceProvider::class)();
    $second = Closure::bind($resolve, $provider, ServiceProvider::class)();

    expect($first)->toBeInstanceOf(ClosedMediaAccess::class)
        ->and($second)->toBeInstanceOf(ClosedMediaAccess::class);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'no MediaAccess bound'))
        ->once();
});

it('logs the resource and the asset when a link cannot be made for a signed-in viewer', function () {
    Log::spy();
    $viewer = entitledViewer();

    expect(app(PrivateMedia::class)->url($viewer, 'cvt-101', 'other::videos/intro.mp4'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => ($context['resource'] ?? null) === 'cvt-101'
            && ($context['path'] ?? null) === 'other::videos/intro.mp4')
        ->once();
});

it('does not log for a guest, who gets no link by design', function () {
    Log::spy();

    expect(app(PrivateMedia::class)->url(null, 'cvt-101', 'videos/intro.mp4'))->toBeNull();

    Log::shouldNotHaveReceived('warning');
});
