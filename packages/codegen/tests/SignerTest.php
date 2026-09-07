<?php

declare(strict_types=1);

namespace Eleph\Codegen\Tests;

use Eleph\Codegen\Signing\HeaderStyle;
use Eleph\Codegen\Signing\SignatureStatus;
use Eleph\Codegen\Signing\Signer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Signer::class)]
#[CoversClass(HeaderStyle::class)]
final class SignerTest extends TestCase
{
    private const BODY = "namespace App;\n\nfinal class Post\n{\n}\n";

    public function testThePhpHeaderOccupiesExactlyTheDeclaredNumberOfLines(): void
    {
        // The digest covers everything after the header, so an off-by-one here would
        // silently shift what is signed. This is the invariant that keeps the
        // no-sidecar-manifest decision safe.
        $signer = new Signer(HeaderStyle::Php);
        $signed = $signer->sign('Post.php', self::BODY);

        $lines = explode("\n", $signed);
        $header = array_slice($lines, 0, HeaderStyle::Php->lines());

        self::assertSame('<?php', $header[0]);
        self::assertSame('declare(strict_types=1);', $header[2]);
        self::assertSame('', $header[HeaderStyle::Php->lines() - 1]);
        self::assertSame(self::BODY, $signer->bodyOf($signed));
    }

    /**
     * The same invariant, for every style there is.
     *
     * A style whose rendered header is one line longer than it claims signs the wrong
     * bytes, and does so silently. Asserting it per case is what lets a new language be
     * added without re-deriving the reasoning.
     */
    public function testEveryHeaderStyleRendersExactlyTheLinesItClaims(): void
    {
        foreach (HeaderStyle::cases() as $style) {
            $header = $style->render('Post.src', 'sha256:abc');

            self::assertSame(
                $style->lines(),
                count(explode("\n", $header)) - 1,
                sprintf('%s renders a different number of lines than it declares.', $style->name),
            );

            $signer = new Signer($style);

            self::assertSame(
                self::BODY,
                $signer->bodyOf($signer->sign('Post.src', self::BODY)),
                sprintf('%s does not round-trip a body.', $style->name),
            );

            self::assertSame(
                SignatureStatus::Valid,
                $signer->verify('Post.src', $signer->sign('Post.src', self::BODY)),
                sprintf('%s does not verify its own signature.', $style->name),
            );
        }
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
