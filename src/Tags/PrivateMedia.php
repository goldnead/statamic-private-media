<?php

namespace Goldnead\PrivateMedia\Tags;

use Goldnead\PrivateMedia\PrivateMedia as Links;
use Illuminate\Support\Collection;
use Statamic\Fields\Value;
use Statamic\Tags\Tags;

/**
 * {{ private_media:url resource="cvt-101" asset="videos/intro.mp4" }}
 *
 * A signed link to the file for the signed-in user. Renders nothing for a
 * guest, with the route switched off, or for an asset outside the container:
 * a template never prints a link that would only be refused.
 *
 * Parameters: `resource` (required), `asset` (a path, a `container::path`
 * id, or an asset from an assets field), `ttl` (minutes, optional).
 */
class PrivateMedia extends Tags
{
    protected static $handle = 'private_media';

    public function url(): string
    {
        $ttl = $this->params->int('ttl');

        return app(Links::class)->url(
            auth(config('private-media.routes.guard'))->user(),
            (string) $this->params->get('resource', ''),
            $this->asset($this->params->get('asset')),
            $ttl > 0 ? $ttl : null,
        ) ?? '';
    }

    /**
     * An assets field hands over a Value, and with max_files above one a
     * collection; the first asset is the one meant.
     */
    protected function asset(mixed $asset): mixed
    {
        if ($asset instanceof Value) {
            $asset = $asset->value();
        }

        if ($asset instanceof Collection) {
            $asset = $asset->first();
        }

        return is_array($asset) ? reset($asset) : $asset;
    }
}
