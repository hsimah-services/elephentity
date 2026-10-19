<?php

declare(strict_types=1);

namespace Eleph\Gen\Php;

/**
 * What a target produces: files to sign, or the reasons it could not.
 *
 * Errors are a list rather than an exception because the convention everywhere else in
 * the pipeline is that problems accumulate and get reported together. A target that
 * gives up on the first bad config key makes the caller run the build once per
 * mistake.
 *
 * The header style travels with the response rather than being asked for separately,
 * so that one call yields everything needed to write the files — which is what the
 * protocol hands back as one JSON document.
 *
 * It is a plain string, and naming a style is as far as a builder goes: rendering the
 * header, and knowing how many lines it occupies, belongs to whatever signs the file.
 * A builder that rendered its own header would be a builder whose output nothing else
 * could verify.
 *
 * So do the extensions the target owns. Writing a tree means deleting what the schema
 * no longer produces, and a target that cannot say what is its to delete either leaves
 * stale files behind or reaches into another target's. Declaring it is also the safer
 * default when an output directory is misconfigured: a target that owns `php` cannot
 * delete someone's notes.
 */
final readonly class TargetResponse
{
    /**
     * @param list<GeneratedFile> $files
     * @param list<string>        $extensions File extensions this target owns, no dot.
     * @param list<string>        $errors
     */
    private function __construct(
        public array $files,
        public string $headerStyle,
        public array $extensions,
        public array $errors,
    ) {
    }

    /**
     * @param list<GeneratedFile> $files
     * @param list<string>        $extensions
     */
    public static function ok(array $files, string $headerStyle, array $extensions): self
    {
        return new self($files, $headerStyle, $extensions, []);
    }

    /**
     * A target that produced nothing owns nothing: there is no tree to sweep.
     *
     * The header style is optional here because a response with no files has no use for
     * one — an external builder that failed before it could say which style it speaks
     * would otherwise have to invent an answer. It stays non-null so that reading the
     * property never needs a branch.
     *
     * @param list<string> $errors
     */
    public static function failed(array $errors, ?string $headerStyle = null): self
    {
        return new self([], $headerStyle ?? PhpTarget::HEADER_STYLE, [], $errors);
    }

    public function isSuccess(): bool
    {
        return [] === $this->errors;
    }
}
