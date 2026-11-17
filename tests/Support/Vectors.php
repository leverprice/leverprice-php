<?php

declare(strict_types=1);

namespace Leverprice\Tests\Support;

final class Vectors
{
    private static ?string $json = null;

    public static function path(): string
    {
        $dir = __DIR__;
        while (true) {
            foreach (['conformance/vectors.json', 'sdks/conformance/vectors.json'] as $candidate) {
                $path = $dir . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($path)) {
                    return $path;
                }
            }
            $up = dirname($dir);
            if ($up === $dir) {
                throw new \RuntimeException('conformance/vectors.json not found');
            }
            $dir = $up;
        }
    }

    /** @return array<string, mixed> */
    public static function arrays(): array
    {
        $data = json_decode(self::json(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('vectors.json is not an object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    public static function objects(): \stdClass
    {
        $data = json_decode(self::json(), false, 512, JSON_THROW_ON_ERROR);
        if (!$data instanceof \stdClass) {
            throw new \RuntimeException('vectors.json is not an object');
        }

        return $data;
    }

    private static function json(): string
    {
        if (self::$json === null) {
            $json = file_get_contents(self::path());
            if ($json === false) {
                throw new \RuntimeException('cannot read vectors.json');
            }
            self::$json = $json;
        }

        return self::$json;
    }
}
