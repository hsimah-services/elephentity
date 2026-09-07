<?php

declare(strict_types=1);

namespace Eleph\Schema\Wire;

/**
 * The builder side of the codegen protocol, for builders written in this repository.
 *
 * `eleph-gen-wordpress` and `eleph-gen-wpgraphql` are builders like any other — a JSON
 * object on stdin, one on stdout — and both need the same version gate and the same
 * dispatch. This is that, once. It sits beside `IrCodec` because it is the other half
 * of the same wire format, and in a package that never ships because nothing here runs
 * at request time.
 *
 * Deliberately not shared with `eleph-codegen` or `eleph-gen-php`. Each side of a
 * protocol declaring its own constants is what makes a disagreement detectable; three
 * programs importing one constant would be one program in three files. See
 * PROTOCOL.md in elephentity-codegen.
 */
final readonly class BuilderEnvelope
{
    public const VERSION = 1;

    public const REQUEST_GENERATE = 'generate';

    public const REQUEST_DESCRIBE = 'describe';

    /**
     * Which question this is.
     *
     * Read before anything else about the request: a describe carries no schema, so
     * decoding it as a generate is exactly how a builder that predates describe fails.
     *
     * @param array<string, mixed> $request
     */
    public static function kindOf(array $request): string
    {
        $kind = $request['request'] ?? self::REQUEST_GENERATE;

        if (self::REQUEST_GENERATE !== $kind && self::REQUEST_DESCRIBE !== $kind) {
            throw new WireException(sprintf(
                'Unknown request "%s". This builder answers "%s" and "%s".',
                is_scalar($kind) ? (string) $kind : get_debug_type($kind),
                self::REQUEST_GENERATE,
                self::REQUEST_DESCRIBE,
            ));
        }

        return $kind;
    }

    /**
     * The gate, on every exchange including a describe.
     *
     * A describe carries no schema and still must not be answered across a version
     * boundary: what it answers shapes the spec the other side is about to compile.
     *
     * @param array<string, mixed> $request
     */
    public static function assertVersions(array $request): void
    {
        $protocol = $request['elephentity'] ?? null;

        if (self::VERSION !== $protocol) {
            throw new WireException(sprintf(
                'Protocol version mismatch: this builder speaks %d, the other side speaks %s.',
                self::VERSION,
                is_scalar($protocol) ? (string) $protocol : get_debug_type($protocol),
            ));
        }

        $ir = $request['irVersion'] ?? null;

        if (IrCodec::VERSION !== $ir) {
            throw new WireException(sprintf(
                'IR version mismatch: this builder speaks %s, the other side speaks %s. '
                . 'There is no compatibility guarantee before 1.0; upgrade whichever side is behind.',
                IrCodec::VERSION,
                is_scalar($ir) ? (string) $ir : get_debug_type($ir),
            ));
        }
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    public static function schemaOf(array $request): array
    {
        $schema = $request['schema'] ?? null;

        if (!is_array($schema)) {
            throw new WireException('The request carries no schema.');
        }

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @param array<string, mixed> $provides
     *
     * @return array<string, mixed>
     */
    public static function description(array $provides): array
    {
        return [
            'elephentity' => self::VERSION,
            'irVersion' => IrCodec::VERSION,
            'provides' => (object) $provides,
        ];
    }

    /**
     * @param array<string, string> $files  Relative path => body, below the signed header.
     * @param list<string>          $errors Every problem found, not the first.
     *
     * @return array<string, mixed>
     */
    public static function response(array $files, array $errors = []): array
    {
        $encoded = [];

        foreach ($files as $path => $body) {
            $encoded[] = ['path' => $path, 'body' => $body];
        }

        return [
            'elephentity' => self::VERSION,
            'irVersion' => IrCodec::VERSION,
            // Both builders here emit PHP, which is the one thing they have in common
            // with each other and with the language generator whose tree they land in.
            'headerStyle' => 'php',
            'extensions' => ['php'],
            'files' => [] === $errors ? $encoded : [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(string $stdin): array
    {
        if ('' === trim($stdin)) {
            throw new WireException('Expected a request on stdin.');
        }

        $decoded = json_decode($stdin, true);

        if (!is_array($decoded)) {
            throw new WireException('Expected one JSON object on stdin.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function write(array $response): string
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES);

        if (false === $json) {
            throw new WireException('Could not encode the response: ' . json_last_error_msg());
        }

        return $json;
    }
}
