<?php

use Goldnead\PrivateMedia\Support\MediaPath;

it('keeps a plain relative path', function (string $path) {
    expect(MediaPath::normalize($path))->toBe($path);
})->with([
    'file' => ['intro.mp4'],
    'nested' => ['videos/module 1/intro.mp4'],
    'dots in name' => ['videos/v1.2..final.mp4'],
]);

it('refuses every path that could leave the container', function (mixed $path) {
    expect(MediaPath::normalize($path))->toBeNull();
})->with([
    'parent' => ['../secret.txt'],
    'parent in the middle' => ['videos/../../.env'],
    'trailing parent' => ['videos/..'],
    'current dir' => ['./intro.mp4'],
    'absolute' => ['/etc/passwd'],
    'double slash' => ['videos//intro.mp4'],
    'backslash' => ['..\\secret.txt'],
    'windows drive' => ['C:/Windows/win.ini'],
    'scheme' => ['file:///etc/passwd'],
    'stream wrapper' => ['php://filter/resource=/etc/passwd'],
    'null byte' => ["intro.mp4\0.txt"],
    'newline' => ["intro\n.mp4"],
    'still encoded slash' => ['videos%2F..%2F.env'],
    'still encoded dot' => ['%2e%2e/secret.txt'],
    'empty' => [''],
    'not a string' => [['a']],
    'too long' => [str_repeat('a', 1025)],
]);
