<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\WordPress\Account\Users;

/**
 * An in-memory `wp_users`/`wp_usermeta`, standing in for a WordPress installation.
 *
 * `AccountStorage` is the thing worth testing, so this fake is deliberately dumb: two
 * arrays, no WordPress semantics beyond what `AccountStorage` itself relies on.
 */
final class FakeUsers implements Users
{
    /** @var array<int, string> wp_users.ID => user_registered */
    public array $registered = [];

    /** @var array<int, array<string, scalar|null>> wp_users.ID => meta key => value */
    public array $meta = [];

    public function exists(int $wpUserId): bool
    {
        return isset($this->registered[$wpUserId]);
    }

    public function registeredAt(int $wpUserId): ?string
    {
        return $this->registered[$wpUserId] ?? null;
    }

    public function meta(int $wpUserId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->meta[$wpUserId] ?? [])) {
                $values[$key] = $this->meta[$wpUserId][$key];
            }
        }

        return $values;
    }

    public function metaForMany(array $keysByUser): array
    {
        $values = [];

        foreach ($keysByUser as $wpUserId => $keys) {
            $values[$wpUserId] = $this->meta($wpUserId, $keys);
        }

        return $values;
    }

    public function updateMeta(int $wpUserId, string $key, string|int|float|bool|null $value): void
    {
        $this->meta[$wpUserId][$key] = $value;
    }

    public function list(?int $limit, int $offset): array
    {
        $ids = array_keys($this->registered);
        sort($ids);

        return array_values(array_slice($ids, $offset, $limit));
    }

    public function count(): int
    {
        return count($this->registered);
    }
}
