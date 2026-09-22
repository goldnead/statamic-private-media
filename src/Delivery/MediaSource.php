<?php

namespace Goldnead\PrivateMedia\Delivery;

use Goldnead\PrivateMedia\Support\MediaPath;
use Goldnead\PrivateMedia\Support\Reason;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Statamic\Facades\AssetContainer;
use Throwable;

/**
 * Finds a requested path in the configured container (or disk), or says why not.
 *
 * Three fences, in order: the path's shape ({@see MediaPath}), the lookup in
 * the container, and for local disks the resolved location under the disk's
 * root. A path that fails any of them is `not_found`; the caller does not
 * learn which fence stopped it.
 */
class MediaSource
{
    /**
     * The handle of the disk the source sits on, or null when neither a
     * container nor a disk is configured (or the container does not exist).
     */
    public function diskName(): ?string
    {
        $handle = config('private-media.source.container');

        if (is_string($handle) && $handle !== '') {
            $container = AssetContainer::find($handle);
            $disk = $container instanceof \Statamic\Assets\AssetContainer ? $container->diskHandle() : null;

            return is_string($disk) && $disk !== '' ? $disk : null;
        }

        $disk = config('private-media.source.disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    public function locate(string $rawPath): MediaFile|Reason
    {
        $path = MediaPath::normalize($rawPath);

        if ($path === null) {
            return Reason::NotFound;
        }

        $diskName = $this->diskName();

        if ($diskName === null) {
            Log::warning('private-media: no source configured', [
                'reason' => Reason::StorageUnavailable->value,
                'container' => config('private-media.source.container'),
                'disk' => config('private-media.source.disk'),
                'runbook' => 'Set private-media.source.container to an existing asset container, or source.disk to a disk.',
            ]);

            return Reason::StorageUnavailable;
        }

        try {
            $filesystem = Storage::disk($diskName);

            // The realpath fence first: a symlink out of the root must be a
            // plain `not_found`, before Flysystem gets to throw about it.
            if (! $this->insideLocalRoot($filesystem, $path)
                || ! $this->inSource($path)
                || ! $filesystem->exists($path)) {
                return Reason::NotFound;
            }

            $size = (int) $filesystem->size($path);

            if ($size <= 0) {
                return Reason::FileEmpty;
            }

            $mime = $filesystem->mimeType($path);
        } catch (Throwable $e) {
            if (! self::isStorageFailure($e)) {
                throw $e;
            }

            Log::warning('private-media: storage did not answer', [
                'reason' => Reason::StorageUnavailable->value,
                'disk' => $diskName,
                'path' => $path,
                'exception' => $e,
                'runbook' => 'Nothing to fix on the entry: check the disk and its credentials.',
            ]);

            return Reason::StorageUnavailable;
        }

        return new MediaFile(
            $diskName,
            $filesystem,
            $path,
            $size,
            is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
        );
    }

    /**
     * Narrow on purpose, as in the source site: a programming error (a
     * missing method, a bad argument) must surface as a 500 with its stack
     * trace, not dressed up as a storage outage.
     */
    public static function isStorageFailure(Throwable $e): bool
    {
        return $e instanceof FilesystemException
            || is_a($e, 'Aws\Exception\AwsException');
    }

    protected function inSource(string $path): bool
    {
        $handle = config('private-media.source.container');

        if (! is_string($handle) || $handle === '') {
            return true;
        }

        $container = AssetContainer::find($handle);

        return $container !== null && $container->asset($path) !== null;
    }

    /**
     * On a local disk, the file's real location must lie under the disk's
     * real root. Catches a symlink inside the container that points out of it.
     */
    protected function insideLocalRoot(Filesystem $filesystem, string $path): bool
    {
        if (! $filesystem instanceof LocalFilesystemAdapter) {
            return true;
        }

        $root = realpath($filesystem->path(''));
        $file = realpath($filesystem->path($path));

        if ($root === false || $file === false) {
            return false;
        }

        return str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
