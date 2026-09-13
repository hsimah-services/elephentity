<?php

declare(strict_types=1);

namespace Eleph\WordPress\Account;

/**
 * Which of an account-backed entity's fields are ordinary usermeta, and which are the
 * two the framework stamps itself.
 *
 * `Managed::Created` never reaches storage — the unit of work only stamps it on
 * insert, and this package refuses to insert an account — so it is always answered
 * from `wp_users.user_registered`, the one creation timestamp that is guaranteed to
 * exist for a row this framework did not write. `Managed::Modified` is stamped on
 * every write, insert included, so it arrives on every update like any other value;
 * it is usermeta once written, and falls back to `user_registered` before the first
 * one, so a non-nullable field never sees a null this class could have avoided.
 */
final readonly class AccountFields
{
    public function __construct(
        public string $entity,
        public ?string $createdField,
        public ?string $modifiedField,
    ) {
    }
}
