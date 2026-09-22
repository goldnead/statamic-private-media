<?php

use Goldnead\PrivateMedia\Delivery\ByteRange;

it('answers a missing or foreign header with the whole file', function (?string $header) {
    $range = ByteRange::resolve($header, 100);

    expect([$range->status, $range->start, $range->end])->toBe([200, 0, 99]);
})->with([
    'none' => [null],
    'empty' => [''],
    'other unit' => ['items=0-5'],
    'garbage' => ['bytes=abc'],
    'no numbers' => ['bytes=-'],
]);

it('serves a closed range', function () {
    $range = ByteRange::resolve('bytes=0-3', 100);

    expect([$range->status, $range->start, $range->end, $range->length()])->toBe([206, 0, 3, 4]);
});

it('clamps an end past the file to the last byte', function () {
    $range = ByteRange::resolve('bytes=90-500', 100);

    expect([$range->status, $range->start, $range->end])->toBe([206, 90, 99]);
});

it('serves an open range to the end', function () {
    $range = ByteRange::resolve('bytes=40-', 100);

    expect([$range->status, $range->start, $range->end])->toBe([206, 40, 99]);
});

it('serves a suffix range as the last n bytes', function () {
    $range = ByteRange::resolve('bytes=-5', 100);

    expect([$range->status, $range->start, $range->end])->toBe([206, 95, 99]);
});

it('serves a suffix longer than the file as the whole file, partially', function () {
    $range = ByteRange::resolve('bytes=-500', 100);

    expect([$range->status, $range->start, $range->end])->toBe([206, 0, 99]);
});

it('ignores a range whose start lies after its end', function () {
    $range = ByteRange::resolve('bytes=50-10', 100);

    expect([$range->status, $range->start, $range->end])->toBe([200, 0, 99]);
});

it('refuses a range starting at or past the end with 416', function (string $header) {
    expect(ByteRange::resolve($header, 100)->isSatisfiable())->toBeFalse();
})->with([
    'at size' => ['bytes=100-'],
    'beyond' => ['bytes=150-200'],
    'huge' => ['bytes=99999999999999999999999-'],
    'empty suffix' => ['bytes=-0'],
]);

it('answers several ranges with the whole file', function () {
    $range = ByteRange::resolve('bytes=0-1,4-5', 100);

    expect([$range->status, $range->start, $range->end])->toBe([200, 0, 99]);
});

it('counts only a request from byte 0 as an opening', function () {
    expect(ByteRange::resolve(null, 100)->isOpening())->toBeTrue()
        ->and(ByteRange::resolve('bytes=0-1', 100)->isOpening())->toBeTrue()
        ->and(ByteRange::resolve('bytes=10-', 100)->isOpening())->toBeFalse();
});
