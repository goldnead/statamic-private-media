<?php

namespace Goldnead\PrivateMedia\Access;

use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\PrivateMedia\Contracts\MediaAccess;
use Goldnead\PrivateMedia\Support\UserId;
use Illuminate\Database\Eloquent\Model;

/**
 * Asks statamic-entitlements whether the user holds the resource as a product.
 *
 * The resource slug is the product slug. The file is not looked at: a site
 * whose resources share one container and must not see each other's files
 * binds its own MediaAccess.
 *
 * How a user becomes the (type, id) pair entitlements keys on, as in
 * statamic-courses:
 *
 * 1. An Eloquent model or a SubjectReference passes straight through.
 * 2. A Statamic eloquent user (`Statamic\Auth\Eloquent\User`) is unwrapped to
 *    its model, so the model's morph class is used, as a checkout that
 *    granted to the model did.
 * 3. Anything else becomes a reference of the configured type; unconfigured,
 *    the auth model's morph class on an eloquent install, `user` on a
 *    flat-file one.
 */
class EntitlementsMediaAccess implements MediaAccess
{
    public function __construct(private readonly EntitlementManager $entitlements) {}

    public function allows(mixed $user, string $resource, string $path): bool
    {
        if ($user === null || $resource === '') {
            return false;
        }

        return $this->entitlements->allows($this->subject($user), $resource);
    }

    protected function subject(mixed $user): Model|SubjectReference
    {
        if ($user instanceof Model || $user instanceof SubjectReference) {
            return $user;
        }

        if (is_object($user) && method_exists($user, 'model')) {
            $model = $user->model();

            if ($model instanceof Model) {
                return $model;
            }
        }

        return new SubjectReference($this->subjectType(), UserId::of($user));
    }

    protected function subjectType(): string
    {
        $configured = config('private-media.entitlements.subject_type');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $class = config('auth.providers.users.model');

        if (config('statamic.users.repository') === 'eloquent'
            && is_string($class) && is_subclass_of($class, Model::class)) {
            return (new $class)->getMorphClass();
        }

        return 'user';
    }
}
