<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Verification;

/**
 * Writes the tree's `verify.php`: a ready verifier, not data for `eleph check` to
 * assemble one from.
 *
 * `__NAMESPACE__` rather than a hardcoded string, the same trick `ManifestExporter`
 * uses: this file lives in the namespace `WPGraphQLVerifier` lives in, so the exported
 * source can name it bare and the two can never drift out of the same directory.
 */
final readonly class VerifierExporter
{
    public function export(): string
    {
        return sprintf(
            <<<'PHP'
                namespace %s;

                /**
                 * eleph check loads this to prove the compiled manifest and the generated
                 * classes still agree. Built from the manifest already on disk beside it, so a
                 * stale copy of either fails here rather than at the first request.
                 *
                 * @var \Eleph\WPGraphQL\Manifest\Manifest $manifest
                 */
                $manifest = require __DIR__ . '/graphql-manifest.php';

                return new WPGraphQLVerifier($manifest);

                PHP,
            __NAMESPACE__,
        );
    }
}
