<?php

namespace Goldnead\PrivateMedia\Support;

use Goldnead\PrivateMedia\Models\AccessLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the audit row, allowed and refused alike.
 *
 * Refusals always count: a pile of `no_access` is either somebody probing or
 * a checkout whose grant did not arrive, and without the row neither shows.
 * A served range that does not start at byte 0 is the same playback seeking,
 * and is left out unless `audit.log_ranges` is on.
 */
class AuditTrail
{
    public function record(
        Request $request,
        mixed $user,
        string $resource,
        ?string $path,
        Reason $reason,
        ?string $delivery = null,
        bool $opening = true,
    ): void {
        if (! config('private-media.audit.enabled', true)) {
            return;
        }

        if ($reason === Reason::Served && ! $opening && ! config('private-media.audit.log_ranges', false)) {
            return;
        }

        $range = $request->header('Range');
        $agent = $request->userAgent();

        try {
            AccessLog::query()->create([
                'user_id' => UserId::tryOf($user),
                'resource' => mb_substr($resource, 0, 191),
                'path' => $path === null ? null : mb_substr($path, 0, 1024),
                'allowed' => $reason === Reason::Served,
                'reason' => $reason->value,
                'delivery' => $delivery,
                'range' => is_string($range) ? mb_substr($range, 0, 100) : null,
                'ip_address' => $request->ip(),
                'user_agent' => is_string($agent) ? mb_substr($agent, 0, 512) : null,
            ]);
        } catch (Throwable $e) {
            // The access decision has been made; a broken audit table must not
            // turn a delivered video into a 500 or a 403 into a 500. The gap in
            // the trail is loud in the log instead.
            Log::error('private-media: audit row could not be written', [
                'reason' => $reason->value,
                'resource' => mb_substr($resource, 0, 191),
                'path' => $path,
                'exception' => $e,
                'runbook' => 'Run the package migrations (php artisan migrate) and check the private_media_access_log table and database connection.',
            ]);
        }
    }
}
