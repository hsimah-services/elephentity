<?php

declare(strict_types=1);

namespace Eleph\Runtime\Conformance;

use Closure;

/**
 * What `eleph check` asks an integration: is the generated surface coherent?
 *
 * "Every field the API exposes resolves to a method that exists" is a question about
 * *an integration's* surface, not about GraphQL or any other integration specifically,
 * and the integration is the only thing that knows how far the question goes — so it
 * ships the verifier rather than answering to one core asks.
 *
 * A verifier is handed a class lookup, not the IR and not a project directory: `eleph
 * check` stays offline, and a verifier compares generated artefacts against each other
 * rather than rebuilding one from the spec to compare against the other, which is the
 * bug class unique to this gate. Whatever else a verifier needs — its own compiled
 * manifest, say — it is its `generated/<integration>/verify.php` that loads it, since
 * that file already lives beside it in the tree.
 */
interface Verifier
{
    /**
     * @param Closure(string): string $classFor Entity name => fully-qualified class,
     *                                           or the entity name itself when the tree
     *                                           never generated one.
     *
     * @return list<Signal>
     */
    public function verify(Closure $classFor): array;
}
