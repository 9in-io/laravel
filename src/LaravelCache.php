<?php

namespace Nine\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Nine\Storage\AtomicStorageInterface;

/**
 * Laravel cache store as a 9in SDK store (discovery document, signing keys, processed
 * webhook ids). Use a store shared by all servers (redis, database, memcached) when you
 * run more than one.
 */
class LaravelCache implements AtomicStorageInterface
{
    /** @var Repository */
    protected $cache;

    public function __construct(Repository $cache)
    {
        $this->cache = $cache;
    }

    public function put(string $key, string $value, int $ttl = 0): void
    {
        $ttl > 0 ? $this->cache->put($key, $value, $ttl) : $this->cache->forever($key, $value);
    }

    public function add(string $key, string $value, int $ttl = 0): bool
    {
        return (bool) $this->cache->add($key, $value, $ttl > 0 ? $ttl : null);
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key);
        return is_string($value) ? $value : null;
    }

    public function delete(string $key): void
    {
        $this->cache->forget($key);
    }
}
