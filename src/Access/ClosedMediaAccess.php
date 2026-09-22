<?php

namespace Goldnead\PrivateMedia\Access;

use Goldnead\PrivateMedia\Contracts\MediaAccess;

/**
 * The answer when nothing is installed that could give a better one: no.
 *
 * A site that forgot to install entitlements should find its media shut, not
 * handed out to everyone with an account.
 */
class ClosedMediaAccess implements MediaAccess
{
    public function allows(mixed $user, string $resource, string $path): bool
    {
        return false;
    }
}
