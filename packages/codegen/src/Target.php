<?php

declare(strict_types=1);

namespace Eleph\Codegen;

use Eleph\Schema\Ir\Schema;

/**
 * One code generator, for one output language.
 *
 * A target turns the IR into files and nothing else. It never touches the filesystem:
 * it returns paths and bodies, and the caller signs and writes them. That keeps the
 * Signer the single authority on locking — the framework's actual claim — rather than
 * every target reimplementing it and one of them getting it subtly wrong. It also
 * means `generate --check` works for any target without the target knowing --check
 * exists.
 *
 * The shape mirrors the eventual out-of-process protocol deliberately: a request in, a
 * response out, no shared state and no callbacks. When a target becomes a subprocess
 * exchanging JSON, the only thing that changes is how the request reaches it. See
 * docs/PLAN.md §15.
 */
interface Target
{
    /**
     * The key this target is configured under, e.g. "php".
     */
    public function name(): string;

    public function generate(TargetRequest $request, Schema $schema): TargetResponse;
}
