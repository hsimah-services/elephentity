<?php

declare(strict_types=1);

namespace Eleph\WordPress\Account;

use WP_User;
use WP_User_Query;

/**
 * The only class that calls `get_userdata()` and its siblings.
 *
 * As thin as `WordPressTerms`: argument mapping and nothing a test without a
 * WordPress installation could usefully exercise. `AccountStorage` is where the
 * actual decisions live.
 */
final readonly class WordPressUsers implements Users
{
    public function exists(int $wpUserId): bool
    {
        return false !== get_userdata($wpUserId);
    }

    public function registeredAt(int $wpUserId): ?string
    {
        $user = get_userdata($wpUserId);

        return $user instanceof WP_User ? $user->user_registered : null;
    }

    public function meta(int $wpUserId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if (metadata_exists('user', $wpUserId, $key)) {
                /** @var scalar|null $value */
                $value = get_user_meta($wpUserId, $key, true);
                $values[$key] = $value;
            }
        }

        return $values;
    }

    public function metaForMany(array $keysByUser): array
    {
        if ([] === $keysByUser) {
            return [];
        }

        // Warms WordPress's own object cache for every user in one round trip;
        // meta() below then costs nothing per user, the same batching intent
        // Terms::termsOfMany() serves for taxonomies.
        update_meta_cache('user', array_keys($keysByUser));

        $values = [];

        foreach ($keysByUser as $wpUserId => $keys) {
            $values[$wpUserId] = $this->meta($wpUserId, $keys);
        }

        return $values;
    }

    public function updateMeta(int $wpUserId, string $key, string|int|float|bool|null $value): void
    {
        update_user_meta($wpUserId, $key, $value);
    }

    public function list(?int $limit, int $offset): array
    {
        $args = [
            'fields' => 'ID',
            'orderby' => 'ID',
            'order' => 'ASC',
            'offset' => $offset,
        ];

        if (null !== $limit) {
            $args['number'] = $limit;
        }

        /** @var list<int|numeric-string> $ids */
        $ids = (new WP_User_Query($args))->get_results();

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }

    public function count(): int
    {
        $counts = count_users();

        return (int) $counts['total_users'];
    }
}
