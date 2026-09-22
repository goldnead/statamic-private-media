<?php

namespace Goldnead\PrivateMedia\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A file went out, as a stream or a redirect to a temporary URL.
 *
 * Fired on every served request, byte-range continuations included;
 * `$opening` is true for the first request of a playback (no range, or one
 * starting at byte 0). Count views on that, not on the event itself.
 */
class MediaServed
{
    use Dispatchable;

    public function __construct(
        public readonly mixed $user,
        public readonly string $resource,
        public readonly string $path,
        public readonly string $delivery,
        public readonly bool $opening,
        public readonly ?string $range = null,
    ) {}
}
