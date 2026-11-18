<p>
<a href="https://leverprice.com">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/leverprice/.github/main/assets/repo/php-dark.svg">
    <img alt="Leverprice SDK for PHP" src="https://raw.githubusercontent.com/leverprice/.github/main/assets/repo/php-light.svg" width="100%">
  </picture>
</a>
</p>

<p>
  <a href="https://packagist.org/packages/leverprice/leverprice-php"><img alt="Packagist" src="https://img.shields.io/packagist/v/leverprice/leverprice-php?label=packagist&style=flat-square&labelColor=0F1011&color=3C3F44"></a>
  <a href="https://github.com/leverprice/leverprice-php/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/leverprice/leverprice-php/ci.yml?branch=main&label=ci&style=flat-square&labelColor=0F1011"></a>
  <a href="https://github.com/leverprice/leverprice-php/blob/main/LICENSE"><img alt="MIT license" src="https://img.shields.io/badge/license-MIT-3C3F44?style=flat-square&labelColor=0F1011"></a>
</p>

Server-side SDK for [Leverprice](https://leverprice.com) price experiments. PHP 8.1+ (64-bit), no runtime dependencies beyond `ext-json`. Works with Laravel, Symfony, any PSR-7 framework and plain PHP, under PHP-FPM and in worker mode (Octane, FrankenPHP, RoadRunner, Swoole).

Your payments stay yours. The SDK hands your server a number; your code creates the checkout with your own provider keys. Leverprice never sees card data or provider API keys.

```bash
composer require leverprice/leverprice-php
```

## Quick start

```php
use Leverprice\Leverprice;

$leverprice = new Leverprice([
    'secretKey' => getenv('LEVERPRICE_SECRET_KEY'), // server-only
    'products' => [
        'pro' => ['currency' => 'usd', 'fallbackPrice' => 1900], // your current price, served whenever Leverprice cannot answer
    ],
]);

// 1. Where the price is shown. Without a `request`, the SDK reads $_SERVER and $_COOKIE.
$quote = $leverprice->getPrice('pro', ['userId' => $user?->id]);
$quote->applyCookies(); // header('Set-Cookie: …') — call before any output
echo number_format($quote->price / 100, 2); // minor units

// 2. Where you create the checkout. Never trust a price sent by the browser.
$q = $leverprice->getPrice('pro', ['userId' => $user?->id]);
$session = $stripe->checkout->sessions->create([
    'mode' => 'payment',
    'line_items' => [[
        'quantity' => 1,
        'price_data' => ['currency' => $q->currency, 'unit_amount' => $q->price, 'product_data' => ['name' => 'Pro']],
    ]],
    'metadata' => $q->metadata, // signed attribution: pass it whole
    'payment_intent_data' => ['metadata' => $q->metadata],
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
]);
$q->applyCookies();

// 3. In your webhook handler (or connect your provider's webhooks to Leverprice directly).
//    A session paid with a delayed method completes 'unpaid'; async_payment_succeeded reports it later.
$payload = file_get_contents('php://input'); // the raw body, exactly as received
$event = \Stripe\Webhook::constructEvent($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $endpointSecret);
$paid = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];
$session = $event->data->object;
if (in_array($event->type, $paid, true) && $session->payment_status === 'paid'
    && isset($session->payment_intent) && $session->amount_total > 0) { // $0: a trial start, a 100% discount
    $leverprice->trackPurchase([
        'provider' => 'stripe',
        'orderId' => $session->payment_intent,
        'amount' => $session->amount_total - ($session->total_details->amount_tax ?? 0),
        'currency' => $session->currency,
        'metadata' => $session->metadata,
        'timestamp' => $event->created, // Unix seconds are converted
    ]);
}
```

`constructEvent()` throws `\Stripe\Exception\SignatureVerificationException` for a bad signature and `\UnexpectedValueException` for a body that is not JSON; answer both with HTTP 400.

A subscription session names the PaymentIntent of its first payment from Stripe API version 2025-03-31.basil on. On an older version, report that payment from `invoice.paid` with `billing_reason` `subscription_create`: the order id is the invoice's `payment_intent` and the attribution is in `subscription_details.metadata`, so pass `'subscription_data' => ['metadata' => $q->metadata]` at checkout as well.

That is all the configuration your code holds. Prices, price ranges, tests, regional prices and where the visitor's country comes from are managed in Leverprice and arrive in a signed config, so new features and new settings never need a code change.

### Laravel

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    $this->app->singleton(Leverprice::class, fn () => new Leverprice([
        'secretKey' => config('services.leverprice.secret'),
        'products' => config('services.leverprice.products'),
        'cache' => cache()->store(), // optional: any PSR-16 cache
    ]));
}
```

Laravel encrypts cookies set on its responses. The Leverprice cookies are signed already and must reach the browser unchanged:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->encryptCookies(except: ['__pp', '__pp_f']);
})
```

```php
public function pricing(Request $request, Leverprice $leverprice)
{
    $quote = $leverprice->getPrice('pro', ['request' => $request, 'userId' => $request->user()?->id]);
    $response = response()->view('pricing', ['quote' => $quote]);
    foreach ($quote->setCookie as $cookie) {
        $response->headers->set('Set-Cookie', $cookie, false);
    }

    return $response; // or: return $quote->applyCookiesTo($response);
}
```

`$response->withHeaders(['Set-Cookie' => $quote->setCookie])` also works, but it replaces any `Set-Cookie` headers already on the response. The checkout and webhook code is the same as above. With Octane, call `terminate()` after each request:

```php
Event::listen(\Laravel\Octane\Events\RequestTerminated::class, fn () => app(Leverprice::class)->terminate());
```

### Symfony

```yaml
# config/services.yaml
services:
    Leverprice\Leverprice:
        arguments:
            $options:
                secretKey: '%env(LEVERPRICE_SECRET_KEY)%'
                products:
                    pro: { currency: usd, fallbackPrice: 1900 }
```

```php
#[Route('/pricing')]
public function pricing(Request $request, Leverprice $leverprice): Response
{
    $quote = $leverprice->getPrice('pro', ['request' => $request, 'userId' => $this->getUser()?->getUserIdentifier()]);

    return $quote->applyCookiesTo($this->render('pricing.html.twig', ['quote' => $quote]));
}
```

In worker mode (FrankenPHP, RoadRunner), call `$leverprice->terminate()` from a `kernel.terminate` listener.

### PSR-7 (Slim, Mezzio, …)

```php
$quote = $leverprice->getPrice('pro', ['request' => $request]);
foreach ($quote->setCookie as $cookie) {
    $response = $response->withAddedHeader('Set-Cookie', $cookie);
}
```

## Guarantees

- **Fails safe.** If Leverprice is off, slow (no config within 500 ms), down, misconfigured or returns anything unexpected, `getPrice()` returns your `fallbackPrice`. It never throws for those reasons; only for a product key missing from `products`.
- **Verified config.** Root key → keyset → config signature chain (Ed25519), version-monotonic (no rollback, no revoked keys), time-limited.
- **Bounded.** Every standard and test price is checked against the product's signed price range and currency; regional prices follow from them, and every country pays between 5% and 200% of the standard price.
- **Sticky and tamper-proof.** Visitors keep their price. The assignment cookie is HMAC-signed with your project's assignment key, so rotating your API key does not reshuffle visitors.
- **Private.** Visitor and user ids are HMAC-hashed on your server; Leverprice only sees the hash.
- **No price flips.** A visitor who got the fallback during an outage keeps it for the rest of their session.
- **Search engines see the standard price.** Crawlers and link previews are never put into experiments.
- **Never in your way.** Events are sent after the response (`fastcgi_finish_request()`), and tracking calls never throw.

## Requests and cookies

`request` accepts a Laravel or Symfony `Request`, a PSR-7 `ServerRequestInterface`, or a plain header array (`['cookie' => …, 'user-agent' => …]`, any case, `HTTP_*` keys included). Without one, the SDK reads `$_SERVER` and `$_COOKIE`. `cookie` and `userAgent` in the context override what is read from the request.

`$quote->setCookie` is a list of `Set-Cookie` header values: usually empty, one value when the assignment cookie (`__pp`) changes, or the outage pin (`__pp_f`). Send every value with the response.

Pricing several products on one page? Pass the same request object to each `getPrice()` call (or no request at all): the quotes share one visitor cookie, and the last quote's cookie carries every assignment.

A visitor who refuses cookies still keeps one price and counts once: without a cookie, the SDK keys the visitor by an HMAC of their IP address (an IPv6 address by its /64 network), browser and languages, made with your project's secret on your server. Neither the address nor the browser leaves your server. The address comes from `CF-Connecting-IP`, `True-Client-IP`, `Fastly-Client-IP`, `Fly-Client-IP`, `X-Real-IP`, `X-Forwarded-For` or the connection (`REMOTE_ADDR`).

## Regional prices

Turn on regional prices for a product in the dashboard and a visitor from India pays $12, or ₹999 in rupees, where a visitor from the US pays $29. Your code stays as it is: in Leverprice, choose where your site runs under **Project settings → Visitor country** (Cloudflare, Vercel, AWS CloudFront, or the header your own proxy sets), and the SDK reads the visitor's country from that header.

```php
$quote = $leverprice->getPrice('pro', ['request' => $request]); // a visitor from India
$quote->price;    // 1200: India is set to $12 in the dashboard
$quote->currency; // 'usd': pass both to your checkout
$quote->country;  // 'IN'
$quote->regional; // true
```

- **The country.** The header is read from the request you pass, or from `$_SERVER` without one. Name only a header your host or CDN sets and overwrites: a header a visitor can send themselves would let them pick their own price. Headers every browser sends (`Accept-Language`, `X-Forwarded-For`, `User-Agent`…) are never read as the country: as `'countryHeader'` they are ignored and reported to `onError`. In `$_SERVER`, `CF_IPCountry` and `CF-IPCountry` are the same key, so keep header names with underscores dropped in front of PHP (the nginx default). To decide in code instead, pass `'countryHeader'` to the constructor (it replaces the project setting) or `'country'` to `getPrice()` (it wins over both).
- **VPNs.** A visitor keeps the price of a dearer country they were seen in for 7 days, so a VPN to a cheaper country doesn't lower it: someone who saw $29 in the US still sees $29 with a VPN to India. The country they hold is `$quote->country` and travels in the signed `__pp` cookie, which a regional product sets even without a test.
- **Local currencies.** Regional prices in your product's currency ($12 in India instead of $29) need nothing in code. Prices in the visitor's own currency (₹999) are served only with `'localCurrencies' => true`, which you set when your checkout charges `$quote->price` in `$quote->currency`; without it those visitors get the standard price, so a checkout with a fixed currency can never charge 99900 in dollars.
- **Tests.** Each visitor still gets one sticky variant, at that variant's price for their country. A test can run in selected countries only, and countries can get their own share of traffic per price: both are set in the dashboard and need nothing from your code but the visitor's country.
- **Checkout.** Always pass `$quote->currency` with `$quote->price`, also before you turn regional prices on: then they need no code change later. `checkoutUrl` is `null` for a regional price: hosted checkout links are made for standard prices.
- Bots and visitors whose country is unknown get the standard price.

## Exposures

An exposure is one person seeing an experiment price. With `'exposure' => 'auto'` (default) the SDK logs it the first time a visitor gets a price from an experiment, once per visitor and experiment (remembered in the cookie; the server deduplicates too). Prefetch and prerender requests (`Sec-Purpose`, `Purpose`, `X-Purpose`, `X-Moz`, `Next-Router-Prefetch`, and Turbo's `X-Sec-Purpose`) are priced but not counted.

If you compute the price before it is visible (a hidden tab, a modal), use manual mode:

```php
$quote = $leverprice->getPrice('pro', ['request' => $request, 'exposure' => 'manual']);
// …later, when the price is actually on screen:
$leverprice->logExposure($quote);
```

## Bots

Crawlers, link previewers, headless browsers, uptime monitors and command-line clients get the product's standard price (or your fallback when no config is available) with reason `bot`: no assignment, no cookie, no exposure. An empty user agent counts as a person. Bring your own detection with `['bot' => true]` or `['bot' => false]`; the built-in check is `Leverprice\Bots::isBot($userAgent)`.

## Kill switch

Set `LEVERPRICE_DISABLED=1` (or `true`) in the environment (`getenv()`, `$_ENV` or `$_SERVER`), or pass `'disabled' => true`. It is checked on every call: `getPrice()` returns your fallback price with reason `disabled`, sets no cookies, makes no network calls and logs nothing; tracking and exposure calls do nothing. A missing or malformed `secretKey` behaves the same way (a malformed key is reported once through `onError`), as does a PHP build without Ed25519 (install `ext-sodium` or `paragonie/sodium_compat`).

## Outages

When no usable config is available (reason `no_config`), the quote also sets `__pp_f=1` for 30 minutes. While a browser sends it, it gets the fallback price with reason `pinned_fallback` and is not enrolled; each pinned request extends it, so the price changes only after 30 minutes of inactivity. The cookie is unsigned on purpose: it can only select your own fallback price.

Failed config fetches back off (up to 30 s) for every PHP worker sharing the cache, and only one worker fetches at a time, so an outage does not multiply requests or slow your pages beyond `timeoutMs`.

Purchases and refunds survive an outage: when a send still fails after its retries, the events go to an outbox in the shared cache (up to 1000 events, kept for 60 days). The next request that flushes events sends them first; a lease ensures only one worker sends the outbox at a time, and the server deduplicates by order and refund id. While the API keeps failing, requests skip the outbox instead of waiting on it, and exposures are dropped.

## Caching between requests

PHP starts every request from scratch, so the SDK keeps the verified config, its ETag, the newest keyset version, the backoff state and the event outbox in a cache shared by all workers:

| `cache` option | Store |
|---|---|
| not set | APCu when enabled, else a file in `sys_get_temp_dir()` (mode 0600, replaced atomically) |
| any PSR-16 cache | Redis, Memcached, your framework's cache |
| `new Leverprice\State\FileStore('/var/cache/leverprice')` | a directory of your choice |
| `false` | this process only |

Entries are keyed by your key's fingerprint and the API URL and protected by an HMAC derived from your secret key. Every load re-verifies the full signature chain, so a tampered cache can never change a price.

Revalidation (every 30 s, with ETag) and queued exposures run after the response has been sent: at shutdown, after `fastcgi_finish_request()` (disable with `'finishRequest' => false` if later shutdown functions must still write output). In worker mode there is no per-request shutdown, so call `$leverprice->terminate()` when each request ends; `preload()` warms the cache at worker start. `trackPurchase()` and `trackRefund()` always send immediately.

## Trust chain and updates

The SDK embeds only Leverprice's offline root public keys (`Leverprice::ROOT_PUBLIC_KEYS`). Each config carries a keyset of online signing keys signed by a root key, and must be signed by one of those keys. Keyset versions only move forward, across requests too (a revoked key cannot come back with an older keyset, and a config it signed stops being trusted); `Leverprice::MIN_KEYSET_VERSION` sets the floor for a fresh cache. Config versions only move forward, and configs expire. For local development pass `'trustedRootKeys' => ['r1' => '<your dev root public key>']`.

When any Leverprice response announces a newer version in `Leverprice-Config-Version`, the SDK fetches it right away.

## Reference

`getPrice(string $productKey, array $context = []): Quote` — context keys: `request`, `cookie`, `userAgent`, `userId` (string, int or `Stringable`), `exposure` (`'auto'` / `'manual'`), `bot`, `country`. Throws `InvalidArgumentException` only if `$productKey` is missing from `products`.

| `Quote` property | |
|---|---|
| `productKey` | The key you asked for |
| `price` | Minor units; use it for display and as the checkout amount |
| `currency` | Lowercase ISO 4217: your product's currency, or the region's for a regional price |
| `country` | The country whose price applies (ISO 3166-1 alpha-2): the visitor's, or a dearer one they hold (see VPNs); `null` when unknown |
| `regional` | `true` when `price` is the regional price of `country` |
| `isFallback` | `true` when `price` is your `fallbackPrice` |
| `reason` | See below (`Quote::REASON_*` constants) |
| `experimentId`, `variantId` | Set when `reason` is `experiment`, else `null` |
| `visitorId` | Hashed visitor id Leverprice sees; `null` unless `reason` is `experiment` |
| `metadata` | `['pp_exp', 'pp_var', 'pp_vid', 'pp_sig']` for the checkout (signed); `[]` otherwise |
| `setCookie` | `Set-Cookie` values to send (zero or more) |
| `checkoutUrl` | No-code mode: hosted checkout link for this price, or `null` (always `null` for a regional price) |
| `exposureLogged` | An exposure for this visitor and experiment has been logged (now or earlier) |

| `reason` | Price |
|---|---|
| `experiment` | The visitor's sticky variant |
| `base_price` | The product's price for this visitor (no experiment runs for them) |
| `bot` | Standard price, or fallback if unavailable; never enrolled |
| `disabled` | Fallback: kill switch, no valid secret key, or no Ed25519 |
| `no_config` | Fallback: no usable signed config (timeout, outage, rejected or expired); sets the pin |
| `pinned_fallback` | Fallback: this browser got a fallback during an outage in this session |
| `unknown_product` | Fallback: the config has no such product |
| `currency_mismatch` | Fallback: the config's currency differs from your guard |
| `out_of_bounds` | Fallback: a price is outside the product's signed range |

| Method | |
|---|---|
| `logExposure(Quote $quote): bool` | Logs a manual-mode exposure; `true` if one was queued |
| `trackPurchase(['provider', 'orderId', 'amount', 'currency', 'metadata', 'timestamp'?])` | Reads `pp_exp`/`pp_var`/`pp_vid`/`pp_sig` from `metadata` (array, `ArrayAccess` or object). Providers: `stripe`, `polar`, `lemonsqueezy`, `dodo`, `paddle`, `creem`, `other` |
| `trackRefund(['provider', 'orderId', 'currency', 'refundId', 'amount'] \| [… 'refundedTotal'])` | One refund by id, or the order's cumulative refunded total |
| `flush()` | Sends queued events and the outbox now (≤ 500 per request; undelivered money events go to the outbox) |
| `refresh()` / `preload()` | Fetch the config now / only if none is usable |
| `terminate()` | Finishes background work; automatic at shutdown, call it per request in worker mode |
| `Quote::applyCookies()` / `Quote::applyCookiesTo($response)` | Send the cookies with `header()` / add them to a Symfony, Laravel or PSR-7 response |

Timestamps are milliseconds since the epoch; Unix seconds and `DateTimeInterface` are converted. None of these methods throws.

| Option | Default | |
|---|---|---|
| `secretKey` | — | `pp_sk_live_…` / `pp_sk_test_…` |
| `products` | — | `key => ['currency', 'fallbackPrice']`. `minPrice` and `maxPrice` are deprecated (ignored since 0.4: the price range is set in Leverprice) |
| `disabled` | `false` | Kill switch |
| `apiUrl` | `https://api.leverprice.com` | |
| `timeoutMs` | `500` | Longest a request waits for a config when none is cached |
| `refreshIntervalMs` | `30000` | Revalidation period |
| `maxStaleMs` | `86400000` | Stop using a config this long after the server last confirmed it |
| `cookieName` | `__pp` | The outage pin is `<cookieName>_f` |
| `cookieMaxAgeSeconds` | `15552000` | 180 days |
| `cookieSecure` | `true` | Set `false` only for plain-HTTP development |
| `cookieDomain` | none | |
| `countryHeader` | none | Request header your CDN or host sets to the visitor's country; overrides **Project settings → Visitor country**, see [Regional prices](#regional-prices) |
| `localCurrencies` | `false` | Serve regional prices in the visitor's currency, not only in each product's own; set it only when your checkout charges `$quote->price` in `$quote->currency` |
| `flushIntervalMs` | `2000` | Longest exposures wait in a long-running worker |
| `trustedRootKeys` | `ROOT_PUBLIC_KEYS` | For local development |
| `cache` | APCu or temp file | See [Caching between requests](#caching-between-requests) |
| `transport` | curl, else PHP streams | A `Leverprice\Http\Transport` |
| `httpClient`, `requestFactory`, `streamFactory` | — | Any PSR-18 client with PSR-17 factories |
| `onError` | `error_log()` | `callable(Throwable): void`, receives every SDK error |
| `logger` | — | A PSR-3 logger, used when `onError` is not set |
| `clock` | system clock | A `Leverprice\Clock` (tests) |
| `finishRequest` | `true` | Call `fastcgi_finish_request()` before shutdown work |
| `registerShutdown` | `true` | Run `terminate()` automatically at shutdown |

**Upgrading from 0.3:** no code changes. `minPrice` and `maxPrice` in your code are no longer used: the product's range set in Leverprice applies, and you can delete them whenever you like. If the limits in your code were tighter than that range, tighten the range in Leverprice before you upgrade. Regional prices need no code: turn them on in the dashboard and set **Project settings → Visitor country**. Add `'localCurrencies' => true` if your checkout passes `$quote->currency` and you want prices in visitors' own currencies.

## License

MIT
