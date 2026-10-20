<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Protocol;

use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\TargetRequest;

/**
 * A decoded request, as a builder sees it.
 *
 * The mirror image of what the core sends: the schema lifted back out of the envelope
 * and reunited with the request it arrived in.
 */
final readonly class IncomingRequest
{
    public function __construct(
        public string $target,
        public TargetRequest $request,
        public Schema $schema,
    ) {
    }
}
