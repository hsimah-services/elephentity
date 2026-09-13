<?php

declare(strict_types=1);

namespace Eleph\WordPress\Account;

/**
 * The narrow slice of WordPress's user API this adaptor uses.
 *
 * An interface rather than reaching for `get_userdata()` and friends directly, for the
 * same reason `Terms` exists: `AccountStorage` stays testable without a WordPress
 * installation, and everything WordPress-shaped about a user — `WP_User`, `WP_Error`,
 * `wp_users`/`wp_usermeta` themselves — is confined to one small, untested class.
 */
interface Users
{
    /**
     * Whether `wp_users` has a row with this id. An account-backed entity's existence
     * is this and nothing else — no row of the framework's own ever has to exist
     * first.
     */
    public function exists(int $wpUserId): bool;

    /**
     * `Y-m-d H:i:s`, WordPress's own format, or null if no such user exists.
     */
    public function registeredAt(int $wpUserId): ?string;

    /**
     * @param list<string> $keys
     *
     * @return array<string, scalar|null> Meta key => value. A key with no stored value
     *                                    is absent, not present-with-null — the caller
     *                                    decides what an unset field defaults to.
     */
    public function meta(int $wpUserId, array $keys): array;

    /**
     * @param array<int, list<string>> $keysByUser wp_users.ID => the meta keys wanted for it.
     *
     * @return array<int, array<string, scalar|null>> wp_users.ID => meta key => value.
     */
    public function metaForMany(array $keysByUser): array;

    public function updateMeta(int $wpUserId, string $key, string|int|float|bool|null $value): void;

    /**
     * Every user id, ordered, id-only — callers page and hydrate through `meta()` the
     * same as any other list.
     *
     * @return list<int>
     */
    public function list(?int $limit, int $offset): array;

    public function count(): int;
}
