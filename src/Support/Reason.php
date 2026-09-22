<?php

namespace Goldnead\PrivateMedia\Support;

/**
 * Why a file did or did not go out. The value is what the JSON refusal, the
 * audit row and MediaRefused carry; name one in a support ticket and the log
 * line is one search away.
 */
enum Reason: string
{
    case Served = 'served';

    /** The signature does not match: the link was edited or made elsewhere. */
    case Signature = 'signature';

    /** The signature matches, the deadline has passed. */
    case Expired = 'expired';

    /** Nobody is signed in. */
    case Unauthenticated = 'unauthenticated';

    /** Somebody is signed in, but not the user the link was made for. */
    case WrongUser = 'wrong_user';

    /** MediaAccess said no. */
    case NoAccess = 'no_access';

    /** No such file in the container, or a path that could never be one. */
    case NotFound = 'not_found';

    /** The file is there and has zero bytes: an aborted upload. */
    case FileEmpty = 'file_empty';

    /** The storage did not answer, or cannot hand out what the strategy asks for. */
    case StorageUnavailable = 'storage_unavailable';

    /** A byte range that starts past the end of the file. */
    case RangeNotSatisfiable = 'range_not_satisfiable';

    public function status(): int
    {
        return match ($this) {
            self::Served => 200,
            self::Signature, self::Expired, self::WrongUser, self::NoAccess => 403,
            self::Unauthenticated => 401,
            self::NotFound, self::FileEmpty => 404,
            self::StorageUnavailable => 503,
            self::RangeNotSatisfiable => 416,
        };
    }

    public function message(): string
    {
        return __('private-media::messages.'.$this->value);
    }
}
