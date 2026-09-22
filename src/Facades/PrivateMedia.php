<?php

namespace Goldnead\PrivateMedia\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null url(mixed $user, string $resource, mixed $asset, ?int $ttlMinutes = null)
 * @method static string viewerToken(string $userId)
 * @method static string|null pathOf(mixed $asset)
 *
 * @see \Goldnead\PrivateMedia\PrivateMedia
 */
class PrivateMedia extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Goldnead\PrivateMedia\PrivateMedia::class;
    }
}
