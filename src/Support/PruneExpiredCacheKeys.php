<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;

/**
 * Deletes the package's expired keys from a database or a file cache store.
 *
 * Both stores delete an expired key only when that key is read again. The receiver keeps a key for every delivery id
 * it handles while a source dedupes with `redis+db`, and the outbound rate limit a key for every minute a subscription
 * sends in, and almost none of them is read again, so on either store they stay for good: rows in the table of a
 * database store, files under the directory of a file store. Scheduled once a day, this deletes them, the rows a
 * thousand to a statement:
 *
 *     Schedule::call(PruneExpiredCacheKeys::class)->daily();
 *
 * It prunes the default store, where the receiver keeps its keys, and the limiter store, `cache.limiter`, where the
 * rate limits and the hold keep theirs, each once, and does nothing for a store that is neither a database nor a file
 * store. A file store names a file by the hash of its key, so there the expired file of any key goes, which the store
 * already treats as missing.
 *
 * Part of the public API, because a host schedules it in its own scheduler.
 */
final readonly class PruneExpiredCacheKeys
{
    /** The most rows one statement deletes, so a large table is pruned in short statements. */
    private const int CHUNK = 1000;

    public function __construct(
        private Repository $config,
        private ConnectionResolverInterface $connections,
    ) {}

    /** Delete every expired key of the package, and return how many rows or files went. */
    public function __invoke(): int
    {
        $deleted = 0;

        foreach ($this->stores() as $store) {
            if ($store['driver'] === 'file') {
                $deleted += $this->pruneFiles($store['path']);

                continue;
            }

            $deleted += $this->pruneRows($store['connection'], $store['table'], $store['prefix']);
        }

        return $deleted;
    }

    /**
     * The stores pruning can reach, among the default store and the limiter store, each once.
     *
     * Two names of one table, or the limiter store left to the default one, are pruned once.
     *
     * @return list<array{driver: 'database', table: string, connection: ?string, prefix: string}|array{driver: 'file', path: string}>
     */
    private function stores(): array
    {
        $stores = [];

        foreach ([$this->config->get('cache.default'), $this->config->get('cache.limiter')] as $name) {
            $store = $this->store($name);

            if ($store !== null && ! in_array($store, $stores, true)) {
                $stores[] = $store;
            }
        }

        return $stores;
    }

    /**
     * What pruning a store takes: for a database store its table, its connection and its key prefix, for a file store
     * its directory, and null for any other store and for a name no store is configured under.
     *
     * @param  mixed  $name  a store name as the cache config holds it, which a host may have set to any value
     * @return array{driver: 'database', table: string, connection: ?string, prefix: string}|array{driver: 'file', path: string}|null
     */
    private function store(mixed $name): ?array
    {
        $stores = $this->config->get('cache.stores');
        $store = is_array($stores) && is_string($name) ? ($stores[$name] ?? null) : null;

        if (! is_array($store)) {
            return null;
        }

        $driver = $store['driver'] ?? null;

        if ($driver === 'file') {
            $path = $store['path'] ?? null;

            if (! is_string($path)) {
                return null;
            }

            if ($path === '') {
                return null;
            }

            return ['driver' => 'file', 'path' => rtrim($path, '/')];
        }

        if ($driver !== 'database') {
            return null;
        }

        $table = $store['table'] ?? null;
        $connection = $store['connection'] ?? null;
        // As Laravel builds the store: its own prefix, or the one every store shares.
        $prefix = $store['prefix'] ?? $this->config->get('cache.prefix');

        if (! is_string($table) || $table === '') {
            return null;
        }

        return [
            'driver' => 'database',
            'table' => $table,
            'connection' => is_string($connection) && $connection !== '' ? $connection : null,
            'prefix' => is_string($prefix) ? $prefix : '',
        ];
    }

    /** Delete the expired rows of the package's keys from the table of a database store, and return how many went. */
    private function pruneRows(?string $connection, string $table, string $prefix): int
    {
        $pattern = $this->keysLike($prefix);
        $now = Date::now()->getTimestamp();
        $deleted = 0;

        do {
            $chunk = $this->connections->connection($connection)->table($table)
                ->where('key', 'like', $pattern)
                ->where('expiration', '<=', $now)
                ->limit(self::CHUNK)
                ->delete();

            $deleted += $chunk;
        } while ($chunk === self::CHUNK);

        return $deleted;
    }

    /**
     * Delete every expired file of a file store, and return how many went.
     *
     * The store keeps a key in a file named by its hash, two directories below its own, and writes the moment it
     * expires as the first ten characters. A key that never expires carries 9999999999 and stays.
     */
    private function pruneFiles(string $directory): int
    {
        $now = Date::now()->getTimestamp();
        $deleted = 0;

        foreach (glob($directory.'/*/*/*', GLOB_NOSORT) ?: [] as $path) {
            $expiration = is_file($path) ? (string) file_get_contents($path, length: 10) : '';

            if (preg_match('/\A\d{10}\z/', $expiration) === 1 && (int) $expiration <= $now && unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * The package's keys as a LIKE pattern.
     *
     * A character of the prefix that LIKE reads as more than itself on one of the engines, `%`, `\` and `[`, stands
     * as `_`, any one character, so the pattern means the same on every engine without an escape clause. It can then
     * match a key beside these, and so can a LIKE that ignores case, as SQLite's does and MySQL's under its default
     * collation. Such a key goes only once it has expired, and the store already treats it as missing then.
     */
    private function keysLike(string $prefix): string
    {
        return str_replace(['%', '\\', '['], '_', $prefix).'webhooks:%';
    }
}
