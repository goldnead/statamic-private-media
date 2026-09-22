<?php

use Goldnead\PrivateMedia\Models\AccessLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    putMedia('videos/intro.mp4', '0123456789');
});

it('writes a refusal with its reason', function () {
    $viewer = viewer();

    $this->actingAs($viewer)->withHeaders(['User-Agent' => 'TestPlayer/1'])->getJson(mediaLink($viewer));

    expect(AccessLog::query()->sole())
        ->user_id->toBe((string) $viewer->getKey())
        ->resource->toBe('cvt-101')
        ->path->toBe('videos/intro.mp4')
        ->allowed->toBeFalse()
        ->reason->toBe('no_access')
        ->user_agent->toBe('TestPlayer/1');
});

it('writes the opening of a playback, not every seek', function () {
    $viewer = entitledViewer();
    $link = mediaLink($viewer);

    $this->actingAs($viewer)->withHeaders(['Range' => 'bytes=0-'])->get($link);
    $this->actingAs($viewer)->withHeaders(['Range' => 'bytes=4-'])->get($link);
    $this->actingAs($viewer)->withHeaders(['Range' => 'bytes=8-'])->get($link);

    expect(AccessLog::query()->count())->toBe(1)
        ->and(AccessLog::query()->sole())->allowed->toBeTrue()->delivery->toBe('stream')->range->toBe('bytes=0-');
});

it('writes every range when asked to', function () {
    config()->set('private-media.audit.log_ranges', true);
    $viewer = entitledViewer();
    $link = mediaLink($viewer);

    $this->actingAs($viewer)->withHeaders(['Range' => 'bytes=0-'])->get($link);
    $this->actingAs($viewer)->withHeaders(['Range' => 'bytes=4-'])->get($link);

    expect(AccessLog::query()->count())->toBe(2);
});

it('writes nothing when switched off', function () {
    config()->set('private-media.audit.enabled', false);
    $viewer = viewer();

    $this->actingAs($viewer)->getJson(mediaLink($viewer))->assertForbidden();

    expect(AccessLog::query()->count())->toBe(0);
});

it('keeps a long path and user agent within their columns', function () {
    $viewer = viewer();
    $path = str_repeat('a', 200).'/'.str_repeat('b', 200).'.mp4';

    $this->actingAs($viewer)->withHeaders(['User-Agent' => str_repeat('x', 2000)])->getJson(mediaLink($viewer, $path));

    expect(mb_strlen((string) AccessLog::query()->sole()->user_agent))->toBe(512);
});

it('uses its own prefixed table, not one of the source site\'s', function () {
    expect(Schema::hasTable('private_media_access_log'))->toBeTrue()
        ->and(Schema::hasTable('resource_download_audits'))->toBeFalse();
});

it('prunes rows past the retention', function () {
    AccessLog::query()->create(['resource' => 'a', 'allowed' => true, 'reason' => 'served', 'created_at' => Carbon::now()->subDays(91)]);
    AccessLog::query()->create(['resource' => 'b', 'allowed' => false, 'reason' => 'no_access', 'created_at' => Carbon::now()->subDays(89)]);

    $this->artisan('private-media:prune')->assertSuccessful();

    expect(AccessLog::query()->pluck('resource')->all())->toBe(['b']);
});

it('prunes by a --days override', function () {
    AccessLog::query()->create(['resource' => 'a', 'allowed' => true, 'reason' => 'served', 'created_at' => Carbon::now()->subDays(10)]);

    $this->artisan('private-media:prune', ['--days' => 5])->assertSuccessful();

    expect(AccessLog::query()->count())->toBe(0);
});

it('refuses a retention below one day', function () {
    $this->artisan('private-media:prune', ['--days' => 0])->assertFailed();
});
