<?php

declare(strict_types=1);

namespace Leverprice;

use Leverprice\Http\CurlTransport;
use Leverprice\Http\HttpRequest;
use Leverprice\Http\HttpResponse;
use Leverprice\Http\Psr18Transport;
use Leverprice\Http\StreamTransport;
use Leverprice\Http\Transport;
use Leverprice\Http\TransportException;
use Leverprice\Internal\Acceptor;
use Leverprice\Internal\AssignmentState;
use Leverprice\Internal\Config;
use Leverprice\Internal\ConfigError;
use Leverprice\Internal\Cookie;
use Leverprice\Internal\Ed25519;
use Leverprice\Internal\Experiment;
use Leverprice\Internal\Flight;
use Leverprice\Internal\Guard;
use Leverprice\Internal\Outbox;
use Leverprice\Internal\Product;
use Leverprice\Internal\Region;
use Leverprice\Internal\RequestInfo;
use Leverprice\Internal\RequestReader;
use Leverprice\Internal\RequestScope;
use Leverprice\Internal\SharedState;
use Leverprice\Internal\Superglobals;
use Leverprice\Internal\TrustChain;
use Leverprice\Internal\Util;
use Leverprice\Internal\Visitor;
use Leverprice\Internal\Wire;
use Leverprice\State\ApcuStore;
use Leverprice\State\FileStore;
use Leverprice\State\LockingStateStore;
use Leverprice\State\MemoryStore;
use Leverprice\State\Psr16Store;
use Leverprice\State\StateStore;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Server-side client for Leverprice price experiments.
 *
 * @phpstan-type GetPriceContext array{request?: mixed, cookie?: ?string, userAgent?: ?string, userId?: string|int|\Stringable|null, country?: ?string, exposure?: ?string, bot?: ?bool}
 * @phpstan-type WireEvent array<string, mixed>
 */
final class Leverprice
{
    public const SDK_VERSION = '0.4.1';
    public const ROOT_PUBLIC_KEYS = ['r1' => '5FmNzXvPMPZEuDnFT_fFd_VVNsUrWwWTKLerMWbSdcg'];
    public const MIN_KEYSET_VERSION = 1;

    private const OPTIONS = [
        'secretKey', 'products', 'disabled', 'apiUrl', 'timeoutMs', 'refreshIntervalMs', 'maxStaleMs',
        'cookieName', 'cookieMaxAgeSeconds', 'cookieSecure', 'cookieDomain', 'countryHeader', 'localCurrencies',
        'flushIntervalMs', 'trustedRootKeys', 'transport', 'httpClient', 'requestFactory', 'streamFactory', 'cache',
        'onError', 'logger', 'clock', 'finishRequest', 'registerShutdown',
    ];
    private const KEY_RE = '/^pp_sk_(live|test)_[0-9A-Za-z]{40}$/D';
    private const COOKIE_NAME_RE = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';
    private const HEADER_NAME_RE = self::COOKIE_NAME_RE;
    private const SIGNATURE_RE = '/^[0-9a-f]{16}$/D';
    private const PIN_MAX_AGE_SECONDS = 1800;
    private const REQUEST_TIMEOUT_MS = 10000;
    private const STALL_MS = self::REQUEST_TIMEOUT_MS + 5000;
    private const MAX_QUEUE = 5000;
    private const AUTO_FLUSH_AT = 100;
    private const SEND_ATTEMPTS = 3;
    private const MAX_BACKOFF_MS = 30000;
    private const CATCH_UP_GAP_MS = 5000;
    private const MAX_DEDUPE = 10000;
    private const POLL_MS = 20;
    private const RESYNC_MS = 100;

    /** @var array<int, self> */
    private static array $pending = [];
    private static bool $shutdownRegistered = false;
    private static bool $requestFinished = false;

    /** @var array<string, Guard> */
    private readonly array $guards;
    private readonly ?string $secretKey;
    private readonly bool $disabled;
    private readonly string $apiUrl;
    private readonly int $timeoutMs;
    private readonly int $refreshIntervalMs;
    private readonly int $maxStaleMs;
    private readonly string $cookieName;
    private readonly int $cookieMaxAge;
    private readonly bool $cookieSecure;
    private readonly ?string $cookieDomain;
    private readonly ?string $countryHeader;
    private readonly bool $localCurrencies;
    private readonly int $flushIntervalMs;
    private readonly Transport $transport;
    /** @var (\Closure(\Throwable): mixed)|null */
    private readonly ?\Closure $onError;
    private readonly ?LoggerInterface $logger;
    private readonly Clock $clock;
    private readonly bool $finishRequest;
    private readonly bool $registerShutdown;
    private readonly TrustChain $trust;
    private readonly Acceptor $acceptor;
    private readonly StateStore $store;
    private readonly string $storeKey;
    private readonly string $macKey;
    private readonly string $owner;
    private readonly Outbox $outbox;
    private readonly string $productsHeader;

    private ?string $envelope = null;
    private ?string $etag = null;
    private int $fetchedAt = 0;
    private ?Flight $flight = null;
    private int $failures = 0;
    private int $retryAt = 0;
    private int $serverVersion = 0;
    private ?string $configError = null;
    private int $configErrorAt = 0;
    private int $catchUpAt = 0;
    private int $leaseSince = 0;
    private string $leaseOwner = '';
    private int $slowLease = 0;
    private bool $loaded = false;
    private int $syncedAt = 0;
    private bool $storeErrorReported = false;
    private ?string $hmacSource = null;
    private string $hmacBytes = '';

    /** @var list<WireEvent> */
    private array $queue = [];
    private int $queuedSince = 0;
    /** @var array<string, true> */
    private array $exposed = [];
    private int $eventFailures = 0;
    private int $eventsRetryAt = 0;
    private int $outboxSize = 0;
    private bool $outboxTouched = false;
    /** @var array<string, true> */
    private array $outboxed = [];

    /** @var \WeakMap<object, RequestScope> */
    private \WeakMap $scopes;
    private ?RequestScope $superglobalScope = null;
    private string $superglobalScopeKey = '';

    /**
     * @param array<string, mixed> $options secretKey and products are required; see the README for the rest
     *
     * @throws \InvalidArgumentException for invalid options (a bug in the calling code)
     */
    public function __construct(array $options)
    {
        foreach (array_keys($options) as $name) {
            if (!in_array($name, self::OPTIONS, true)) {
                throw new \InvalidArgumentException(sprintf('Leverprice: unknown option "%s"', $name));
            }
        }
        $this->guards = self::guards($options['products'] ?? null);
        $this->disabled = ($options['disabled'] ?? false) === true;
        $this->apiUrl = rtrim(self::stringOption($options, 'apiUrl') ?? 'https://api.leverprice.com', '/');
        $this->timeoutMs = self::intOption($options, 'timeoutMs', 500);
        $this->refreshIntervalMs = self::intOption($options, 'refreshIntervalMs', 30000);
        $this->maxStaleMs = self::intOption($options, 'maxStaleMs', 86400000);
        $this->flushIntervalMs = self::intOption($options, 'flushIntervalMs', 2000);
        $this->cookieMaxAge = self::intOption($options, 'cookieMaxAgeSeconds', 15552000);
        $this->cookieName = self::stringOption($options, 'cookieName') ?? '__pp';
        if (preg_match(self::COOKIE_NAME_RE, $this->cookieName) !== 1) {
            throw new \InvalidArgumentException('Leverprice: cookieName is not a valid cookie name');
        }
        $this->cookieSecure = ($options['cookieSecure'] ?? true) !== false;
        $this->cookieDomain = self::stringOption($options, 'cookieDomain');
        $countryHeader = trim(self::stringOption($options, 'countryHeader') ?? '', " \t\n\x0B\f\r");
        if ($countryHeader !== '' && preg_match(self::HEADER_NAME_RE, $countryHeader) !== 1) {
            throw new \InvalidArgumentException('Leverprice: countryHeader is not a valid header name');
        }
        $named = $countryHeader === '' ? null : strtolower($countryHeader);
        // A header the visitor sends would let them pick their own price: the project's setting is used.
        $this->countryHeader = $named !== null && RequestReader::visitorSetHeader($named) ? null : $named;
        $this->localCurrencies = ($options['localCurrencies'] ?? false) === true;
        $this->finishRequest = ($options['finishRequest'] ?? true) !== false;
        $this->registerShutdown = ($options['registerShutdown'] ?? true) !== false;

        $onError = $options['onError'] ?? null;
        if ($onError !== null && !is_callable($onError)) {
            throw new \InvalidArgumentException('Leverprice: onError must be callable');
        }
        $this->onError = $onError === null ? null : \Closure::fromCallable($onError);
        $logger = $options['logger'] ?? null;
        if ($logger !== null && !$logger instanceof LoggerInterface) {
            throw new \InvalidArgumentException('Leverprice: logger must be a PSR-3 logger');
        }
        $this->logger = $logger;
        $clock = $options['clock'] ?? new SystemClock();
        if (!$clock instanceof Clock) {
            throw new \InvalidArgumentException('Leverprice: clock must implement Leverprice\Clock');
        }
        $this->clock = $clock;

        $rootKeys = self::rootKeys($options['trustedRootKeys'] ?? self::ROOT_PUBLIC_KEYS);
        $this->trust = new TrustChain($rootKeys, self::MIN_KEYSET_VERSION);
        $secretKey = $options['secretKey'] ?? null;
        $key = is_string($secretKey) ? Util::trim($secretKey) : '';
        $this->secretKey = preg_match(self::KEY_RE, $key) === 1 ? $key : null;
        $fingerprint = Acceptor::fingerprint($this->secretKey ?? '');
        $this->acceptor = new Acceptor($this->trust, $fingerprint);
        $this->transport = self::transport($options);
        $this->store = self::store($options['cache'] ?? null);
        $this->storeKey = 'leverprice.v1.' . $fingerprint . '.'
            . substr(hash('sha256', $this->apiUrl . "\n" . Util::jsonEncode($rootKeys)), 0, 16);
        $this->macKey = hash_hmac('sha256', 'leverprice:state:v1', $this->secretKey ?? '', true);
        $this->owner = Util::randomHex(8);
        $this->outbox = new Outbox($this->store, $this->storeKey . '.outbox', $this->macKey, $this->owner);
        $this->scopes = new \WeakMap();
        $this->productsHeader = self::productsHeader($this->guards);

        if (!$this->disabled && !self::envDisabled()) {
            if ($key !== '' && $this->secretKey === null) {
                $this->emit(new LeverpriceException(
                    'Leverprice: secretKey is not a pp_sk_live_… / pp_sk_test_… key; serving fallback prices.',
                ));
            } elseif ($this->secretKey !== null && !Ed25519::available()) {
                $this->emit(new LeverpriceException(
                    'Leverprice: Ed25519 is unavailable (install ext-sodium or paragonie/sodium_compat); serving fallback prices.',
                ));
            }
            if ($named !== null && $this->countryHeader === null) {
                $this->emit(new LeverpriceException(sprintf(
                    'Leverprice: countryHeader "%s" is sent by visitors\' browsers, so it would let them pick their own price; it is ignored. Name the header your CDN or host sets, such as cf-ipcountry.',
                    $named,
                )));
            }
        }
    }

    /** True for known bots, crawlers, link previewers, headless browsers and monitors. */
    public static function isBot(?string $userAgent): bool
    {
        return Bots::isBot($userAgent);
    }

    /**
     * The price to show and charge for `$productKey`. Never throws because of Leverprice.
     *
     * @param GetPriceContext $context
     *
     * @throws \InvalidArgumentException if `$productKey` is missing from the `products` option
     *
     * @phpstan-impure
     */
    public function getPrice(string $productKey, array $context = []): Quote
    {
        $guard = $this->guards[$productKey] ?? null;
        if ($guard === null) {
            throw new \InvalidArgumentException(sprintf(
                'Leverprice: unknown product "%s". Add it to the products option.',
                $productKey,
            ));
        }
        if ($this->isOff()) {
            return self::fallback($productKey, $guard, Quote::REASON_DISABLED);
        }
        $bot = false;
        $scope = null;
        try {
            $scope = $this->scope($context['request'] ?? null);
            $this->runOverdueWork($this->clock->now());
            $request = RequestReader::read($context, $this->cookieName, $this->countryHeader);
            $verdict = $context['bot'] ?? null;
            $bot = is_bool($verdict) ? $verdict : Bots::isBot($request->userAgent);
            if ($bot) {
                return $this->botQuote($productKey, $guard);
            }
            if ($request->pinned || ($scope !== null && $scope->pinned)) {
                $this->kick($this->clock->now());

                return self::fallback($productKey, $guard, Quote::REASON_PINNED_FALLBACK, [$this->pinCookie()]);
            }
            $config = $this->currentConfig();
            if ($config === null) {
                return $this->noConfig($productKey, $guard, $scope);
            }
            if ($this->countryHeader === null && $config->countryHeader !== null && !is_string($context['country'] ?? null)) {
                $request = $request->withCountry(RequestReader::headerCountry($context, $config->countryHeader));
            }
            $product = $config->product($productKey);
            if ($product === null) {
                return self::fallback($productKey, $guard, Quote::REASON_UNKNOWN_PRODUCT);
            }
            if ($product->currency !== $guard->currency) {
                return self::fallback($productKey, $guard, Quote::REASON_CURRENCY_MISMATCH);
            }
            // The country a visitor holds lives in the cookie, so it is read before the test is chosen.
            $held = $config->countryHold > 0 && $product->hasRegions()
                ? $this->visitor($context, $request, $scope, $config)
                : null;
            $country = $held === null
                ? $request->country
                : $this->heldCountry($held, $guard, $product, $request->country, $config->countryHold);
            $experiment = $product->experimentFor($country);
            if ($experiment === null) {
                if (!$product->inRange($product->price)) {
                    return self::fallback($productKey, $guard, Quote::REASON_OUT_OF_BOUNDS);
                }
                $setCookie = [];
                if ($held !== null) {
                    $cookies = $this->keep($held, $scope, $request, $config);
                    // Outside a test the cookie only carries the held country: none held, none sent.
                    $setCookie = $held->state->c !== null ? $cookies : [];
                }
                $region = $this->regionFor($guard, $product, $country);

                return $region === null
                    ? self::priced($productKey, $guard, $product->price, Quote::REASON_BASE_PRICE, $product->checkoutUrl, $country, $setCookie)
                    : self::regional($productKey, $region, $country, $setCookie);
            }
            if ($experiment->variants === []) {
                return self::fallback($productKey, $guard, Quote::REASON_OUT_OF_BOUNDS);
            }
            foreach ($experiment->variants as $variant) {
                if (!$product->inRange($variant->price)) {
                    return self::fallback($productKey, $guard, Quote::REASON_OUT_OF_BOUNDS);
                }
            }
            $visitor = $held ?? $this->visitor($context, $request, $scope, $config);
            $region = $this->regionFor($guard, $product, $country);

            return $this->experimentQuote($productKey, $guard, $context, $request, $scope, $config, $product, $experiment, $region, $country, $visitor);
        } catch (\Throwable $e) {
            $this->report($e);

            return $bot
                ? self::fallback($productKey, $guard, Quote::REASON_BOT)
                : $this->noConfig($productKey, $guard, $scope);
        }
    }

    /**
     * Logs the exposure of a quote obtained with `'exposure' => 'manual'`; true if one was queued.
     *
     * @phpstan-impure
     */
    public function logExposure(Quote $quote): bool
    {
        try {
            if ($this->isOff() || $quote->reason !== Quote::REASON_EXPERIMENT || $quote->exposureLogged) {
                return false;
            }
            if ($quote->experimentId === null || $quote->variantId === null || $quote->visitorId === null) {
                return false;
            }
            $country = RequestReader::normalizeCountry($quote->country);
            $queued = $this->recordExposure($quote->experimentId, $quote->variantId, $quote->visitorId, $country);
            $quote->exposureLogged = true;

            return $queued;
        } catch (\Throwable $e) {
            $this->report($e);

            return false;
        }
    }

    /**
     * Reports a completed purchase and sends it right away. Never throws.
     *
     * @param array{provider: string, orderId: string|int, amount: int|float, currency: string, metadata?: mixed, timestamp?: int|float|\DateTimeInterface|null} $input
     */
    public function trackPurchase(array $input): void
    {
        try {
            if ($this->isOff()) {
                return;
            }
            $metadata = self::field($input, 'metadata');
            $experimentId = self::metadataId($metadata, 'pp_exp');
            $variantId = self::metadataId($metadata, 'pp_var');
            $visitorId = self::metadataId($metadata, 'pp_vid');
            $signature = self::metadataValue($metadata, 'pp_sig');
            $this->enqueue([
                'type' => 'purchase',
                'provider' => self::field($input, 'provider'),
                'orderId' => self::idOf(self::field($input, 'orderId')),
                'amount' => self::amountOf(self::field($input, 'amount')),
                'currency' => self::currencyOf(self::field($input, 'currency')),
                'timestamp' => $this->timestampOf(self::field($input, 'timestamp')),
                'attribution' => $experimentId !== null && $variantId !== null && $visitorId !== null
                    ? [
                        'experimentId' => $experimentId,
                        'variantId' => $variantId,
                        'visitorId' => $visitorId,
                        'signature' => is_string($signature) && preg_match(self::SIGNATURE_RE, $signature) === 1 ? $signature : null,
                    ]
                    : null,
            ]);
            $this->flushEvents(true);
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    /**
     * Reports a refund (one refund by id, or the order's cumulative refunded total). Never throws.
     *
     * @param array{provider: string, orderId: string|int, currency: string, refundId?: string|int, amount?: int|float, refundedTotal?: int|float, timestamp?: int|float|\DateTimeInterface|null} $input
     */
    public function trackRefund(array $input): void
    {
        try {
            if ($this->isOff()) {
                return;
            }
            $event = [
                'type' => 'refund',
                'provider' => self::field($input, 'provider'),
                'orderId' => self::idOf(self::field($input, 'orderId')),
                'currency' => self::currencyOf(self::field($input, 'currency')),
                'timestamp' => $this->timestampOf(self::field($input, 'timestamp')),
            ];
            if (array_key_exists('refundedTotal', $input)) {
                $event['refundedTotal'] = self::amountOf($input['refundedTotal']);
            } else {
                $event['refundId'] = self::idOf(self::field($input, 'refundId'));
                $event['amount'] = self::amountOf(self::field($input, 'amount'));
            }
            $this->enqueue($event);
            $this->flushEvents(true);
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    /** Sends queued events now and waits until done. Never throws. */
    public function flush(): void
    {
        $this->flushEvents(false);
    }

    /** Fetches the config now and waits for it. Never throws. */
    public function refresh(): void
    {
        try {
            if ($this->isOff()) {
                return;
            }
            $this->ensureLoaded($this->clock->now());
            $live = $this->liveFlight($this->clock->now());
            if ($live !== null) {
                $this->runFlight($live, self::REQUEST_TIMEOUT_MS);
            }
            $flight = $this->startFetch(true);
            if ($flight !== null) {
                $this->runFlight($flight, self::REQUEST_TIMEOUT_MS);
            }
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    /** Fetches the config only if none is usable, and waits for it. Never throws. */
    public function preload(): void
    {
        try {
            if ($this->isOff()) {
                return;
            }
            $this->ensureLoaded($this->clock->now());
            if ($this->usable($this->clock->now()) !== null) {
                return;
            }
            $flight = $this->startFetch(true);
            if ($flight !== null) {
                $this->runFlight($flight, self::REQUEST_TIMEOUT_MS);
            }
            if ($this->usable($this->clock->now()) === null) {
                $this->retryAt = 0;
                $this->persist();
            }
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    /** Finishes pending work (events, config refresh). Runs at shutdown; in worker mode call it after each request. */
    public function terminate(): void
    {
        unset(self::$pending[spl_object_id($this)]);
        try {
            if ($this->isOff()) {
                return;
            }
            if ($this->queue !== [] || $this->outboxSize > 0) {
                $this->syncFromStore($this->clock->now(), true);
                if ($this->eventsRetryAt > $this->clock->now()) {
                    $this->settleOutbox(false);
                    $this->queue = array_values(array_filter(
                        $this->queue,
                        fn(array $e): bool => $e['type'] !== 'exposure' && !isset($this->outboxed[Outbox::identity($e)]),
                    ));
                    $this->outboxed = [];
                    $this->queuedSince = $this->queue === [] ? 0 : $this->queuedSince;
                } else {
                    $this->flushEvents(false);
                }
            }
            for ($round = 0; $round < 2 && $this->flight !== null; $round++) {
                $flight = $this->flight;
                if ($this->liveFlight($this->clock->now()) !== $flight) {
                    $this->flight = null;
                    break;
                }
                $this->runFlight($flight, self::REQUEST_TIMEOUT_MS);
            }
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    /** @param GetPriceContext $context */
    private function experimentQuote(
        string $productKey,
        Guard $guard,
        array $context,
        RequestInfo $request,
        ?RequestScope $scope,
        Config $config,
        Product $product,
        Experiment $experiment,
        ?Region $region,
        ?string $country,
        Visitor $visitor,
    ): Quote {
        $key = $visitor->key;
        $state = $visitor->state;
        $visitorKey = $visitor->userId !== null ? 'u:' . $visitor->userId : 'a:' . $state->v;
        $visitorId = Util::hmacHex($key, 'pp:visitor:v1:' . $visitorKey, 32);

        $assigned = $state->variantFor($experiment->id);
        $variant = $assigned === null ? null : $experiment->variant($assigned);
        if ($variant === null) {
            $weights = $experiment->weightsFor($country);
            $variant = $experiment->variants[Util::pickVariant($weights, Util::bucketFor($experiment->salt, $visitorId))];
            $state->assign($experiment->id, $variant->id);
            $visitor->changed = true;
        }

        $logged = in_array($experiment->id, $state->x, true) || isset($this->exposed[$experiment->id . ':' . $visitorId]);
        if (!$logged && ($context['exposure'] ?? 'auto') === 'auto' && !$request->prefetch) {
            $this->recordExposure($experiment->id, $variant->id, $visitorId, $country);
            $logged = true;
        }
        if ($logged && $state->markExposed($experiment->id)) {
            $visitor->changed = true;
        }

        $setCookie = $this->keep($visitor, $scope, $request, $config);
        $signature = Util::hmacHex($key, 'pp:attr:v1:' . $experiment->id . ':' . $variant->id . ':' . $visitorId, 16);

        return new Quote(
            $productKey,
            $region === null ? $variant->price : $variant->regional[$region->index],
            $region === null ? $guard->currency : $region->currency,
            false,
            Quote::REASON_EXPERIMENT,
            $experiment->id,
            $variant->id,
            $visitorId,
            ['pp_exp' => $experiment->id, 'pp_var' => $variant->id, 'pp_vid' => $visitorId, 'pp_sig' => $signature],
            $setCookie,
            $region === null ? ($variant->checkoutUrl ?? $product->checkoutUrl) : null,
            $logged,
            $country,
            $region !== null,
        );
    }

    /**
     * The visitor behind the request: the state an earlier quote of the same request left, else the
     * first valid assignment cookie, else a new visitor.
     *
     * @param GetPriceContext $context
     */
    private function visitor(array $context, RequestInfo $request, ?RequestScope $scope, Config $config): Visitor
    {
        $key = $this->hmacKey($config->assignmentKey);
        $userId = self::userIdOf($context['userId'] ?? null);
        $state = null;
        if (
            $scope !== null
            && $scope->state !== null
            && $scope->cookieInput === $request->cookieInput
            && $scope->userId === $userId
            && $scope->assignmentKey === $config->assignmentKey
        ) {
            $state = clone $scope->state;
        } else {
            foreach (array_slice($request->assignmentCookies, 0, 4) as $raw) {
                $state = Cookie::verify($key, $raw);
                if ($state !== null) {
                    break;
                }
            }
        }
        if ($state !== null) {
            return new Visitor($key, $userId, $state, false);
        }

        return new Visitor($key, $userId, new AssignmentState($this->newVisitorKey($key, $context, $request)), true);
    }

    /**
     * A new visitor's key. Without a cookie a browser comes back as a new visitor every time, so the key
     * comes from what stays the same between its requests (address, browser, languages), keyed by the
     * project's secret: a visitor who refuses cookies keeps one price and counts once. Neither the
     * address nor the browser leaves the server. Without an address, a random key.
     *
     * @param GetPriceContext $context
     */
    private function newVisitorKey(string $key, array $context, RequestInfo $request): string
    {
        $source = RequestReader::source($context);
        $address = RequestReader::clientAddress($source);
        if ($address === null) {
            return Util::randomHex(16);
        }
        $language = RequestReader::header($source, 'accept-language') ?? '';

        return Util::hmacHex($key, 'pp:anon:v1:' . $address . "\n" . ($request->userAgent ?? '') . "\n" . $language, 32);
    }

    /**
     * The country whose price the visitor gets (Leverprice's VPN guard). A visitor seen lately in a
     * country that pays more keeps that country's price, so switching a VPN to a cheaper country changes
     * nothing until the hold runs out. Anywhere else, the visitor is priced where they are and that
     * country is held from now on.
     */
    private function heldCountry(Visitor $visitor, Guard $guard, Product $product, ?string $country, int $holdSeconds): ?string
    {
        $now = intdiv($this->clock->now(), 1000);
        $state = $visitor->state;
        $held = $state->c !== null && $state->t !== null && $now - $state->t < $holdSeconds ? $state->c : null;
        if ($held !== null && $held !== $country && $this->levelOf($guard, $product, $held) > $this->levelOf($guard, $product, $country)) {
            return $held;
        }
        if ($country !== null && $held !== $country) {
            $state->c = $country;
            $state->t = $now;
            $visitor->changed = true;
        }

        return $country;
    }

    /** What a visitor from `$country` pays, as basis points of the standard price. */
    private function levelOf(Guard $guard, Product $product, ?string $country): int
    {
        return $this->regionFor($guard, $product, $country)->level ?? Wire::STANDARD_LEVEL;
    }

    /**
     * Remembers the visitor for the request's next quote; the Set-Cookie values when it changed.
     *
     * @return list<string>
     */
    private function keep(Visitor $visitor, ?RequestScope $scope, RequestInfo $request, Config $config): array
    {
        if ($scope !== null) {
            $scope->cookieInput = $request->cookieInput;
            $scope->userId = $visitor->userId;
            $scope->assignmentKey = $config->assignmentKey;
            $scope->state = $visitor->state;
        }
        if (!$visitor->changed) {
            return [];
        }
        $value = Cookie::sign($visitor->key, $visitor->state);

        return [Cookie::setCookieHeader($this->cookieName, $value, $this->cookieMaxAge, $this->cookieSecure, $this->cookieDomain)];
    }

    private function regionFor(Guard $guard, Product $product, ?string $country): ?Region
    {
        if ($country === null) {
            return null;
        }
        $region = $product->region($country);
        if ($region === null) {
            return null;
        }
        if ($region->currency !== $guard->currency && !$this->localCurrencies) {
            return null;
        }

        return $region;
    }

    private function botQuote(string $productKey, Guard $guard): Quote
    {
        $product = $this->currentConfig()?->product($productKey);
        if ($product !== null && $product->currency === $guard->currency && $product->inRange($product->price)) {
            return self::priced($productKey, $guard, $product->price, Quote::REASON_BOT, $product->checkoutUrl, null);
        }

        return self::fallback($productKey, $guard, Quote::REASON_BOT);
    }

    private function noConfig(string $productKey, Guard $guard, ?RequestScope $scope): Quote
    {
        if ($scope !== null) {
            $scope->pinned = true;
        }

        return self::fallback($productKey, $guard, Quote::REASON_NO_CONFIG, [$this->pinCookie()]);
    }

    private function pinCookie(): string
    {
        return Cookie::setCookieHeader($this->cookieName . '_f', '1', self::PIN_MAX_AGE_SECONDS, $this->cookieSecure, $this->cookieDomain);
    }

    private function scope(mixed $request): ?RequestScope
    {
        if (is_object($request)) {
            $scope = $this->scopes[$request] ?? null;
            if ($scope === null) {
                $scope = new RequestScope();
                $this->scopes[$request] = $scope;
            }

            return $scope;
        }
        if ($request !== null) {
            return null;
        }
        $key = Superglobals::instance()->scopeKey();
        if ($key === null) {
            return null;
        }
        if ($this->superglobalScope === null || $this->superglobalScopeKey !== $key) {
            $this->superglobalScope = new RequestScope();
            $this->superglobalScopeKey = $key;
        }

        return $this->superglobalScope;
    }

    private function hmacKey(string $assignmentKey): string
    {
        if ($this->hmacSource !== $assignmentKey) {
            $this->hmacBytes = Util::b64urlDecode($assignmentKey);
            $this->hmacSource = $assignmentKey;
        }

        return $this->hmacBytes;
    }

    private function usable(int $now): ?Config
    {
        $config = $this->acceptor->config();
        if ($config === null || $now >= $config->expiresAt || $now - $this->fetchedAt >= $this->maxStaleMs) {
            return null;
        }

        return $this->acceptor->trusted() ? $config : null;
    }

    private function currentConfig(): ?Config
    {
        $now = $this->clock->now();
        $this->ensureLoaded($now);
        $ready = $this->usable($now);
        $this->kick($now);
        if ($ready !== null) {
            return $ready;
        }
        // Each request waits its own timeoutMs: a fetch started earlier (preload(), another request,
        // another process) must not shorten it.
        $deadline = $now + $this->timeoutMs;
        $flight = $this->liveFlight($now);
        if ($flight !== null) {
            $remaining = $deadline - $this->clock->now();
            if ($remaining <= 0 || $flight->running) {
                return $this->usable($this->clock->now());
            }
            $this->runFlight($flight, $remaining);
            $config = $this->usable($this->clock->now());
            if ($config === null && $this->flight === $flight && !$flight->slowReported) {
                $flight->slowReported = true;
                $this->reportSlowConfig();
            }

            return $config;
        }
        if ($this->sharedLeaseLive($now)) {
            $this->awaitSharedFlight($deadline);
            $now = $this->clock->now();
            $config = $this->usable($now);
            if ($config === null && $this->timeoutMs > 0 && $this->sharedLeaseLive($now) && $this->slowLease !== $this->leaseSince) {
                $this->slowLease = $this->leaseSince;
                $this->reportSlowConfig();
            }

            return $config;
        }

        return null;
    }

    private function reportSlowConfig(): void
    {
        $this->report(new LeverpriceException(sprintf(
            'Leverprice config: still loading after %d ms (timeoutMs); serving fallback prices until it arrives',
            $this->timeoutMs,
        )));
    }

    private function kick(int $now): void
    {
        $this->ensureLoaded($now);
        if ($this->liveFlight($now) !== null || !$this->isDue($now)) {
            return;
        }
        $this->syncFromStore($now);
        if (!$this->isDue($now) || $this->sharedLeaseLive($now)) {
            return;
        }
        if ($this->catchUpDue($now)) {
            $this->catchUpAt = $now + self::CATCH_UP_GAP_MS;
            if ($this->startFetch(false, true) === null) {
                $this->persist();
            }

            return;
        }
        $this->startFetch();
    }

    private function isDue(int $now): bool
    {
        return $this->catchUpDue($now)
            || $this->usable($now) === null
            || $now - $this->fetchedAt >= $this->refreshIntervalMs;
    }

    private function catchUpDue(int $now): bool
    {
        return $this->serverVersion > ($this->acceptor->config()->version ?? 0) && $now >= $this->catchUpAt;
    }

    private function liveFlight(int $now): ?Flight
    {
        return $this->flight !== null && $now - $this->flight->startedAt < self::STALL_MS ? $this->flight : null;
    }

    private function sharedLeaseLive(int $now): bool
    {
        return $this->leaseSince > 0
            && $this->leaseOwner !== ''
            && $this->leaseOwner !== $this->owner
            && $now - $this->leaseSince < self::STALL_MS;
    }

    private function awaitSharedFlight(int $deadline): void
    {
        while (($now = $this->clock->now()) < $deadline) {
            $this->clock->sleep(min(self::POLL_MS, $deadline - $now));
            $now = $this->clock->now();
            $this->syncFromStore($now, true);
            if ($this->usable($now) !== null || !$this->sharedLeaseLive($now)) {
                return;
            }
        }
    }

    private function startFetch(bool $force = false, bool $unconditional = false): ?Flight
    {
        $now = $this->clock->now();
        $live = $this->liveFlight($now);
        if ($live !== null) {
            return $live;
        }
        if (!$force && $now < $this->retryAt) {
            return null;
        }
        $this->flight = new Flight($now, $unconditional);
        $this->leaseSince = $now;
        $this->leaseOwner = $this->owner;
        $this->persist();
        $this->awaitTerminate();

        return $this->flight;
    }

    private function runFlight(Flight $flight, int $timeoutMs): void
    {
        if ($flight->running) {
            return;
        }
        $flight->running = true;
        $finished = true;
        $limit = min($timeoutMs, self::REQUEST_TIMEOUT_MS);
        try {
            // Tells the dashboard whether visitors get real prices: the products in code, and why the
            // last config was refused.
            $headers = [Wire::PRODUCTS_HEADER => $this->productsHeader];
            if ($this->configError !== null) {
                $headers[Wire::CONFIG_ERROR_HEADER] = $this->configError;
            }
            if (!$flight->unconditional && $this->etag !== null && $this->usable($this->clock->now()) !== null) {
                $headers['If-None-Match'] = $this->etag;
            }
            $response = $this->request('GET', '/v1/config', $headers, null, $limit);
            if ($response->status === 304 && isset($headers['If-None-Match'])) {
                $this->fetchedAt = $this->clock->now();
            } else {
                $this->acceptResponse($response);
            }
            $this->failures = 0;
            $this->retryAt = 0;
            $this->noteServerVersion($response);
        } catch (TransportException $e) {
            if ($e->timedOut && $limit < self::REQUEST_TIMEOUT_MS) {
                $finished = false;
            } else {
                $this->fetchFailed($e);
            }
        } catch (\Throwable $e) {
            if ($e instanceof ConfigError) {
                $this->noteConfigError($e->refusal);
            }
            $this->fetchFailed($e);
        } finally {
            $flight->running = false;
            if ($finished) {
                if ($this->flight === $flight) {
                    $this->flight = null;
                }
                if ($this->leaseOwner === $this->owner) {
                    $this->leaseSince = 0;
                }
                $this->persist();
            }
        }
    }

    private function acceptResponse(HttpResponse $response): void
    {
        if (!$response->ok()) {
            throw new LeverpriceException(sprintf('Leverprice config: HTTP %d%s', $response->status, self::errorCode($response->body)));
        }
        try {
            $envelope = Util::jsonDecode($response->body);
        } catch (\JsonException $e) {
            throw new ConfigError(ConfigError::MALFORMED, 'Leverprice config: response is not JSON', $e);
        }
        $this->accept($envelope, $response->body, true);
        $this->noteConfigError(null);
        $this->etag = $response->header('ETag');
        $this->fetchedAt = $this->clock->now();
    }

    /** Why the last config body was refused (null: accepted), sent with the next config request. */
    private function noteConfigError(?string $refusal): void
    {
        $this->configError = $refusal;
        $this->configErrorAt = $this->clock->now();
    }

    private function accept(mixed $envelope, string $raw, bool $reportDropped): void
    {
        $before = $this->acceptor->config()?->version;
        $verified = $this->acceptor->accept($envelope, $this->clock->now());
        $this->envelope = $raw;
        if ($reportDropped && $verified->dropped !== [] && $verified->config->version !== $before) {
            $this->report(new LeverpriceException(sprintf(
                'Leverprice config v%d: ignored malformed products %s',
                $verified->config->version,
                implode(', ', $verified->dropped),
            )));
        }
    }

    private function fetchFailed(\Throwable $error): void
    {
        $this->failures++;
        $this->retryAt = $this->clock->now() + self::backoff($this->failures);
        $this->report($error);
    }

    private function noteServerVersion(HttpResponse $response): void
    {
        $raw = $response->header(Wire::CONFIG_VERSION_HEADER);
        if ($raw === null || preg_match('/^\d{1,15}$/D', trim($raw)) !== 1) {
            return;
        }
        $version = (int) trim($raw);
        if ($version <= $this->serverVersion) {
            return;
        }
        $this->serverVersion = $version;
        if ($this->flight === null || !$this->flight->running) {
            $this->persist();
        }
        if ($version > ($this->acceptor->config()->version ?? 0)) {
            $this->kick($this->clock->now());
        }
    }

    private function ensureLoaded(int $now): void
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->syncFromStore($now, true);
            if ($this->outboxSize > 0) {
                $this->awaitTerminate();
            }
        }
    }

    private function syncFromStore(int $now, bool $force = false): void
    {
        if (!$force && $this->syncedAt !== 0 && $now - $this->syncedAt < self::RESYNC_MS) {
            return;
        }
        $this->syncedAt = $now;
        try {
            $stored = SharedState::decode($this->store->read($this->storeKey), $this->macKey);
        } catch (\Throwable) {
            return;
        }
        if ($stored !== null) {
            $this->adopt($stored, $now);
        }
    }

    private function adopt(SharedState $stored, int $now): void
    {
        if ($stored->keyset !== null && $stored->keysetVersion > $this->trust->keysetVersion()) {
            try {
                $this->trust->restore((object) $stored->keyset, $now);
            } catch (\Throwable) {
            }
        }
        $this->trust->raiseFloor($stored->keysetVersion);

        $current = $this->acceptor->config();
        if ($stored->envelope !== null && $stored->envelope === $this->envelope) {
            if ($stored->fetchedAt > $this->fetchedAt) {
                $this->fetchedAt = $stored->fetchedAt;
                $this->etag = $stored->etag ?? $this->etag;
                $this->failures = $stored->failures;
                $this->retryAt = $stored->retryAt;
            }
        } elseif (
            $stored->envelope !== null
            && (
                $current === null
                || $stored->configVersion > $current->version
                || ($stored->configVersion === $current->version && $stored->fetchedAt > $this->fetchedAt)
            )
        ) {
            try {
                $this->accept(Util::jsonDecode($stored->envelope), $stored->envelope, false);
                $this->etag = $stored->etag;
                $this->fetchedAt = $stored->fetchedAt;
                $this->failures = $stored->failures;
                $this->retryAt = $stored->retryAt;
            } catch (\Throwable) {
            }
        }
        if ($stored->retryAt > $this->retryAt) {
            $this->failures = $stored->failures;
            $this->retryAt = $stored->retryAt;
        }
        $this->serverVersion = max($this->serverVersion, $stored->serverVersion);
        $this->catchUpAt = max($this->catchUpAt, $stored->catchUpAt);
        if ($this->leaseOwner !== $this->owner || $this->leaseSince === 0) {
            $this->leaseSince = $stored->leaseSince;
            $this->leaseOwner = $stored->leaseOwner;
        }
        if ($stored->configErrorAt > $this->configErrorAt) {
            $this->configError = $stored->configError;
            $this->configErrorAt = $stored->configErrorAt;
        }
        $this->eventFailures = $stored->eventFailures;
        $this->eventsRetryAt = max($this->eventsRetryAt, $stored->eventsRetryAt);
        $this->outboxSize = $stored->outboxSize;
        $this->outboxTouched = false;
    }

    private function persist(): void
    {
        $mine = $this->snapshot();
        $write = fn(?string $blob): string => $mine
            ->merge(SharedState::decode($blob, $this->macKey), $this->owner)
            ->encode($this->macKey);
        try {
            $saved = $this->store instanceof LockingStateStore
                ? $this->store->update($this->storeKey, $write)
                : $this->store->write($this->storeKey, $write($this->store->read($this->storeKey)));
        } catch (\Throwable) {
            $saved = false;
        }
        if ($this->leaseOwner === $this->owner && $this->leaseSince === 0) {
            $this->leaseOwner = '';
        }
        if (!$saved && !$this->storeErrorReported) {
            $this->storeErrorReported = true;
            $this->report(new LeverpriceException('Leverprice: could not save state to the cache; every request will fetch the config'));
        }
    }

    private function snapshot(): SharedState
    {
        $config = $this->acceptor->config();
        $s = new SharedState();
        $s->envelope = $config !== null ? $this->envelope : null;
        $s->etag = $this->etag;
        $s->fetchedAt = $this->fetchedAt;
        $s->configVersion = $config->version ?? -1;
        $s->projectId = $config?->projectId;
        $s->keyset = $this->trust->newestSigned();
        $s->keysetVersion = $this->trust->keysetVersion();
        $s->serverVersion = $this->serverVersion;
        $s->catchUpAt = $this->catchUpAt;
        $s->failures = $this->failures;
        $s->retryAt = $this->retryAt;
        $s->leaseSince = $this->leaseSince;
        $s->leaseOwner = $this->leaseOwner;
        $s->eventFailures = $this->eventFailures;
        $s->eventsRetryAt = $this->eventsRetryAt;
        $s->outboxSize = $this->outboxSize;
        $s->outboxTouched = $this->outboxTouched;
        $s->configError = $this->configError;
        $s->configErrorAt = $this->configErrorAt;

        return $s;
    }

    private function recordExposure(string $experimentId, string $variantId, string $visitorId, ?string $country): bool
    {
        $key = $experimentId . ':' . $visitorId;
        if (isset($this->exposed[$key])) {
            return false;
        }
        if (count($this->exposed) >= self::MAX_DEDUPE) {
            $this->exposed = [];
        }
        $this->exposed[$key] = true;
        $event = [
            'type' => 'exposure',
            'experimentId' => $experimentId,
            'variantId' => $variantId,
            'visitorId' => $visitorId,
            'timestamp' => $this->clock->now(),
        ];
        if ($country !== null && $country !== '') {
            $event['country'] = $country;
        }

        return $this->enqueue($event);
    }

    /** @param WireEvent $event */
    private function enqueue(array $event): bool
    {
        $problem = Wire::eventProblem($event);
        if ($problem !== null) {
            $this->report(new LeverpriceException(sprintf(
                'Leverprice: dropped invalid %s event: %s',
                Util::describe($event['type'] ?? null),
                $problem,
            )));

            return false;
        }
        if ($this->queue === []) {
            $this->queuedSince = $this->clock->now();
        }
        $this->queue[] = $event;
        $this->trimQueue();
        if (count($this->queue) >= self::AUTO_FLUSH_AT) {
            $this->flush();
        } else {
            $this->awaitTerminate();
        }

        return true;
    }

    private function trimQueue(): void
    {
        while (count($this->queue) > self::MAX_QUEUE) {
            $index = 0;
            foreach ($this->queue as $i => $queued) {
                if ($queued['type'] === 'exposure') {
                    $index = $i;
                    break;
                }
            }
            $dropped = array_splice($this->queue, $index, 1)[0];
            unset($this->outboxed[Outbox::identity($dropped)]);
            if ($dropped['type'] !== 'exposure') {
                $this->report(new LeverpriceException(sprintf(
                    'Leverprice: event queue full, dropped %s %s',
                    Util::describe($dropped['type']),
                    Util::describe($dropped['orderId'] ?? null),
                )));
            }
        }
    }

    private function flushEvents(bool $money): void
    {
        try {
            if ($this->isOff()) {
                return;
            }
            $this->ensureLoaded($this->clock->now());
            for ($round = 0; $round < 2; $round++) {
                $backingOff = $this->eventsRetryAt > $this->clock->now();
                $claimed = !$backingOff && ($money || $this->outboxSize > 0) ? $this->claimOutbox() : false;
                $delivered = $this->drain();
                $this->settleOutbox($claimed);
                if (!$backingOff || !$delivered || $this->eventsRetryAt > $this->clock->now() || $this->outboxSize === 0) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    private function drain(): bool
    {
        $delivered = true;
        for ($n = (int) ceil(count($this->queue) / Wire::MAX_EVENTS_PER_REQUEST); $n > 0 && $this->queue !== []; $n--) {
            $batch = array_splice($this->queue, 0, Wire::MAX_EVENTS_PER_REQUEST);
            if (!$this->send($batch)) {
                $delivered = false;
                break;
            }
        }
        if ($this->queue === []) {
            $this->queuedSince = 0;
        }

        return $delivered;
    }

    private function claimOutbox(): bool
    {
        $claimed = $this->outbox->claim($this->clock->now());
        $this->outboxProblems();
        if ($claimed === [] && $this->outbox->size !== $this->outboxSize) {
            $this->outboxSize = $this->outbox->size;
            $this->outboxTouched = true;
            $this->persist();
        }
        if ($claimed === null || $claimed === []) {
            return false;
        }
        $queued = [];
        foreach ($this->queue as $event) {
            $queued[Outbox::identity($event)] = true;
        }
        $front = [];
        foreach ($claimed as $event) {
            $id = Outbox::identity($event);
            $this->outboxed[$id] = true;
            if (!isset($queued[$id])) {
                $queued[$id] = true;
                $front[] = $event;
            }
        }
        if ($front !== []) {
            $this->queuedSince = $this->queuedSince === 0 ? $this->clock->now() : $this->queuedSince;
            $this->queue = [...$front, ...$this->queue];
            $this->trimQueue();
        }

        return true;
    }

    private function settleOutbox(bool $release): void
    {
        $pending = [];
        $pendingIds = [];
        foreach ($this->queue as $event) {
            if ($event['type'] !== 'exposure') {
                $pending[] = $event;
                $pendingIds[Outbox::identity($event)] = true;
            }
        }
        $done = array_diff_key($this->outboxed, $pendingIds);
        if (!$release && $done === [] && array_diff_key($pendingIds, $this->outboxed) === []) {
            return;
        }
        $saved = $this->outbox->settle($done, $pending, $release, $this->clock->now());
        $this->outboxProblems();
        if (!$saved) {
            return;
        }
        $this->outboxed = $pendingIds;
        if ($this->outbox->size !== $this->outboxSize || !$this->outboxTouched) {
            $this->outboxSize = $this->outbox->size;
            $this->outboxTouched = true;
            $this->persist();
        }
    }

    private function outboxProblems(): void
    {
        foreach ($this->outbox->takeProblems() as $problem) {
            $this->report(new LeverpriceException($problem));
        }
    }

    /** @param list<WireEvent> $events */
    private function requeue(array $events): void
    {
        $money = array_values(array_filter($events, static fn(array $e): bool => $e['type'] !== 'exposure'));
        if ($money === []) {
            return;
        }
        $this->queue = [...$money, ...$this->queue];
        $this->queuedSince = $this->queuedSince === 0 ? $this->clock->now() : $this->queuedSince;
        $this->trimQueue();
    }

    /** @param list<WireEvent> $events */
    private function send(array $events): bool
    {
        $body = Util::jsonEncode(['events' => $events, 'sdk' => ['name' => 'leverprice-php', 'version' => self::SDK_VERSION]]);
        $lastError = null;
        for ($attempt = 0; $attempt < self::SEND_ATTEMPTS && !$this->isOff(); $attempt++) {
            if ($attempt > 0) {
                $this->clock->sleep(250 * 4 ** ($attempt - 1));
            }
            try {
                $response = $this->request('POST', '/v1/events', ['Content-Type' => 'application/json'], $body, self::REQUEST_TIMEOUT_MS);
                $this->noteServerVersion($response);
                if ($response->ok()) {
                    $this->eventsDelivered();

                    return true;
                }
                if ($response->status < 500 && $response->status !== 408 && $response->status !== 429) {
                    $this->report(new LeverpriceException(sprintf(
                        'Leverprice events rejected: HTTP %d %s',
                        $response->status,
                        self::prefix($response->body, 300),
                    )));

                    return true;
                }
                $lastError = new LeverpriceException('Leverprice events: HTTP ' . $response->status);
            } catch (\Throwable $e) {
                $lastError = $e;
            }
            $this->eventsFailed();
        }
        $this->requeue($events);
        if ($lastError !== null) {
            $this->report($lastError);
        }

        return false;
    }

    private function eventsDelivered(): void
    {
        if ($this->eventFailures !== 0 || $this->eventsRetryAt !== 0) {
            $this->eventFailures = 0;
            $this->eventsRetryAt = 0;
            $this->persist();
        }
    }

    private function eventsFailed(): void
    {
        $this->eventFailures++;
        $this->eventsRetryAt = $this->clock->now() + self::backoff($this->eventFailures);
        $this->persist();
    }

    private function runOverdueWork(int $now): void
    {
        if ($this->queue !== [] && $this->queuedSince > 0 && $now - $this->queuedSince >= $this->flushIntervalMs) {
            $this->flush();
        }
        $flight = $this->flight;
        if (
            $flight !== null
            && !$flight->running
            && !$flight->triedInline
            && $now - $flight->startedAt >= $this->flushIntervalMs
            && $this->liveFlight($now) === $flight
        ) {
            $flight->triedInline = true;
            $this->runFlight($flight, $this->timeoutMs);
        }
    }

    private function awaitTerminate(): void
    {
        if (!$this->registerShutdown) {
            return;
        }
        self::$pending[spl_object_id($this)] = $this;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                for ($round = 0; $round < 3 && self::$pending !== []; $round++) {
                    $instances = self::$pending;
                    self::$pending = [];
                    foreach ($instances as $instance) {
                        $instance->shutdown();
                    }
                }
            });
        }
    }

    private function shutdown(): void
    {
        if ($this->isOff()) {
            return;
        }
        if ($this->finishRequest && !self::$requestFinished) {
            self::$requestFinished = true;
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
        }
        $this->terminate();
        $lost = count(array_filter(
            $this->queue,
            fn(array $e): bool => $e['type'] !== 'exposure' && !isset($this->outboxed[Outbox::identity($e)]),
        ));
        if ($lost > 0) {
            $this->report(new LeverpriceException(sprintf(
                'Leverprice: %d purchase/refund event(s) could not be delivered before shutdown',
                $lost,
            )));
        }
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, array $headers, ?string $body, int $timeoutMs): HttpResponse
    {
        $headers['Authorization'] = 'Bearer ' . $this->secretKey;
        $headers['User-Agent'] = 'leverprice-sdk-php/' . self::SDK_VERSION;
        try {
            return $this->transport->send(new HttpRequest($method, $this->apiUrl . $path, $headers, $body, $timeoutMs));
        } catch (TransportException $e) {
            throw new TransportException(
                $e->timedOut
                    ? sprintf('Leverprice: %s %s timed out after %d ms', $method, $path, $timeoutMs)
                    : sprintf('Leverprice: %s %s failed: %s', $method, $path, $e->getMessage()),
                $e->timedOut,
                $e,
            );
        }
    }

    private function isOff(): bool
    {
        return $this->disabled || $this->secretKey === null || !Ed25519::available() || self::envDisabled();
    }

    private function report(\Throwable $error): void
    {
        if (!$this->isOff()) {
            $this->emit($error);
        }
    }

    private function emit(\Throwable $error): void
    {
        try {
            if ($this->onError !== null) {
                ($this->onError)($error);
            } elseif ($this->logger !== null) {
                $this->logger->warning($error->getMessage(), ['exception' => $error]);
            } else {
                error_log($error->getMessage());
            }
        } catch (\Throwable) {
        }
    }

    private function timestampOf(mixed $t): int|float|null
    {
        if ($t === null) {
            return $this->clock->now();
        }
        if ($t instanceof \DateTimeInterface) {
            return (int) $t->format('Uv');
        }
        if (is_string($t) && is_numeric($t)) {
            $t = (float) $t;
        }
        if (!is_int($t) && !is_float($t)) {
            return null;
        }
        $rounded = Util::jsRound((float) ($t < 1e12 ? $t * 1000 : $t));

        return Util::safeInt($rounded) ?? $rounded;
    }

    /** @param array<string, mixed> $input */
    private static function field(array $input, string $key): mixed
    {
        return $input[$key] ?? null;
    }

    private static function envDisabled(): bool
    {
        foreach ([getenv('LEVERPRICE_DISABLED'), $_ENV['LEVERPRICE_DISABLED'] ?? null, $_SERVER['LEVERPRICE_DISABLED'] ?? null] as $value) {
            if (is_string($value)) {
                $value = strtolower(Util::trim($value));
                if ($value === '1' || $value === 'true') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function backoff(int $failures): int
    {
        return min(self::MAX_BACKOFF_MS, 500 * 2 ** min($failures, 16));
    }

    /** @param list<string> $setCookie */
    private static function fallback(string $productKey, Guard $guard, string $reason, array $setCookie = []): Quote
    {
        return new Quote($productKey, $guard->fallbackPrice, $guard->currency, true, $reason, null, null, null, [], $setCookie, null, false, null, false);
    }

    /** @param list<string> $setCookie */
    private static function priced(string $productKey, Guard $guard, int $price, string $reason, ?string $checkoutUrl, ?string $country, array $setCookie = []): Quote
    {
        return new Quote($productKey, $price, $guard->currency, false, $reason, null, null, null, [], $setCookie, $checkoutUrl, false, $country, false);
    }

    /** @param list<string> $setCookie */
    private static function regional(string $productKey, Region $region, ?string $country, array $setCookie = []): Quote
    {
        return new Quote($productKey, $region->price, $region->currency, false, Quote::REASON_BASE_PRICE, null, null, null, [], $setCookie, null, false, $country, true);
    }

    private static function userIdOf(mixed $id): ?string
    {
        if (is_int($id)) {
            return (string) $id;
        }
        if (is_float($id)) {
            $int = Util::safeInt($id);

            return $int === null ? null : (string) $int;
        }
        if ($id instanceof \Stringable) {
            $id = (string) $id;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    private static function idOf(mixed $id): string
    {
        if (is_string($id)) {
            return $id;
        }
        $int = is_int($id) || is_float($id) ? Util::safeInt($id) : null;

        return $int === null ? '' : (string) $int;
    }

    private static function amountOf(mixed $amount): int|float|null
    {
        if (is_int($amount)) {
            return $amount;
        }
        if (is_string($amount) && is_numeric($amount)) {
            $amount = (float) $amount;
        }
        if (!is_float($amount)) {
            return null;
        }
        $rounded = Util::jsRound($amount);

        return Util::safeInt($rounded) ?? $rounded;
    }

    private static function currencyOf(mixed $currency): string
    {
        return is_string($currency) ? strtolower($currency) : '';
    }

    private static function metadataId(mixed $metadata, string $key): ?string
    {
        $value = self::metadataValue($metadata, $key);

        return is_string($value) && Util::isId($value) ? $value : null;
    }

    private static function metadataValue(mixed $metadata, string $key): mixed
    {
        if (is_array($metadata)) {
            return array_key_exists($key, $metadata) ? $metadata[$key] : null;
        }
        if ($metadata instanceof \ArrayAccess) {
            return $metadata->offsetExists($key) ? $metadata->offsetGet($key) : null;
        }
        if (!is_object($metadata)) {
            return null;
        }
        if (method_exists($metadata, 'toArray')) {
            $values = $metadata->toArray();

            return is_array($values) && array_key_exists($key, $values) ? $values[$key] : null;
        }
        $values = get_object_vars($metadata);

        return array_key_exists($key, $values) ? $values[$key] : null;
    }

    private static function errorCode(string $body): string
    {
        try {
            $data = Util::jsonDecode($body);
        } catch (\JsonException) {
            return '';
        }
        $error = $data instanceof \stdClass ? Util::prop($data, 'error') : null;
        $code = $error instanceof \stdClass ? Util::prop($error, 'code') : null;

        return is_string($code) ? ' (' . $code . ')' : '';
    }

    private static function prefix(string $text, int $chars): string
    {
        if (Util::isUtf8($text) && preg_match('/^.{0,' . $chars . '}/su', $text, $m) === 1) {
            return $m[0];
        }

        return substr($text, 0, $chars);
    }

    /** @return array<string, Guard> */
    private static function guards(mixed $products): array
    {
        if (!is_array($products)) {
            throw new \InvalidArgumentException('Leverprice: products must be an array of product guards');
        }
        $guards = [];
        foreach ($products as $key => $guard) {
            $where = 'Leverprice: products.' . $key;
            if (!is_array($guard)) {
                throw new \InvalidArgumentException($where . ' must be an array');
            }
            $currency = $guard['currency'] ?? null;
            if (!is_string($currency) || preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1) {
                throw new \InvalidArgumentException($where . '.currency must be an ISO 4217 code such as "usd"');
            }
            $fallback = Util::safeInt($guard['fallbackPrice'] ?? null);
            if ($fallback === null || $fallback <= 0) {
                throw new \InvalidArgumentException($where . '.fallbackPrice must be a positive integer (minor units)');
            }
            $guards[(string) $key] = new Guard(strtolower($currency), $fallback);
        }

        return $guards;
    }

    /**
     * `Leverprice-Products`: `key=currency` per product, by key, keys percent-encoded (everything but
     * `A–Z a–z 0–9 - . _ ~`, byte by byte), at most 50.
     *
     * @param array<string, Guard> $guards
     */
    private static function productsHeader(array $guards): string
    {
        $entries = [];
        foreach ($guards as $key => $guard) {
            $entries[rawurlencode((string) $key)] = $guard->currency;
        }
        ksort($entries, SORT_STRING);
        $parts = [];
        foreach (array_slice($entries, 0, Wire::MAX_REPORTED_PRODUCTS, true) as $key => $currency) {
            $parts[] = $key . '=' . $currency;
        }

        return implode(', ', $parts);
    }

    /** @return array<string, string> */
    private static function rootKeys(mixed $keys): array
    {
        if (!is_array($keys)) {
            throw new \InvalidArgumentException('Leverprice: trustedRootKeys must map key ids to base64url public keys');
        }
        $out = [];
        foreach ($keys as $id => $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Leverprice: trustedRootKeys must map key ids to base64url public keys');
            }
            $out[(string) $id] = $key;
        }

        return $out;
    }

    /** @param array<string, mixed> $options */
    private static function transport(array $options): Transport
    {
        $transport = $options['transport'] ?? null;
        if ($transport !== null) {
            if (!$transport instanceof Transport) {
                throw new \InvalidArgumentException('Leverprice: transport must implement Leverprice\Http\Transport');
            }

            return $transport;
        }
        $client = $options['httpClient'] ?? null;
        if ($client === null) {
            return CurlTransport::isSupported() ? new CurlTransport() : new StreamTransport();
        }
        if (!$client instanceof ClientInterface) {
            throw new \InvalidArgumentException('Leverprice: httpClient must be a PSR-18 client');
        }
        $requestFactory = $options['requestFactory'] ?? $client;
        $streamFactory = $options['streamFactory'] ?? $requestFactory;
        if (!$requestFactory instanceof RequestFactoryInterface || !$streamFactory instanceof StreamFactoryInterface) {
            throw new \InvalidArgumentException('Leverprice: httpClient needs PSR-17 requestFactory and streamFactory options');
        }

        return new Psr18Transport($client, $requestFactory, $streamFactory);
    }

    private static function store(mixed $cache): StateStore
    {
        return match (true) {
            $cache === false => new MemoryStore(),
            $cache instanceof StateStore => $cache,
            $cache instanceof CacheInterface => new Psr16Store($cache),
            $cache === null => ApcuStore::isSupported() ? new ApcuStore() : new FileStore(),
            default => throw new \InvalidArgumentException(
                'Leverprice: cache must be a PSR-16 cache, a Leverprice\State\StateStore or false',
            ),
        };
    }

    /** @param array<string, mixed> $options */
    private static function intOption(array $options, string $name, int $default): int
    {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        $int = Util::safeInt($value);
        if ($int === null || $int < 0) {
            throw new \InvalidArgumentException(sprintf('Leverprice: %s must be a non-negative integer', $name));
        }

        return $int;
    }

    /** @param array<string, mixed> $options */
    private static function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Leverprice: %s must be a string', $name));
        }

        return $value;
    }
}
