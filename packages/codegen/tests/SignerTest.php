<?php

declare(strict_types=1);

namespace PheFr\Codegen\Tests;

use PheFr\Codegen\Signing\SignatureStatus;
use PheFr\Codegen\Signing\Signer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Signer::class)]
final class SignerTest extends TestCase
{
    private const BODY = "namespace App;\n\nfinal class Post\n{\n}\n";

    public function testTheHeaderOccupiesExactlyTheDeclaredNumberOfLines(): void
    {
        // The digest covers everything after HEADER_LINES, so an off-by-one here would
        // silently shift what is signed. This is the invariant that keeps the
        // no-sidecar-manifest decision safe.
        $signed = (new Signer())->sign('Post.php', self::BODY);

        $lines = explode("\n", $signed);
        $header = array_slice($lines, 0, Signer::HEADER_LINES);

        self::assertSame('<?php', $header[0]);
        self::assertSame('declare(strict_types=1);', $header[2]);
        self::assertSame('', $header[Signer::HEADER_LINES - 1]);
        self::assertSame(self::BODY, Signer::bodyOf($signed));
    }

    public function testAFreshlySignedFileVerifies(): void
    {
        $signer = new Signer();

        self::assertSame(
            SignatureStatus::Valid,
            $signer->verify('Post.php', $signer->sign('Post.php', self::BODY)),
        );
    }

    public function testAnEditedFileIsDetected(): void
    {
        $signer = new Signer();
        $signed = $signer->sign('Post.php', self::BODY);

        $edited = str_replace('final class Post', 'final class Postt', $signed);

        self::assertSame(SignatureStatus::Tampered, $signer->verify('Post.php', $edited));
    }

    public function testEditingTheHeaderItselfIsDetected(): void
    {
        $signer = new Signer();
        $signed = $signer->sign('Post.php', self::BODY);

        // Blanking the digest leaves a file that no longer claims to be signed.
        $stripped = preg_replace('/^ \* digest: .*$/m', ' * digest:', $signed) ?? '';

        self::assertSame(SignatureStatus::Unsigned, $signer->verify('Post.php', $stripped));
    }

    public function testTheSameBodyAtADifferentPathDoesNotVerify(): void
    {
        // The path is hashed alongside the body, so copying a generated file elsewhere
        // fails rather than quietly passing.
        $signer = new Signer();
        $signed = $signer->sign('Post.php', self::BODY);

        self::assertSame(SignatureStatus::Tampered, $signer->verify('Other.php', $signed));
    }

    public function testAnUnsignedFileIsNotMistakenForAValidOne(): void
    {
        self::assertSame(
            SignatureStatus::Unsigned,
            (new Signer())->verify('Post.php', "<?php\n\nfinal class Post {}\n"),
        );
    }

    public function testSigningIsDeterministic(): void
    {
        $signer = new Signer();

        self::assertSame(
            $signer->sign('Post.php', self::BODY),
            $signer->sign('Post.php', self::BODY),
        );
    }
}
