<?php

declare(strict_types=1);

namespace Leverprice\Tests;

use Leverprice\Internal\Product;
use Leverprice\Internal\Util;
use Leverprice\Internal\Variant;
use Leverprice\Internal\Wire;
use Leverprice\Tests\Support\TestCase;

final class WireTest extends TestCase
{
    private const PRODUCT = ['currency' => 'usd', 'price' => 2900, 'minPrice' => 1900, 'maxPrice' => 4900];
    private const REGIONS = [
        ['countries' => ['BR', 'MX'], 'currency' => 'usd', 'price' => 2000],
        ['countries' => ['IN'], 'currency' => 'INR', 'price' => 99900],
    ];

    /**
     * @param array<string, mixed> $products
     * @param array<string, mixed> $fields
     */
    private static function payload(array $products, array $fields = []): string
    {
        return Util::b64urlEncode(Util::jsonEncode($fields + [
            'schema' => 2,
            'projectId' => 'proj_test',
            'version' => 1,
            'expiresAt' => 4102444800000,
            'assignmentKey' => Util::b64urlEncode(str_repeat('k', 32)),
            'keyFingerprints' => [],
            'products' => $products,
        ]));
    }

    /**
     * @param list<string> $countries
     * @return array{countries: list<string>, currency: string, price: int|float}
     */
    private static function region(array $countries, string $currency = 'inr', int|float $price = 99900): array
    {
        return ['countries' => $countries, 'currency' => $currency, 'price' => $price];
    }

    /** @return list<string> */
    private static function codes(int $count): array
    {
        $codes = [];
        foreach (range('A', 'Z') as $first) {
            foreach (range('A', 'Z') as $second) {
                $codes[] = $first . $second;
            }
        }

        return array_slice($codes, 0, $count);
    }

    /** @return list<array{countries: list<string>, currency: string, price: int|float}> */
    private static function regions(int $count): array
    {
        return array_map(static fn(string $code): array => self::region([$code], 'usd', 2000), self::codes($count));
    }

    /**
     * @param list<mixed> $regional
     * @return array<string, mixed>
     */
    private static function experiment(array $regional): array
    {
        $variants = [];
        foreach ($regional as $i => $prices) {
            $variants[] = ['id' => 'var_' . ($i + 1), 'price' => 1900 + 2000 * $i, 'weight' => 5000]
                + ($prices === null ? [] : ['regional' => $prices]);
        }

        return ['id' => 'exp_1', 'salt' => 's', 'variants' => $variants];
    }

    /** @return list<list<int>> */
    private static function regional(?Product $product): array
    {
        return array_map(static fn(Variant $v): array => $v->regional, $product?->experimentFor(null)->variants ?? []);
    }

    public function testReadsRegionalPricesAndDropsProductsWhoseRegionsDoNotAddUp(): void
    {
        [$config, $dropped] = Wire::parseConfig(self::payload([
            'flat' => self::PRODUCT + ['regions' => []],
            'geo' => self::PRODUCT + ['regions' => self::REGIONS],
            'test' => self::PRODUCT + ['regions' => self::REGIONS, 'experiment' => self::experiment([[1300, 64900], [2700, 129900]])],
            'ignored' => self::PRODUCT + ['experiment' => self::experiment([[1], 'junk'])],
            'widest' => self::PRODUCT + ['regions' => self::regions(Wire::MAX_REGIONS)],
            'twice' => self::PRODUCT + ['regions' => [...self::REGIONS, self::region(['IN'], 'usd', 1)]],
            'repeated' => self::PRODUCT + ['regions' => [self::region(['IN', 'IN'])]],
            'lower' => self::PRODUCT + ['regions' => [self::region(['in'])]],
            'empty' => self::PRODUCT + ['regions' => [self::region([])]],
            'free' => self::PRODUCT + ['regions' => [self::region(['IN'], 'inr', 0)]],
            'fraction' => self::PRODUCT + ['regions' => [self::region(['IN'], 'inr', 999.5)]],
            'money' => self::PRODUCT + ['regions' => [self::region(['IN'], 'rupee')]],
            'shape' => self::PRODUCT + ['regions' => ['IN' => 1]],
            'crowded' => self::PRODUCT + ['regions' => self::regions(Wire::MAX_REGIONS + 1)],
            'short' => self::PRODUCT + ['regions' => self::REGIONS, 'experiment' => self::experiment([[1300], [2700, 129900]])],
            'missing' => self::PRODUCT + ['regions' => self::REGIONS, 'experiment' => self::experiment([[1300, 64900], null])],
            'zero' => self::PRODUCT + ['regions' => self::REGIONS, 'experiment' => self::experiment([[1300, 0], [2700, 129900]])],
        ]));

        self::assertSame(
            ['twice', 'repeated', 'lower', 'empty', 'free', 'fraction', 'money', 'shape', 'crowded', 'short', 'missing', 'zero'],
            $dropped,
        );
        self::assertNull($config->product('flat')?->region('IN'));

        $geo = $config->product('geo');
        self::assertNotNull($geo);
        $india = $geo->region('IN');
        self::assertNotNull($india);
        self::assertSame([1, ['IN'], 'inr', 99900], [$india->index, $india->countries, $india->currency, $india->price]);
        self::assertSame(0, $geo->region('BR')?->index);
        self::assertSame($geo->region('BR'), $geo->region('MX'));
        self::assertNull($geo->region('US'));

        self::assertSame([[1300, 64900], [2700, 129900]], self::regional($config->product('test')));
        self::assertSame([[], []], self::regional($config->product('ignored')));
        self::assertSame(Wire::MAX_REGIONS - 1, $config->product('widest')?->region('LN')?->index);
    }

    public function testReadsScopesAndSplitsAndDropsProductsWhoseTestsByCountryDoNotAddUp(): void
    {
        $test = self::experiment([null, null]);
        $scope = static fn(mixed $countries): array => self::PRODUCT + ['scopedExperiment' => $test + ['countries' => $countries]];
        $splits = static fn(mixed $splits): array => self::PRODUCT + ['experiment' => $test + ['splits' => $splits]];
        $split = static fn(mixed $countries, mixed $weights): array => ['countries' => $countries, 'weights' => $weights];
        $each = static fn(int $count): array => array_map(static fn(string $code): array => $split([$code], [0, 1]), self::codes($count));

        $kept = [
            'open' => self::PRODUCT + ['experiment' => $test, 'scopedExperiment' => null],
            'unsplit' => $splits(null),
            'local' => self::PRODUCT + ['scopedExperiment' => $test + ['countries' => ['BR', 'IN'], 'splits' => [$split(['IN', 'US'], [10000, 0])]]],
            'widest' => $scope(self::codes(Wire::MAX_SCOPE)),
            'most' => $splits($each(Wire::MAX_SPLITS)),
            'everywhere' => $splits([$split(self::codes(676), [1, 0])]),
        ];
        $malformed = [];
        foreach ([null, [], 'IN', (object) ['IN'], ['in'], ['IND'], ["IN\n"], [91], ['IN', 'BR', 'IN']] as $i => $countries) {
            $malformed['scope' . $i] = $scope($countries);
            $malformed['split' . $i] = $splits([$split($countries, [5000, 5000])]);
        }
        foreach ([null, (object) [5000, 5000], [10000], [5000, 2500, 2500], [10001, -1], [2500.5, 7499.5], [true, false], ['5000', '5000']] as $i => $weights) {
            $malformed['weights' . $i] = $splits([$split(['IN'], $weights)]);
        }
        $malformed += [
            'crowded' => $scope(self::codes(Wire::MAX_SCOPE + 1)),
            'unsalted' => self::PRODUCT + ['scopedExperiment' => ['salt' => null, 'countries' => ['IN']] + $test],
            'both' => self::PRODUCT + ['experiment' => $test, 'scopedExperiment' => $test + ['countries' => ['IN']]],
            'shape' => $splits((object) [$split(['IN'], [5000, 5000])]),
            'entry' => $splits([['IN']]),
            'twice' => $splits([$split(['IN'], [5000, 5000]), $split(['BR', 'IN'], [10000, 0])]),
            'many' => $splits($each(Wire::MAX_SPLITS + 1)),
        ];

        [$config, $dropped] = Wire::parseConfig(self::payload($kept + $malformed));

        self::assertSame(array_keys($malformed), $dropped);
        self::assertSame([5000, 5000], $config->product('open')?->experimentFor(null)?->weightsFor('IN'));
        self::assertSame([5000, 5000], $config->product('unsplit')?->experimentFor('IN')?->weightsFor('IN'));

        $local = $config->product('local');
        self::assertNotNull($local);
        self::assertNull($local->experimentFor(null));
        self::assertNull($local->experimentFor('US'));
        $running = $local->experimentFor('IN');
        self::assertNotNull($running);
        self::assertSame($running, $local->experimentFor('BR'));
        self::assertSame([10000, 0], $running->weightsFor('IN'));
        self::assertSame([5000, 5000], $running->weightsFor('BR'));
        self::assertSame([5000, 5000], $running->weightsFor(null));

        self::assertNotNull($config->product('widest')?->experimentFor('LN'));
        self::assertSame([0, 1], $config->product('most')?->experimentFor(null)?->weightsFor('LN'));
        self::assertSame([1, 0], $config->product('everywhere')?->experimentFor(null)?->weightsFor('ZZ'));
    }

    public function testReadsTheProjectCountryHeaderAndIgnoresAnythingElse(): void
    {
        $header = static fn(mixed $value): ?string => Wire::parseConfig(self::payload(['flat' => self::PRODUCT], ['countryHeader' => $value]))[0]->countryHeader;

        self::assertNull(Wire::parseConfig(self::payload(['flat' => self::PRODUCT]))[0]->countryHeader);
        self::assertSame('x-geo-country', $header('X-Geo-Country'));
        self::assertSame('cf-ipcountry!#$%&\'*+-.^_`|~', $header('CF-IPCountry!#$%&\'*+-.^_`|~'));
        foreach ([null, '', ' cf-ipcountry', "cf-ipcountry\n", 'cf ipcountry', 'cf-ipcountry:', "\u{212A}ey", 42, true, ['cf-ipcountry']] as $value) {
            self::assertNull($header($value), self::envelope($value));
        }
    }

    public function testExposureCountryIsAnUppercaseTwoLetterCodeOrAbsent(): void
    {
        $exposure = ['type' => 'exposure', 'experimentId' => 'exp_A', 'variantId' => 'var_1', 'visitorId' => 'abc', 'timestamp' => 1800000000000];

        self::assertNull(Wire::eventProblem($exposure));
        self::assertNull(Wire::eventProblem($exposure + ['country' => 'IN']));
        foreach (['in', 'IND', '', 5, null] as $country) {
            self::assertSame('country must be an ISO 3166-1 alpha-2 code', Wire::eventProblem($exposure + ['country' => $country]));
        }
    }
}
