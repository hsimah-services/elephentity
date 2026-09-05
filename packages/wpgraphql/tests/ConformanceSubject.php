<?php

declare(strict_types=1);

namespace PheFr\WPGraphQL\Tests;

/**
 * A stand-in entity that is missing accessors on purpose, so the conformance check
 * has something to fail against.
 */
final class ConformanceSubject
{
    public function getId(): string
    {
        return '1';
    }

    public function getTitle(): string
    {
        return 'Hello';
    }
}
