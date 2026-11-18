# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [0.4.1] - 2026-11-18

- VPN guard: a visitor keeps the price of a country that pays more, where they were seen in the last 7 days, so a VPN to a cheaper country no longer lowers the price. A visitor who moves to a dearer country pays its price from then on. The held country is `$quote->country` and lives in the signed `__pp` cookie, which a regional product now sets even without a test. Turned on by the signed config; older SDKs ignore it.
- A visitor who refuses cookies keeps one price and counts once: without a cookie, the visitor is keyed by an HMAC of their IP address (IPv6 by its /64), browser and languages, made with the project's secret. Neither leaves your server.
- A `countryHeader` option naming a header every browser sends (`Accept-Language`, `X-Forwarded-For`…) is ignored and reported to `onError`, and such a header in the project settings is never read: visitors can no longer pick their own country.
- Every config request says which products your code prices (`Leverprice-Products`) and why the last config was refused, if it was (`Leverprice-Config-Error`, kept in the shared cache so the next request reports it), so the dashboard's Setup page turns green only when visitors get real prices and otherwise says what to fix.
- A cold start no longer gives up early: each request waits its own `timeoutMs` for the first config, also while another PHP worker holds the shared fetch. Before, a worker that arrived 400 ms after another one started fetching waited only 100 ms, served the fallback, said nothing, and pinned the visitor to it for 30 minutes. A request that does give up now reports `Leverprice config: still loading after 500 ms (timeoutMs); …` to `onError`, once per fetch and process.

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
