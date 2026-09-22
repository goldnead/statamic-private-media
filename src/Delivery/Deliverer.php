<?php

namespace Goldnead\PrivateMedia\Delivery;

use Goldnead\PrivateMedia\Support\Reason;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Turns a located file into a response: a byte-range stream from this app,
 * or a redirect to a temporary URL of the storage provider.
 */
class Deliverer
{
    public const STREAM = 'stream';

    public const REDIRECT = 'redirect';

    /**
     * Which way the file leaves under the configured strategy.
     */
    public function mode(MediaFile $file): string
    {
        $strategy = (string) config('private-media.delivery.strategy', 'auto');

        if ($strategy === self::STREAM || $strategy === self::REDIRECT) {
            return $strategy;
        }

        // A local disk is streamed even when it can sign URLs: Laravel's own
        // local file serving answers without byte ranges, and a video that
        // cannot seek is worse than one that goes through this app.
        if ($file->filesystem instanceof LocalFilesystemAdapter) {
            return self::STREAM;
        }

        return $file->filesystem instanceof FilesystemAdapter && $file->filesystem->providesTemporaryUrls()
            ? self::REDIRECT
            : self::STREAM;
    }

    public function redirect(MediaFile $file): RedirectResponse|Reason
    {
        $filesystem = $file->filesystem;

        if (! $filesystem instanceof FilesystemAdapter || ! $filesystem->providesTemporaryUrls()) {
            Log::warning('private-media: redirect strategy on a disk without temporary URLs', [
                'reason' => Reason::StorageUnavailable->value,
                'disk' => $file->disk,
                'path' => $file->path,
                'runbook' => 'Set private-media.delivery.strategy to auto or stream, or use a disk that makes temporary URLs.',
            ]);

            return Reason::StorageUnavailable;
        }

        try {
            $url = $filesystem->temporaryUrl(
                $file->path,
                Carbon::now()->addMinutes(max(1, (int) config('private-media.delivery.temporary_url_ttl', 60))),
            );
        } catch (Throwable $e) {
            if (! MediaSource::isStorageFailure($e)) {
                throw $e;
            }

            Log::warning('private-media: storage did not sign a URL', [
                'reason' => Reason::StorageUnavailable->value,
                'disk' => $file->disk,
                'path' => $file->path,
                'exception' => $e,
                'runbook' => 'Nothing to fix on the entry: check the disk and its credentials.',
            ]);

            return Reason::StorageUnavailable;
        }

        return new RedirectResponse($url, 302, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Stream the range. The file is opened here, before any header is sent:
     * a file that cannot be opened is `storage_unavailable`, not a 200 whose
     * body breaks off after the headers promised ten megabytes.
     */
    public function stream(Request $request, MediaFile $file, ByteRange $range): StreamedResponse|Reason
    {
        $handle = $this->open($file);

        if ($handle === null) {
            return Reason::StorageUnavailable;
        }

        $headers = [
            'Content-Type' => $file->mimeType,
            'Content-Length' => (string) $range->length(),
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => $this->disposition($file),
            // The file is served from the site's own origin. A sandbox keeps
            // anything that slips past the disposition (an HTML file opened
            // directly, an SVG) from running script with the site's cookies.
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($range->isPartial()) {
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $range->start, $range->end, $file->size);
        }

        $chunk = max(1024, (int) config('private-media.delivery.chunk_size', 8192));

        return new StreamedResponse(function () use ($handle, $range, $chunk, $request): void {
            try {
                if ($request->isMethod('HEAD')) {
                    return;
                }

                $this->seek($handle, $range->start, $chunk);

                $remaining = $range->length();

                while ($remaining > 0 && ! feof($handle)) {
                    $bytes = fread($handle, min($chunk, $remaining));

                    if ($bytes === false || $bytes === '') {
                        break;
                    }

                    echo $bytes;
                    flush();
                    $remaining -= strlen($bytes);
                }
            } finally {
                fclose($handle);
            }
        }, $range->status, $headers);
    }

    /**
     * @return resource|null
     */
    private function open(MediaFile $file)
    {
        try {
            $handle = $file->filesystem->readStream($file->path);
        } catch (Throwable $e) {
            if (! MediaSource::isStorageFailure($e)) {
                throw $e;
            }

            $handle = null;
        }

        if (is_resource($handle)) {
            return $handle;
        }

        Log::warning('private-media: file could not be opened for streaming', [
            'reason' => Reason::StorageUnavailable->value,
            'disk' => $file->disk,
            'path' => $file->path,
            'runbook' => 'The file was found but would not open. Check the disk, its permissions and credentials.',
        ]);

        return null;
    }

    /**
     * Inline only for types a browser plays or shows without running code.
     * Everything else (HTML, SVG, XML, JavaScript, office files) is an
     * attachment: rendered same-origin it could script against the site.
     */
    public function disposition(MediaFile $file): string
    {
        $inline = preg_match('#^(video|audio)/#i', $file->mimeType) === 1
            || preg_match('#^image/(png|jpe?g|gif|webp|avif)$#i', $file->mimeType) === 1
            || in_array(strtolower($file->mimeType), ['application/pdf', 'text/plain'], true);

        $name = $file->name();
        $fallback = str_replace(['%', '/', '\\'], '_', Str::ascii($name));
        $fallback = (string) preg_replace('/[^\x20-\x7e]/', '_', $fallback);

        return HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            $fallback === '' ? 'download' : $fallback,
        );
    }

    /**
     * A local stream seeks; a remote one may not, and is read forward instead.
     * That costs the skipped bytes in transfer from the provider on every
     * seek, which is why `auto` redirects remote disks instead of streaming.
     *
     * @param  resource  $handle
     */
    private function seek($handle, int $offset, int $chunk): void
    {
        if ($offset === 0) {
            return;
        }

        $meta = stream_get_meta_data($handle);

        if ($meta['seekable'] && fseek($handle, $offset) === 0) {
            return;
        }

        $skip = $offset;

        while ($skip > 0 && ! feof($handle)) {
            $read = fread($handle, min($chunk, $skip));

            if ($read === false || $read === '') {
                return;
            }

            $skip -= strlen($read);
        }
    }
}
