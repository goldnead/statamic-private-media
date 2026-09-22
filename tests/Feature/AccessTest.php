<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\PrivateMedia\Access\ClosedMediaAccess;
use Goldnead\PrivateMedia\Access\EntitlementsMediaAccess;
use Goldnead\PrivateMedia\Contracts\MediaAccess;
use Goldnead\PrivateMedia\Events\MediaRefused;
use Goldnead\PrivateMedia\Support\Reason;
use Illuminate\Support\Facades\Event;
use Statamic\Auth\Eloquent\User as EloquentUser;

/*
 * Ported from adriangoldner.com tests/Feature/ProtectedMediaApiTest.php:
 * test_non_entitled_user_cannot_stream_private_video,
 * test_abgelehnter_videozugriff_steht_in_der_pruefspur.
 */

beforeEach(function () {
    putMedia('videos/intro.mp4', '0123456789');
});

it('binds the entitlements bridge when entitlements is installed', function () {
    expect(app(MediaAccess::class))->toBeInstanceOf(EntitlementsMediaAccess::class);
});

it('fails closed without an access answer', function () {
    expect((new ClosedMediaAccess)->allows(viewer(), 'cvt-101', 'videos/intro.mp4'))->toBeFalse();
});

it('refuses a viewer without the product, and says so', function () {
    Event::fake([MediaRefused::class]);
    $viewer = viewer();

    $this->actingAs($viewer)->getJson(mediaLink($viewer))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'no_access');

    Event::assertDispatched(MediaRefused::class, fn (MediaRefused $e) => $e->reason === Reason::NoAccess && $e->resource === 'cvt-101');
});

it('refuses a viewer entitled to a different resource', function () {
    $viewer = entitledViewer('other-course');

    $this->actingAs($viewer)->getJson(mediaLink($viewer))->assertJsonPath('error.code', 'no_access');
});

it('lets a site bind its own answer', function () {
    app()->instance(MediaAccess::class, new class implements MediaAccess
    {
        public function allows(mixed $user, string $resource, string $path): bool
        {
            return str_starts_with($path, $resource.'/');
        }
    });

    putMedia('cvt-101/intro.mp4', 'abc');
    $viewer = viewer();

    $this->actingAs($viewer)->get(mediaLink($viewer, 'cvt-101/intro.mp4'))->assertOk();
    $this->actingAs($viewer)->getJson(mediaLink($viewer, 'videos/intro.mp4'))->assertJsonPath('error.code', 'no_access');
});

it('unwraps a Statamic eloquent user to its model', function () {
    $viewer = entitledViewer();
    $wrapped = (new EloquentUser)->model($viewer);

    expect(app(MediaAccess::class)->allows($wrapped, 'cvt-101', 'videos/intro.mp4'))->toBeTrue();
});

it('looks a bare id up under the auth model\'s morph class on an eloquent install', function () {
    $viewer = entitledViewer();
    config()->set('statamic.users.repository', 'eloquent');

    expect(app(MediaAccess::class)->allows((string) $viewer->getKey(), 'cvt-101', 'x.mp4'))->toBeTrue();
});

it('falls back to the type "user" for flat-file users', function () {
    config()->set('statamic.users.repository', 'file');
    Entitlements::grant(new SubjectReference('user', 'file-7'), 'cvt-101', 'test');

    expect(app(MediaAccess::class)->allows('file-7', 'cvt-101', 'x.mp4'))->toBeTrue();
});

it('lets an explicitly configured subject type win', function () {
    $viewer = entitledViewer();
    config()->set('statamic.users.repository', 'eloquent');
    config()->set('private-media.entitlements.subject_type', 'member');

    expect(app(MediaAccess::class)->allows((string) $viewer->getKey(), 'cvt-101', 'x.mp4'))->toBeFalse();
});
