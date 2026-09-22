<?php

namespace Goldnead\PrivateMedia\Support;

/**
 * The only shape a file path may take before anything touches a disk.
 *
 * Relative, forward slashes, no empty, `.` or `..` segment, no scheme, no
 * drive letter, no control characters, no backslash. The router has already
 * decoded `%2e%2e%2f` into `../` by the time a path gets here, so an encoded
 * climb is caught as the plain one; a still-encoded `%2f` is refused
 * because no stored file needs one in its name.
 *
 * This is the first of two fences. The second is the lookup in the
 * container, and for local disks a realpath check that the file sits under
 * the disk's root (a symlink inside the root pointing out of it fails there).
 */
final class MediaPath
{
    public static function normalize(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > 1024) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
            return null;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $path) === 1) {
            return null;
        }

        if (stripos($path, '%2f') !== false || stripos($path, '%5c') !== false || stripos($path, '%2e') !== false) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }
}
