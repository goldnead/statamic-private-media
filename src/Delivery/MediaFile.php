<?php

namespace Goldnead\PrivateMedia\Delivery;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * A file that exists, is not empty and lies inside the configured source.
 * Only MediaSource makes these.
 */
final class MediaFile
{
    public function __construct(
        public readonly string $disk,
        public readonly Filesystem $filesystem,
        public readonly string $path,
        public readonly int $size,
        public readonly string $mimeType,
    ) {}

    public function name(): string
    {
        return basename($this->path);
    }
}
