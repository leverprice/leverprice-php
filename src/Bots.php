<?php

declare(strict_types=1);

namespace Leverprice;

use Leverprice\Internal\Util;

/** User-agent detection of crawlers, link previewers, headless browsers, monitors and HTTP libraries. */
final class Bots
{
    private const PATTERNS = [
        '(?<!cu)bot(?![a-z])',
        'crawl',
        'spider',
        'scrap',
        'slurp',
        'archiver',
        'fetcher',
        '\+https?:',
        'facebookexternalhit',
        'facebookcatalog',
        'meta-external',
        'whatsapp\/',
        'skypeuripreview',
        'bingpreview',
        'microsoftpreview',
        'link ?preview',
        'url preview',
        'web preview',
        'slack-imgproxy',
        'vkshare',
        'embedly',
        'iframely',
        'cardyb',
        'outbrain',
        'flipboardproxy',
        'mediapartners-google',
        'apis-google',
        'duplexweb-google',
        'google-(?:inspectiontool|site-verification|read-aloud|pagerenderer|structured-data|safety)',
        'googleother',
        'googleproducer',
        'google favicon',
        'chatgpt-user',
        'claude-user',
        'perplexity-user',
        'mistralai-user',
        'anthropic-ai',
        'cohere-ai',
        'daumoa',
        'ahrefs',
        'sitebulb',
        'siteimprove',
        'barkrowler',
        'botify',
        'seokicks',
        'validator',
        'checklink',
        'heritrix',
        'headless',
        'phantomjs',
        'slimerjs',
        'htmlunit',
        'jsdom',
        'cypress',
        'lighthouse',
        'page ?speed',
        'gtmetrix',
        'ptst\/',
        'pingdom',
        'uptime',
        'statuscake',
        'site24x7',
        'newrelicpinger',
        'synthetic',
        'checkly',
        'health-?check',
        'googlehc',
        'kube-probe',
        'nagios',
        'zabbix',
        'catchpoint',
        'freshping',
        'hetrixtools',
        'ohdear',
        'monitoring',
        'curl',
        'wget',
        'httpie',
        'python-requests',
        'python-urllib',
        'python-httpx',
        'aiohttp',
        'go-http-client',
        '^java\/',
        'apache-httpclient',
        'libwww-perl',
        'lwp::',
        'guzzlehttp',
        'postmanruntime',
        'insomnia',
        'axios\/',
        'node-fetch',
        'undici',
    ];

    private static ?string $pattern = null;

    /** True for known bots; an empty or missing user agent counts as a person. */
    public static function isBot(?string $userAgent): bool
    {
        if ($userAgent === null) {
            return false;
        }
        $ua = Util::trim($userAgent);

        return $ua !== '' && preg_match(self::pattern(), $ua) === 1;
    }

    /** The PCRE pattern behind isBot(). */
    public static function pattern(): string
    {
        return self::$pattern ??= '/' . implode('|', self::PATTERNS) . '/i';
    }
}
