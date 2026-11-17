<?php

declare(strict_types=1);

namespace Leverprice\State;

/** One file per key (mode 0600, replaced atomically). Defaults to the system temp directory. */
final class FileStore implements LockingStateStore
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? sys_get_temp_dir(), '/\\');
    }

    public function read(string $key): ?string
    {
        return self::quietly(function () use ($key): ?string {
            $path = $this->path($key);
            if (!is_file($path)) {
                return null;
            }
            $contents = file_get_contents($path);

            return is_string($contents) ? $contents : null;
        }, null);
    }

    public function write(string $key, string $value): bool
    {
        return self::quietly(function () use ($key, $value): bool {
            $tmp = tempnam($this->directory, 'leverprice-');
            if ($tmp === false) {
                return false;
            }
            if (realpath(dirname($tmp)) !== realpath($this->directory)) {
                unlink($tmp);

                return false;
            }
            chmod($tmp, 0o600);
            if (file_put_contents($tmp, $value) !== strlen($value) || !rename($tmp, $this->path($key))) {
                if (file_exists($tmp)) {
                    unlink($tmp);
                }

                return false;
            }

            return true;
        }, false);
    }

    public function update(string $key, callable $mutate): bool
    {
        $lock = self::quietly(function () use ($key) {
            $path = $this->path($key) . '.lock';
            $handle = fopen($path, 'c');
            if ($handle === false) {
                return null;
            }
            chmod($path, 0o600);
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);

                return null;
            }

            return $handle;
        }, null);
        try {
            $value = $mutate($this->read($key));

            return $value === null || $this->write($key, $value);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'leverprice-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.state';
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @param T $default
     * @return T
     */
    private static function quietly(callable $fn, mixed $default): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $fn();
        } catch (\Throwable) {
            return $default;
        } finally {
            restore_error_handler();
        }
    }
}
