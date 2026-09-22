<?php

namespace Goldnead\PrivateMedia\Contracts;

use Goldnead\PrivateMedia\Access\ClosedMediaAccess;
use Goldnead\PrivateMedia\Access\EntitlementsMediaAccess;

/**
 * May this user open this file of this resource?
 *
 * A resource is whatever the site sells or shares access to: a course, a
 * library, a workshop recording. The file is a path inside the configured
 * container, already checked to be one. Bound to
 * {@see EntitlementsMediaAccess} when statamic-entitlements is installed and
 * to {@see ClosedMediaAccess} otherwise. Bind your own to answer differently,
 * for example to check that the file belongs to the resource.
 */
interface MediaAccess
{
    /**
     * @param  mixed  $user  the signed-in user (an Authenticatable, a Statamic user, or whatever your binding accepts)
     * @param  string  $resource  the resource slug from the link
     * @param  string  $path  the file's path inside the container
     */
    public function allows(mixed $user, string $resource, string $path): bool;
}
