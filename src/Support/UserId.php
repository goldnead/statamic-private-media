<?php

namespace Goldnead\PrivateMedia\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Turns whatever the caller holds for a user into the string links and the
 * audit table key on. Statamic users, file or Eloquent, are Authenticatable.
 */
final class UserId
{
    public static function of(mixed $user): string
    {
        $id = self::tryOf($user);

        if ($id === null) {
            throw new InvalidArgumentException('Private media needs a user id: pass a user, an Authenticatable or the id itself.');
        }

        return $id;
    }

    public static function tryOf(mixed $user): ?string
    {
        $id = match (true) {
            $user instanceof Authenticatable => $user->getAuthIdentifier(),
            is_string($user), is_int($user) => $user,
            default => null,
        };

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }
}
