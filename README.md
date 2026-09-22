# Statamic Private Media

Private media for Statamic 6: files in an asset container that only the right user can open.
A template asks for a link, the link is signed, expires, and works only for the user it was made
for. The route checks signature, user and access, then streams the file with byte ranges (so a
video can seek) or redirects to a temporary URL of the storage provider. Every refusal and every
opening is written to an audit table.

Who may open what is asked of [statamic-entitlements](https://github.com/goldnead/statamic-entitlements)
by default, or of your own `MediaAccess` binding.

Internal package, MIT. No Control Panel screen: there is nothing to configure per entry, and the
audit table is meant for queries and reports, not browsing.

## Requirements

- PHP 8.2+, Laravel 12.40+ or 13, Statamic 6
- Optional: goldnead/statamic-entitlements 1.3+ (without it, or your own `MediaAccess`, every request is refused)

## Install

```bash
composer require goldnead/statamic-private-media
php artisan migrate
```

Then, in `.env`:

```
PRIVATE_MEDIA_ROUTES_ENABLED=true
PRIVATE_MEDIA_CONTAINER=private
```

The container must sit on a disk **without a public URL** (for example a local disk under
`storage/app/private`, or a bucket without public read). A public container hands the file to
anyone who guesses the path, and this addon never sees the request.

## Links

```antlers
<video src="{{ private_media:url resource="cvt-101" asset="videos/intro.mp4" }}" controls></video>

{{# an assets field works too #}}
<video src="{{ private_media:url resource="cvt-101" :asset="lesson_video" }}" controls></video>
```

| Parameter | |
|---|---|
| `resource` | required; the slug of what access is granted to (with entitlements: the product slug) |
| `asset` | a path in the container, a `container::path` id, or an asset from an assets field |
| `ttl` | minutes the link stays valid; default `links.ttl` (240) |

The tag renders nothing for a guest, with the route off, or for an asset from another container.
From PHP: `PrivateMedia::url($user, 'cvt-101', 'videos/intro.mp4', ttlMinutes: 30)`, `null` in the
same cases.

A link is bound to its user by an HMAC token (the id itself is not in the URL). Forwarded, it is
refused as `wrong_user`; expired, as `expired`. Pick a `ttl` longer than your longest video: a
player that seeks after expiry is refused, and a page reload makes a new link.

## The route

`GET /{prefix}/{resource}/{path}`, prefix `private-media` by default
(`PRIVATE_MEDIA_ROUTE_PREFIX`), middleware `web` (`routes.middleware`). Checks, in order:

| Code | Status | When |
|---|---|---|
| `signature` | 403 | the URL is not exactly what was signed (edited path, resource, expiry, token) |
| `expired` | 403 | the signature matches, the deadline has passed |
| `unauthenticated` | 401 | nobody is signed in |
| `wrong_user` | 403 | somebody else is signed in |
| `not_found` | 404 | the path could never be a file (`..`, absolute, scheme, backslash, control characters) |
| `no_access` | 403 | `MediaAccess` said no |
| `not_found` | 404 | no such file in the container, or a symlink out of the disk's root |
| `file_empty` | 404 | the file has zero bytes (an aborted upload) |
| `storage_unavailable` | 503 | the storage did not answer, or the container is missing |
| `range_not_satisfiable` | 416 | the range starts at or past the end |

Access is asked before the file is looked up, so a user without access cannot probe which files
exist. Refusals are JSON: `{"error": {"code": "no_access", "message": "…"}}`, the message in the
site's language (en, de).

### Delivery

`delivery.strategy`:

- `auto` (default): local disks are streamed by this app; disks that make temporary URLs (S3 and
  compatible) get a 302 to one, valid `delivery.temporary_url_ttl` minutes (60); anything else is
  streamed.
- `stream`: always through this app.
- `redirect`: always a temporary URL; a disk that cannot make one is refused with `storage_unavailable`.

Byte ranges, when streaming:

| `Range` | Answer |
|---|---|
| none, not `bytes=`, unparseable | 200, whole file |
| `bytes=0-3`, `bytes=6-` | 206 with `Content-Range` |
| `bytes=-500` (suffix) | 206, the last 500 bytes (the whole file if shorter) |
| end past the file | 206, clamped to the last byte |
| start after end (`bytes=5-2`) | 200, whole file (invalid spec is ignored, RFC 9110) |
| start at or past the size, `bytes=-0` | 416 with `Content-Range: bytes */size` |
| several ranges (`bytes=0-1,4-5`) | 200, whole file (no multipart) |

Responses carry `Cache-Control: private, no-store`.

## Access

```php
use Goldnead\PrivateMedia\Contracts\MediaAccess;

$this->app->bind(MediaAccess::class, fn () => new class implements MediaAccess {
    public function allows(mixed $user, string $resource, string $path): bool
    {
        return $user !== null && str_starts_with($path, $resource.'/');
    }
});
```

The default (`EntitlementsMediaAccess`) asks entitlements whether the user holds the product
named by `resource`. It does not look at the path: if resources share a container and must not
see each other's files, bind your own. Users are resolved as in statamic-courses: Eloquent models
pass through, Statamic's eloquent user wrapper is unwrapped to its model, anything else is looked
up under `entitlements.subject_type`, or the auth model's morph class on an eloquent install, or
`user` on a flat-file one.

## Audit and events

Table `private_media_access_log` (`audit.table`): user id, resource, path, allowed, reason
(the codes above, or `served`), delivery (`stream`/`redirect`), range, IP, user agent, time.
A playback sends many range requests; only the one starting at byte 0 is written, unless
`audit.log_ranges` is on. Refusals are always written.

```php
Schedule::command('private-media:prune')->daily();   // older than audit.retention_days (90), or --days=
```

Events: `MediaServed` (user, resource, path, delivery, opening, range; fired on every served
request, count views on `opening`) and `MediaRefused` (user, resource, path, reason).

## Not included

Transcoding, HLS, DRM, and limits on concurrent devices. A signed link stops forwarding, not a
user who downloads the stream. See `docs/EXTRACTION.md` for open questions.

## Testing

```bash
composer test                                        # SQLite
DB_DRIVER=mysql DB_DATABASE=private_media_test composer test:mysql
composer analyse && composer lint
```
