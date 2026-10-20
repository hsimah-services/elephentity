<?php

declare(strict_types=1);

namespace Eleph\Tsquid;

final readonly class HtmlResponse
{
    /** @param array<string, string> $headers */
    public function __construct(public string $body, public array $headers = [])
    {
    }
}
