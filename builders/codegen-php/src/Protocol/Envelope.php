<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Protocol;

use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\TargetRequest;
use Eleph\Gen\Php\TargetResponse;
use Eleph\Gen\Php\Wire\IrCodec;
use Eleph\Gen\Php\Wire\WireException;
use JsonException;

/**
 * What travels between the orchestrator and this builder, from the builder's end.
 *
 * The envelope is the metadata *about* the payload: which protocol, which IR version,
 * which target, what it was configured with. The payload is `schema`. That separation
 * is the whole point — this reads `irVersion` and decides whether it can proceed
 * without ever having parsed an entity. Put the version inside the IR and a builder has
 * to parse the IR to discover whether it can parse the IR.
 *
 * Only half the exchange is here: this side reads requests and writes responses. The
 * other half belongs to `eleph-codegen`. That the two halves are separate
 * implementations of one documented format, rather than one shared library, is what
 * makes it a protocol — and it is why this repository depends on nothing of
 * Elephentity's. See PROTOCOL.md in elephentity-codegen.
 *
 * **The envelope's own shape is frozen.** It may gain optional fields and nothing else.
 * It is what both sides must agree on before anything else can be negotiated, so it
 * cannot itself be negotiable.
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
     * The IR version this builder speaks.
     *
     * Declared rather than derived. `IrCodec` in this repository is a copy of the
     * compiler's, and a copy that silently fell behind is exactly the failure this
     * constant exists to make loud: the orchestrator declares its own version too, and
     * `assertVersions()` refuses the exchange when they disagree.
     *
     * `EnvelopeTest` asserts this matches the local `IrCodec::VERSION`, which catches
     * the other direction — a codec updated here without the constant moving with it.
     */
    public const IR_VERSION = '1.2';

    /**
     * The two things this builder can be asked for.
     *
     * A discriminator rather than a second wire format: the exchange is identical
     * either way, one JSON object in and one out, with the versions outside the
     * payload. Absent means `generate`, which is what it meant before describe existed.
     */
    public const REQUEST_GENERATE = 'generate';

    public const REQUEST_DESCRIBE = 'describe';

    /**
     * Which of the two this request is.
     *
     * Read before anything else about the request, because a describe carries no schema
     * and no output directory — decoding it as a generate is exactly how a builder that
     * predates describe fails.
     *
     * @param array<string, mixed> $data
     */
    public static function requestKind(array $data): string
    {
        $kind = $data['request'] ?? self::REQUEST_GENERATE;

        if (self::REQUEST_GENERATE !== $kind && self::REQUEST_DESCRIBE !== $kind) {
            throw new ProtocolException(sprintf(
                'Unknown request "%s". This build answers "%s" and "%s".',
                is_scalar($kind) ? (string) $kind : get_debug_type($kind),
                self::REQUEST_GENERATE,
                self::REQUEST_DESCRIBE,
            ));
        }

        return $kind;
    }

    /**
     * What this builder provides: nothing.
     *
     * It generates PHP from the IR and knows no platform — no integration to declare,
     * no storage driver to claim. Answering emptily is still answering, and is what
     * separates "provides nothing" from "could not be asked".
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function describe(array $data): array
    {
        self::assertVersions($data);

        return [
            'elephentity' => self::VERSION,
            'irVersion' => self::IR_VERSION,
            'provides' => (object) [],
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
            'headerStyle' => $response->headerStyle,
            'extensions' => $response->extensions,
            'files' => array_map(
                static fn (GeneratedFile $file) => ['path' => $file->relativePath, 'body' => $file->body],
                $response->files,
            ),
            'errors' => $response->errors,
        ];
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
}
