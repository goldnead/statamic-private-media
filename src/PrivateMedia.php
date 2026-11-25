<?php

namespace Goldnead\PrivateMedia;

use Goldnead\PrivateMedia\Support\MediaPath;
use Goldnead\PrivateMedia\Support\UserId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Statamic\Contracts\Assets\Asset;

/**
 * Makes the signed links the delivery route answers.
 *
 * A link carries the resource, the file's path, an expiry and a token bound
 * to the user it was made for. Laravel signs all of it: editing any part,
 * the deadline included, breaks the signature. The token is an HMAC of the
 * user id under the app key, so the link does not print the id, and a link
 * opened by anybody else is refused as `wrong_user` before access is asked.
 */
class PrivateMedia
{
    public const ROUTE = 'private-media.show';

    /**
     * A signed link for this user to this file of this resource, or null when
     * there is no user, no route, or the asset is not a path in the source.
     *
     * @param  mixed  $asset  a path in the container, a `container::path` id, or a Statamic asset
     */
    public function url(mixed $user, string $resource, mixed $asset, ?int $ttlMinutes = null): ?string
    {
        $userId = UserId::tryOf($user);

        // A guest gets no link by design: nothing to log.
        if ($userId === null) {
            return null;
        }

        // An empty optional asset field is an ordinary state in a template, not a
        // misconfiguration: no link, no log line per rendered row.
        if ($asset === null || $asset === '') {
            return null;
        }

        $path = $this->pathOf($asset);

        if ($path === null || trim($resource) === '' || str_contains($resource, '/')) {
            Log::warning('private-media: no link, the resource or the asset is not valid.', [
                'resource' => $resource,
                'path' => is_string($asset) ? $asset : get_debug_type($asset),
            ]);

            return null;
        }

        if (! app('router')->has(self::ROUTE)) {
            Log::warning('private-media: no link, the delivery route is not registered.', ['resource' => $resource, 'path' => $path]);

            return null;
        }

        $ttl = $ttlMinutes ?? (int) config('private-media.links.ttl', 240);

        return URL::temporarySignedRoute(
            self::ROUTE,
            Carbon::now()->addMinutes(max(1, $ttl)),
            ['resource' => $resource, 'path' => $path, 'viewer' => $this->viewerToken($userId)],
        );
    }

    /**
     * The token a link carries for its user. Stable per user and app key.
     */
    public function viewerToken(string $userId): string
    {
        return substr(hash_hmac('sha256', 'private-media|viewer|'.$userId, (string) config('app.key')), 0, 40);
    }

    /**
     * The path inside the configured container, from whatever a template holds.
     * An asset or id from a different container is not ours to sign.
     */
    public function pathOf(mixed $asset): ?string
    {
        $container = config('private-media.source.container');
        $container = is_string($container) && $container !== '' ? $container : null;

        if ($asset instanceof Asset) {
            if (! $asset instanceof \Statamic\Assets\Asset) {
                return null;
            }

            if ($container === null || $asset->container()->handle() !== $container) {
                return null;
            }

            return MediaPath::normalize($asset->path());
        }

        if (! is_string($asset)) {
            return null;
        }

        if (str_contains($asset, '::')) {
            [$handle, $path] = explode('::', $asset, 2);

            if ($container === null || $handle !== $container) {
                return null;
            }

            $asset = $path;
        }

        return MediaPath::normalize($asset);
    }
}
