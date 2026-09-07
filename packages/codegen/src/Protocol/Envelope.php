<?php

declare(strict_types=1);

namespace Eleph\Codegen\Protocol;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Signing\HeaderStyle;
use Eleph\Codegen\TargetRequest;
use Eleph\Codegen\TargetResponse;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Wire\IrCodec;
use Eleph\Schema\Wire\WireException;
use JsonException;

/**
 * What travels between the core and a builder.
 *
 * The envelope is the metadata *about* the payload: which protocol, which IR version,
 * which target, what it was configured with. The payload is `schema`. That separation
 * is the whole point — a builder reads `irVersion` and decides whether it can proceed
 * without ever having parsed an entity. Put the version inside the IR and a builder has
 * to parse the IR to discover whether it can parse the IR.
 *
 * **The envelope's own shape is frozen.** It may gain optional fields and nothing else.
 * It is what both sides must agree on before anything else can be negotiated, so it
 * cannot itself be negotiable. See docs/PLAN.md §15.
 */
final readonly class Envelope
{
    /**
     * The protocol version, as opposed to the IR's.
     *
     * These move for different reasons: the IR changes when the shape of a spec's
     * meaning changes, the envelope when the exchange itself does. Conflating them
     * would make every IR change look like a protocol break.
     */
    public const VERSION = 1;

    /**
     * The IR version this side of the exchange speaks.
     *
     * Declared here rather than read from the compiler, because the host does not have
     * a compiler: it forwards an IR it never decodes, and the only thing it can honestly
     * say is which version it was built to carry. `EnvelopeTest` asserts this still
     * matches `IrCodec::VERSION` while both live in one repository — after that, a
     * mismatch is what the gate below exists to catch.
     */
    public const IR_VERSION = '1.0';

    /**
     * Wrap an already-encoded IR for one target.
     *
     * The schema arrives encoded and leaves untouched. Nothing between the compiler and
     * the builder needs to understand an entity, and a host that decoded one would have
     * to be released every time the IR gained a field.
     *
     * @param array<string, mixed> $schema The IR as `IrCodec::encode()` left it.
     *
     * @return array<string, mixed>
     */
    public static function encodeRequest(string $target, TargetRequest $request, array $schema): array
    {
        return [
            'elephentity' => self::VERSION,
            'irVersion' => self::IR_VERSION,
            'target' => $target,
            'config' => (object) $request->config,
            'outputDirectory' => $request->outputDirectory,
            'schema' => $schema,
        ];
    }

    /**
     * Read a request, refusing anything this build cannot be sure it understands.
     *
     * @param array<string, mixed> $data
     */
    public static function decodeRequest(array $data): IncomingRequest
    {
        self::assertVersions($data);

        $target = $data['target'] ?? null;

        if (!is_string($target) || '' === $target) {
            throw new ProtocolException('The request names no target.');
        }

        $outputDirectory = $data['outputDirectory'] ?? null;

        if (!is_string($outputDirectory) || '' === $outputDirectory) {
            throw new ProtocolException('The request names no output directory.');
        }

        $config = $data['config'] ?? [];

        if (!is_array($config)) {
            throw new ProtocolException('"config" must be an object.');
        }

        $schema = $data['schema'] ?? null;

        if (!is_array($schema)) {
            throw new ProtocolException('The request carries no schema.');
        }

        try {
            /** @var array<string, mixed> $schema */
            $decoded = IrCodec::decode($schema);
        } catch (WireException $exception) {
            throw new ProtocolException('The schema is not readable: ' . $exception->getMessage(), 0, $exception);
        }

        /** @var array<string, mixed> $config */
        return new IncomingRequest($target, TargetRequest::of($outputDirectory, $config), $decoded);
    }

    /**
     * @return array<string, mixed>
     */
    public static function encodeResponse(TargetResponse $response): array
    {
        return [
            'elephentity' => self::VERSION,
            'irVersion' => self::IR_VERSION,
            'headerStyle' => $response->headerStyle->value,
            'extensions' => $response->extensions,
            'files' => array_map(
                static fn (GeneratedFile $file) => ['path' => $file->relativePath, 'body' => $file->body],
                $response->files,
            ),
            'errors' => $response->errors,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decodeResponse(array $data): TargetResponse
    {
        self::assertVersions($data);

        $style = $data['headerStyle'] ?? null;

        if (!is_string($style) || null === HeaderStyle::tryFrom($style)) {
            throw new ProtocolException(sprintf(
                'The response declares header style %s, which this build does not know.',
                is_string($style) ? '"' . $style . '"' : get_debug_type($style),
            ));
        }

        $headerStyle = HeaderStyle::from($style);
        $errors = self::stringList($data['errors'] ?? [], 'errors');

        if ([] !== $errors) {
            return TargetResponse::failed($errors, $headerStyle);
        }

        $files = $data['files'] ?? null;

        if (!is_array($files)) {
            throw new ProtocolException('The response carries no files.');
        }

        $generated = [];

        /** @var mixed $file */
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new ProtocolException('Every file must be an object with "path" and "body".');
            }

            $path = $file['path'] ?? null;
            $body = $file['body'] ?? null;

            if (!is_string($path) || '' === $path || !is_string($body)) {
                throw new ProtocolException('Every file must be an object with "path" and "body".');
            }

            if (str_contains($path, '..')) {
                // A builder is not allowed to name its way out of the directory it was
                // given. Nothing else in the pipeline would notice.
                throw new ProtocolException(sprintf('File path "%s" escapes the output directory.', $path));
            }

            $generated[] = new GeneratedFile($path, $body);
        }

        return TargetResponse::ok($generated, $headerStyle, self::stringList($data['extensions'] ?? [], 'extensions'));
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws JsonException
     */
    public static function toJson(array $json): string
    {
        return json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProtocolException('Not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new ProtocolException('Expected a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The gate, in both directions.
     *
     * A hard refusal rather than a warning: a builder that half-understands the IR
     * generates subtly wrong code, and the core then signs it. A signed file carrying
     * the framework's correctness guarantee, produced from a misread IR, is the worst
     * failure this system has. Not building is strictly better.
     *
     * @param array<string, mixed> $data
     */
    private static function assertVersions(array $data): void
    {
        $protocol = $data['elephentity'] ?? null;

        if (self::VERSION !== $protocol) {
            throw new ProtocolException(sprintf(
                'Protocol version mismatch: this build speaks %d, the other side speaks %s.',
                self::VERSION,
                is_scalar($protocol) ? (string) $protocol : get_debug_type($protocol),
            ));
        }

        $ir = $data['irVersion'] ?? null;

        if (self::IR_VERSION !== $ir) {
            throw new ProtocolException(sprintf(
                'IR version mismatch: this build emits %s, the other side speaks %s. '
                . 'There is no compatibility guarantee before 1.0; upgrade whichever side is behind.',
                self::IR_VERSION,
                is_scalar($ir) ? (string) $ir : get_debug_type($ir),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new ProtocolException(sprintf('"%s" must be a list of strings.', $field));
        }

        $strings = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new ProtocolException(sprintf('"%s" must be a list of strings.', $field));
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
