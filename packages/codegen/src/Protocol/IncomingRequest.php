<?php

declare(strict_types=1);

namespace Eleph\Codegen\Protocol;

use Eleph\Codegen\TargetRequest;
use Eleph\Schema\Ir\Schema;

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
