<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

final readonly class AnonymousViewer implements Viewer
{
    public function id(): ?string
    {
        return null;
    }

    public function isAuthenticated(): bool
    {
        return false;
    }

    public function hasRole(string $role): bool
    {
        return false;
    }

    public function can(string $capability): bool
    {
        return false;
    }
}
