<?php

namespace Goldnead\PrivateMedia\Http\Controllers;

use Goldnead\PrivateMedia\Contracts\MediaAccess;
use Goldnead\PrivateMedia\Delivery\ByteRange;
use Goldnead\PrivateMedia\Delivery\Deliverer;
use Goldnead\PrivateMedia\Delivery\MediaFile;
use Goldnead\PrivateMedia\Delivery\MediaSource;
use Goldnead\PrivateMedia\Events\MediaRefused;
use Goldnead\PrivateMedia\Events\MediaServed;
use Goldnead\PrivateMedia\PrivateMedia;
use Goldnead\PrivateMedia\Support\AuditTrail;
use Goldnead\PrivateMedia\Support\MediaPath;
use Goldnead\PrivateMedia\Support\Reason;
use Goldnead\PrivateMedia\Support\UserId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /{prefix}/{resource}/{path}?viewer=…&expires=…&signature=…
 *
 * The checks run in this order, and each refusal names itself:
 *
 * 1. signature   the URL is exactly what PrivateMedia::url() signed
 * 2. expired     and its deadline has not passed
 * 3. unauthenticated / wrong_user   the signed-in user is the one it was made for
 * 4. not_found   the path has a shape a file could have
 * 5. no_access   MediaAccess allows this user this file of this resource
 * 6. not_found / file_empty / storage_unavailable   the file is in the source
 * 7. range_not_satisfiable   the requested range lies inside it
 *
 * Access is asked before the file is looked up, so a user without access
 * cannot find out which files exist.
 */
class MediaController extends Controller
{
    public function __construct(
        private readonly MediaAccess $access,
        private readonly MediaSource $source,
        private readonly Deliverer $deliverer,
        private readonly AuditTrail $audit,
        private readonly PrivateMedia $links,
    ) {}

    public function show(Request $request, string $resource, string $path): StreamedResponse|RedirectResponse|JsonResponse
    {
        $user = $request->user(config('private-media.routes.guard'));

        if (! URL::hasCorrectSignature($request)) {
            return $this->refuse($request, $user, $resource, $path, Reason::Signature);
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->refuse($request, $user, $resource, $path, Reason::Expired);
        }

        $userId = UserId::tryOf($user);

        if ($userId === null) {
            return $this->refuse($request, $user, $resource, $path, Reason::Unauthenticated);
        }

        if (! hash_equals($this->links->viewerToken($userId), (string) $request->query('viewer', ''))) {
            return $this->refuse($request, $user, $resource, $path, Reason::WrongUser);
        }

        if (MediaPath::normalize($path) === null) {
            return $this->refuse($request, $user, $resource, $path, Reason::NotFound);
        }

        if (! $this->access->allows($user, $resource, $path)) {
            Log::info('private-media: no access', [
                'reason' => Reason::NoAccess->value,
                'user_id' => $userId,
                'resource' => $resource,
                'path' => $path,
            ]);

            return $this->refuse($request, $user, $resource, $path, Reason::NoAccess);
        }

        $file = $this->source->locate($path);

        if ($file instanceof Reason) {
            if ($file === Reason::FileEmpty) {
                Log::warning('private-media: file is empty', [
                    'reason' => $file->value,
                    'resource' => $resource,
                    'path' => $path,
                    'runbook' => 'The object exists but has zero bytes, most likely an aborted upload. Upload it again.',
                ]);
            }

            return $this->refuse($request, $user, $resource, $path, $file);
        }

        return $this->deliver($request, $user, $resource, $file);
    }

    private function deliver(Request $request, mixed $user, string $resource, MediaFile $file): StreamedResponse|RedirectResponse|JsonResponse
    {
        $mode = $this->deliverer->mode($file);

        if ($mode === Deliverer::REDIRECT) {
            $response = $this->deliverer->redirect($file);

            if ($response instanceof Reason) {
                return $this->refuse($request, $user, $resource, $file->path, $response);
            }

            $this->served($request, $user, $resource, $file->path, $mode, true);

            return $response;
        }

        $range = ByteRange::resolve($request->header('Range'), $file->size);

        if (! $range->isSatisfiable()) {
            return $this->refuse($request, $user, $resource, $file->path, Reason::RangeNotSatisfiable)
                ->header('Content-Range', 'bytes */'.$file->size);
        }

        $this->served($request, $user, $resource, $file->path, $mode, $range->isOpening());

        return $this->deliverer->stream($request, $file, $range);
    }

    private function served(Request $request, mixed $user, string $resource, string $path, string $mode, bool $opening): void
    {
        $this->audit->record($request, $user, $resource, $path, Reason::Served, $mode, $opening);

        $range = $request->header('Range');

        MediaServed::dispatch($user, $resource, $path, $mode, $opening, is_string($range) ? $range : null);
    }

    private function refuse(Request $request, mixed $user, string $resource, string $path, Reason $reason): JsonResponse
    {
        $this->audit->record($request, $user, $resource, $path, $reason);

        MediaRefused::dispatch($user, $resource, $path, $reason);

        return new JsonResponse(
            ['error' => ['code' => $reason->value, 'message' => $reason->message()]],
            $reason->status(),
            ['Cache-Control' => 'private, no-store'],
        );
    }
}
