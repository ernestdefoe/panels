<?php

namespace ErnestDefoe\Panels\Tests\unit;

use ErnestDefoe\Panels\Media\Store;
use Flarum\Settings\SettingsRepositoryInterface;

/** Settings held in memory. */
class MemorySettings implements SettingsRepositoryInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $keyLike): void
    {
        unset($this->values[$keyLike]);
    }
}

/** A Store that keeps what it's given, so tests can read the bytes that would be served. */
class CapturingStore extends Store
{
    /** @var array<string, string> */
    public array $files = [];

    public function __construct()
    {
    }

    public function put(bool $public, string $path, string $bytes): void
    {
        $this->files[$path] = $bytes;
    }
}
