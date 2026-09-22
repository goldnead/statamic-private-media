# Changelog

## Unreleased

First version, extracted from adriangoldner.com.

### Added
- Signed links bound to the viewer: `{{ private_media:url }}` and `PrivateMedia::url()`.
- Delivery route (off by default, configurable prefix and middleware) with signature, expiry,
  user, path and access checks, each refusal named by a code.
- Streaming with single byte ranges (206, suffix ranges, 416), or a redirect to a temporary URL.
- Path fences: shape check, lookup in the configured asset container, realpath under the disk root.
- `MediaAccess` contract; statamic-entitlements as default, closed without it.
- Audit table `private_media_access_log`, `private-media:prune`.
- Events `MediaServed` and `MediaRefused`.
