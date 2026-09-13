<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

interface Viewer
{
    public function id(): ?string;

    public function isAuthenticated(): bool;

    public function hasRole(string $role): bool;

    public function can(string $capability): bool;
}
