# Changelog

## Unreleased

### Fixed

- A failing audit write (missing table, database down) no longer turns a delivered file or a refusal into a 500. The failure is logged as an error with a runbook line.
- `private-media:prune` fails on a non-numeric `--days` instead of silently using the configured retention, and no longer loops forever when a delete removes no rows. It now exits with a failure and says so when rows older than the retention could not be deleted.

## 0.1.2 (2026-11-16)

### Changed

- The package download no longer contains `art/` and `MARKETPLACE.md` (`export-ignore`). No code change, no migration, no new config key.

## 0.1.1 (2026-09-22)

### Fixed
- The service provider no longer overrides Statamic's `bootRoutes()`, `bootCommands()` and
  `bootPublishables()`. `AddonServiceProvider::boot()` calls those itself before `bootAddon()`,
  so the delivery route, the prune command and the publishables were registered twice.

## 0.1.0 (2026-09-22)

First release, extracted from adriangoldner.com.

### Added
- Signed links bound to the viewer: `{{ private_media:url }}` and `PrivateMedia::url()`.
- Delivery route (off by default, configurable prefix and middleware) with signature, expiry,
  user, path and access checks, each refusal named by a code.
- Streaming with single byte ranges (206, suffix ranges, 416), or a redirect to a temporary URL.
- Path fences: shape check, lookup in the configured asset container, realpath under the disk root.
- `MediaAccess` contract; statamic-entitlements as default, closed without it.
- Audit table `private_media_access_log`, `private-media:prune`.
- Events `MediaServed` and `MediaRefused`.
- Route throttle (`routes.throttle`, default `300,1`); anonymous refusals go to the log, not the table.
- Active content (HTML, SVG, XML, JS …) as attachment; every stream with `Content-Security-Policy: sandbox`;
  UTF-8 file names with an ASCII fallback.
- A file that cannot be opened is `storage_unavailable` (503), not a truncated 200.
- Refusal JSON keeps umlauts and slashes unescaped, readable when opened in a tab.
