<?php

declare(strict_types=1);

namespace Leverprice;

/** The price to show and charge for one product, and why. */
final class Quote implements \JsonSerializable
{
    public const REASON_EXPERIMENT = 'experiment';
    public const REASON_BASE_PRICE = 'base_price';
    public const REASON_BOT = 'bot';
    public const REASON_DISABLED = 'disabled';
    public const REASON_NO_CONFIG = 'no_config';
    public const REASON_PINNED_FALLBACK = 'pinned_fallback';
    public const REASON_UNKNOWN_PRODUCT = 'unknown_product';
    public const REASON_CURRENCY_MISMATCH = 'currency_mismatch';
    public const REASON_OUT_OF_BOUNDS = 'out_of_bounds';

    /**
     * @param array<string, string> $metadata
     * @param list<string> $setCookie
     */
    public function __construct(
        public readonly string $productKey,
        public readonly int $price,
        public readonly string $currency,
        public readonly bool $isFallback,
        public readonly string $reason,
        public readonly ?string $experimentId,
        public readonly ?string $variantId,
        public readonly ?string $visitorId,
        public readonly array $metadata,
        public readonly array $setCookie,
        public readonly ?string $checkoutUrl,
        public bool $exposureLogged,
    ) {}

    /** Sends `setCookie` with header(); false when headers were already sent. */
    public function applyCookies(): bool
    {
        if ($this->setCookie === []) {
            return true;
        }
        if (headers_sent()) {
            return false;
        }
        foreach ($this->setCookie as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }

        return true;
    }

    /** Adds `setCookie` to a Symfony/Laravel response (in place) or a PSR-7 response (returns the new instance). */
    public function applyCookiesTo(object $response): object
    {
        if (method_exists($response, 'withAddedHeader')) {
            foreach ($this->setCookie as $cookie) {
                $next = $response->withAddedHeader('Set-Cookie', $cookie);
                if (!is_object($next) || !method_exists($next, 'withAddedHeader')) {
                    throw new \InvalidArgumentException('Leverprice: withAddedHeader() did not return a response');
                }
                $response = $next;
            }

            return $response;
        }
        $headers = $response->headers ?? null;
        if (is_object($headers) && method_exists($headers, 'set')) {
            foreach ($this->setCookie as $cookie) {
                $headers->set('Set-Cookie', $cookie, false);
            }

            return $response;
        }

        throw new \InvalidArgumentException('Leverprice: unsupported response object ' . get_debug_type($response));
    }

    /**
     * @return array{productKey: string, price: int, currency: string, isFallback: bool, reason: string,
     *     experimentId: ?string, variantId: ?string, visitorId: ?string, metadata: array<string, string>,
     *     setCookie: list<string>, checkoutUrl: ?string, exposureLogged: bool}
     */
    public function toArray(): array
    {
        return [
            'productKey' => $this->productKey,
            'price' => $this->price,
            'currency' => $this->currency,
            'isFallback' => $this->isFallback,
            'reason' => $this->reason,
            'experimentId' => $this->experimentId,
            'variantId' => $this->variantId,
            'visitorId' => $this->visitorId,
            'metadata' => $this->metadata,
            'setCookie' => $this->setCookie,
            'checkoutUrl' => $this->checkoutUrl,
            'exposureLogged' => $this->exposureLogged,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        $data['metadata'] = (object) $data['metadata'];

        return $data;
    }
}
