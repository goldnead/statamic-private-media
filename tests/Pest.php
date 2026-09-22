<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\PrivateMedia\Facades\PrivateMedia;
use Goldnead\PrivateMedia\Tests\Fixtures\Viewer;
use Goldnead\PrivateMedia\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class)->in('Feature', 'Unit');

function viewer(string $email = 'a@example.test'): Viewer
{
    return Viewer::query()->create(['email' => $email]);
}

/** A viewer holding the product `$resource`. */
function entitledViewer(string $resource = 'cvt-101', string $email = 'a@example.test'): Viewer
{
    $viewer = viewer($email);
    Entitlements::grant($viewer, $resource, 'test');

    return $viewer;
}

function putMedia(string $path, string $contents = '0123456789'): void
{
    Storage::disk('private_media')->put($path, $contents);
}

/** A signed link to the path for the viewer, relative, ready for $this->get(). */
function mediaLink(Viewer $viewer, string $path = 'videos/intro.mp4', string $resource = 'cvt-101'): string
{
    $url = PrivateMedia::url($viewer, $resource, $path);

    expect($url)->toBeString();

    return (string) $url;
}
