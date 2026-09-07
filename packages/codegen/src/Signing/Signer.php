<?php

declare(strict_types=1);

namespace Eleph\Codegen\Signing;

/**
 * Stamps generated files with a digest of their own content, and checks it later.
 *
 * The header is a fixed number of lines and the digest covers everything after it — a
 * file cannot hash its own hash, so the header is excluded by position rather than by
 * pattern matching. `HeaderStyle` owns that line count so no verifier can disagree
 * about where the body starts.
 *
 * The style is per target rather than global: a `//` comment in TypeScript will not
 * land on the same line as PHP's open tag and strict_types. One signer instance
 * therefore speaks exactly one style, and the target that produced a file is what
 * decides which.
 *
 * The path is hashed alongside the body, so a generated file copied to another
 * location fails verification rather than quietly passing.
 *
 * Deliberately not a sidecar manifest. That gives up hash-based detection of files
 * added to or deleted from the generated tree, but `generate --check` regenerates and
 * diffs the whole tree, which catches both.
 */
final readonly class Signer
{
    private const ALGORITHM = 'sha256';

    public function __construct(private HeaderStyle $style = HeaderStyle::Php)
    {
    }

    public function sign(string $relativePath, string $body): string
    {
        return $this->style->render($relativePath, $this->digest($relativePath, $body)) . $body;
    }

    public function verify(string $relativePath, string $contents): SignatureStatus
    {
        $declared = $this->declaredDigest($contents);

        if (null === $declared) {
            return SignatureStatus::Unsigned;
        }

        $body = $this->bodyOf($contents);

        return hash_equals($this->digest($relativePath, $body), $declared)
            ? SignatureStatus::Valid
            : SignatureStatus::Tampered;
    }

    /**
     * Everything the digest covers: the file from the first line after the header.
     */
    public function bodyOf(string $contents): string
    {
        $lines = explode("\n", $contents);

        if (count($lines) <= $this->style->lines()) {
            return '';
        }

        return implode("\n", array_slice($lines, $this->style->lines()));
    }

    private function digest(string $relativePath, string $body): string
    {
        return self::ALGORITHM . ':' . hash(self::ALGORITHM, $relativePath . "\n" . $body);
    }

    private function declaredDigest(string $contents): ?string
    {
        $header = implode("\n", array_slice(explode("\n", $contents), 0, $this->style->lines()));

        if (1 !== preg_match($this->style->digestPattern(), $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
