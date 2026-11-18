# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [0.4.0] - 2026-11-18

Cookies, cached state and the wire protocol are compatible with 0.3.

### Changed

- Limits set in code (`minPrice`, `maxPrice`) no longer hold prices: the product's range set in Leverprice applies. If your limits were tighter than that range, tighten it in the dashboard before you upgrade.

### Added

- Settings live in Leverprice, not in code: the product's price range and the header that carries the visitor's country (Project settings → Visitor country) arrive in the signed config. `minPrice` and `maxPrice` in code are no longer used; 0.3 code that sets them keeps compiling and running.
- Regional prices: a product can cost something else in each country, in your currency or the visitor's. `country` in the `getPrice()` context, `country` / `regional` on the quote, and a `countryHeader` option that overrides the project setting. Prices in the visitor's currency need `'localCurrencies' => true` (set it when your checkout charges `$quote->currency`); `$quote->currency` is then the region's currency.
- Exposures carry the visitor's country when it is known.
- Tests by country: a test can run in selected countries only, and countries can be split between the prices their own way. Both are set in the dashboard; the SDK only needs the visitor's country.
- `trackPurchase()` and `trackRefund()` accept the providers `paddle` and `creem`.

### Fixed

- Turbo 8 hover prefetches (`X-Sec-Purpose: prefetch`) are priced but no longer counted as exposures, like other prefetches.
- README: the Stripe webhook counts only paid Checkout Sessions, handles `checkout.session.async_payment_succeeded`, reports a subscription's first payment by its PaymentIntent id (as verified webhooks do) and skips $0 sessions and passes the event time.

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

[0.4.0]: https://github.com/leverprice/leverprice-php/releases/tag/v0.4.0
[0.3.0]: https://github.com/leverprice/leverprice-php/releases/tag/v0.3.0
