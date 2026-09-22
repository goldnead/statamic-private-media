# Extraction from adriangoldner.com

Source: `~/projects/adriangoldner.com`, branch `staging`, read on 2026-09-22. Nothing there was
changed. Model for the package layout: `statamic-courses` (same day, same way), conventions for
signed links from `statamic-lead-magnets` (`Services/DownloadLink.php`).

## What came from where

| Here | Source in adriangoldner.com | Change |
|---|---|---|
| `src/Delivery/ByteRange.php`, `Deliverer::stream()` | `app/Services/Media/PrivateVideoStreamService.php` (`stream`, `resolveByteRange`, `headersFor`) | Suffix ranges (`bytes=-500`) now mean the last n bytes; the source read them as `0-500`. A start at or past the size is 416 with `Content-Range: bytes */size`; the source answered 200. Multi-range stays 200. Reads through the disk's `readStream`, not an absolute path, so it works on any disk. `Cache-Control: private, no-store`, `nosniff`. HEAD sends no body. |
| `src/Http/Controllers/MediaController.php` | `app/Http/Controllers/Api/V1/AccountMediaController.php` (`show`) | Signature, expiry and user binding are new (the source relied on the session and Sanctum). Access asked through `MediaAccess` instead of `EntitlementService::canAccessResourceSlug`. |
| `Deliverer::redirect()` | same controller, `signiertAusliefern` (bucket: `exists()` → `size()` → `temporaryUrl()` → redirect) | TTL configurable (source: one hour). Disk from the container, not hardwired `hetzner`. |
| `MediaSource::locate()` | same controller: empty object check (`GRUND_DATEI_LEER`), narrow catch of `FilesystemException`/`AwsException` (`GRUND_SPEICHER_STUMM`) | Kept narrow for the same reason (a programming error must stay a 500). `AwsException` checked by name, since aws-sdk is not a dependency. |
| `Support/AuditTrail.php`, `Models/AccessLog.php`, migration | same controller, `schreibePruefspur` → `resource_download_audits` | Own table `private_media_access_log` (adg has `resource_download_audits`, no collision). Dropped the dead `resource_id`/`resource_item_id` columns. Range continuations are not logged by default. |
| `Support/Reason.php` | the controller's `NOT_ENTITLED` / `NOT_FOUND` codes and the runbook lines of `AccountResourceDownloadController` | Lowercase codes in the JSON (`no_access`, `not_found`, `file_empty`, `storage_unavailable`, …); runbook text stays in the log, not in the response. |
| `Access/EntitlementsMediaAccess.php` | none (copied from `statamic-courses` `EntitlementsCourseAccess`) | Resource slug = product slug. |
| `tests/Feature/StreamTest.php` | `tests/Feature/ProtectedMediaApiTest.php`: `test_entitled_user_can_stream_private_video_with_range_requests` | Circle import fixture replaced by a file on a fake disk. |
| `tests/Feature/RedirectTest.php` | same file: `test_base64url_video_asset_key_…`, `test_ein_leeres_objekt_wird_nicht_als_video_ausgeliefert` | The base64url key is gone (the link carries the path, signed), so the padding regression has no counterpart; the redirect and the empty-object refusal are ported. |
| `tests/Feature/AccessTest.php`, `AuditTest.php` | same file: `test_non_entitled_user_cannot_stream_private_video`, `test_abgelehnter_videozugriff_steht_in_der_pruefspur` | |

## New, not extracted

- Signed, viewer-bound links (`private_media:url`, `PrivateMedia::url()`), refusal codes
  `signature`, `expired`, `wrong_user`, `unauthenticated`.
- Path fences: `MediaPath` shape check, lookup in the configured container, realpath under the
  local disk root (symlinks out of the container).
- `range_not_satisfiable` (416), strategy `auto`/`stream`/`redirect`.
- Events `MediaServed`, `MediaRefused`; `private-media:prune`.

## Left out, and why

- **Legacy manifest lookup** (`MediaLocator::findByKey`, `absolutePathForManifestItem`, the
  Circle import media manifest, the local fallback when the bucket does not answer): specific to
  adg's Circle import. A site with such a layout binds its container to that folder.
- **base64url asset keys** (`videos::path` encoded in the URL): replaced by the signed path.
- **`resourceExistsInStatamic`** (lookup of `resource_slug` in `courses`, `libraries`,
  `resources`, `member_products`): adg's blueprints. An unknown resource is `no_access` here,
  since entitlements holds no grant for it.
- **`ActivityLedger::record('resource.downloaded')`**: adg's ledger. Listen to `MediaServed`.
- **Sanctum / API envelope** (`BaseApiController::error`): the route runs in `web` by default,
  the middleware is configurable.
- Brand scoping: not natural here; a resource slug is already unique per site.

## Open questions

1. **Concurrent devices.** The ticket's real question (the same person on four devices) is not
   answered. It needs a session or heartbeat table and a policy (kick oldest, refuse newest); the
   signed link does not help, since each device gets its own.
2. **Redirect leaves the fence.** After a 302 the provider URL is a bearer token for its TTL, as
   in adg. Streaming everything keeps the check per request but costs bandwidth on the app server.
   Which one for course videos?
3. **Link TTL vs. long videos.** 240 minutes default. A tab left open overnight gets `expired` on
   the next seek; a player-side refresh (fetch a new link on 403 `expired`) would fix it and
   belongs to the consumer (statamic-courses or adg's player).
4. **Path belongs to resource.** The entitlements default does not check that the file belongs to
   the resource; links are signed, so a user cannot swap it, but a template can. Should the default
   require `path` to lie under a folder named after the resource (config switch)?
5. **Switching adg over.** adg keeps `/api/account/media/{slug}/{key}` (Inertia player, Sanctum,
   base64 keys, Circle manifest). Moving it means: container on the `hetzner` disk, links from the
   player API via `PrivateMedia::url()`, legacy manifest items migrated or served by a custom
   `MediaAccess` + container. Not done here.
