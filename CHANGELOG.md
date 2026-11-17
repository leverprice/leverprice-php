# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [0.3.0] - 2026-11-17

First release of the PHP SDK, implementing Leverprice server SDK protocol v2.

### Added

- `Leverprice::getPrice()` with signed sticky assignment, outage pinning, bot detection, prefetch detection and local price guards.
- Config trust chain (root key → keyset → config, Ed25519 via ext-sodium or paragonie/sodium_compat) with version monotonicity, revocation and expiry.
- State shared between requests through APCu, a temp file or any PSR-16 cache, re-verified on every load.
- Event tracking: `logExposure()`, `trackPurchase()`, `trackRefund()` and `flush()`, sent after the response at shutdown.
- Outbox for purchases and refunds that could not be delivered: kept in the shared cache (HMAC-protected, at most 1000 events, 60 days) and sent by the next request, one worker at a time.
- `refresh()`, `preload()` and `terminate()` for long-running workers.
- Request reading for Laravel, Symfony, PSR-7, header arrays and superglobals; `Quote::applyCookies()` and `Quote::applyCookiesTo()`.
- curl, PHP stream and PSR-18 transports.
- Conformance with the shared vectors in `conformance/vectors.json`.

[0.3.0]: https://github.com/leverprice/leverprice-php/releases/tag/v0.3.0
