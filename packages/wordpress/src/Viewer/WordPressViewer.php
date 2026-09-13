<?php

declare(strict_types=1);

namespace Eleph\WordPress\Viewer;

use Eleph\Runtime\Policy\Viewer;
use WP_User;

final readonly class WordPressViewer implements Viewer
{
    public function __construct(private WP_User $user)
    {
    }

    public function id(): ?string
    {
        return 0 === (int) $this->user->ID ? null : (string) $this->user->ID;
    }

    public function isAuthenticated(): bool
    {
        return null !== $this->id();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->user->roles, true);
    }

    public function can(string $capability): bool
    {
        return user_can($this->user, $capability);
    }
}
