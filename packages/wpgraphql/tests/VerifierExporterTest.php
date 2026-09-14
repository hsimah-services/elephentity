<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\WPGraphQL\Manifest\ManifestExporter;
use Eleph\WPGraphQL\Verification\VerifierExporter;
use Eleph\WPGraphQL\Verification\WPGraphQLVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The exported source is the thing `eleph check` actually loads, so this writes it to
 * disk beside a real manifest — exactly how the builder leaves them in the tree — and
 * requires it back, rather than asserting on the string.
 */
#[CoversClass(VerifierExporter::class)]
final class VerifierExporterTest extends TestCase
{
    public function testTheExportedSourceReturnsAWorkingVerifier(): void
    {
        $directory = sys_get_temp_dir() . '/eleph-verifier-export-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o775, true);

        file_put_contents(
            $directory . '/graphql-manifest.php',
            '<?php ' . (new ManifestExporter())->export(Fixture::manifest()),
        );
        file_put_contents(
            $directory . '/verify.php',
            '<?php ' . (new VerifierExporter())->export(),
        );

        $verifier = require $directory . '/verify.php';

        self::assertInstanceOf(WPGraphQLVerifier::class, $verifier);

        $signals = $verifier->verify(static fn (): string => ConformanceSubject::class);
        self::assertNotSame([], $signals);

        unlink($directory . '/graphql-manifest.php');
        unlink($directory . '/verify.php');
        rmdir($directory);
    }
}
