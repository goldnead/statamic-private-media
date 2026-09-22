<?php

namespace Goldnead\PrivateMedia\Events;

use Goldnead\PrivateMedia\Support\Reason;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A request for a file was turned down. `$reason` says why; `$user` is
 * whoever was signed in, which for `wrong_user` is not the link's owner.
 */
class MediaRefused
{
    use Dispatchable;

    public function __construct(
        public readonly mixed $user,
        public readonly string $resource,
        public readonly string $path,
        public readonly Reason $reason,
    ) {}
}
