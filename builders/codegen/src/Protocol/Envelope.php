<?php

declare(strict_types=1);

namespace Eleph\Codegen\Protocol;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Signing\HeaderStyle;
use Eleph\Codegen\TargetRequest;
use Eleph\Codegen\TargetResponse;
use JsonException;

/**
 * What travels between the orchestrator and a builder, from the orchestrator's end.
 *
 * The envelope is the metadata *about* the payload: which protocol, which IR version,
 * which target, what it was configured with. The payload is `schema`. That separation
 * is the whole point — a builder reads `irVersion` and decides whether it can proceed
 * without ever having parsed an entity. Put the version inside the IR and a builder has
 * to parse the IR to discover whether it can parse the IR.
 *
 * Only half the exchange is here: this side writes requests and reads responses. The
 * other half — reading a request, writing a response — belongs to each builder, in
 * whatever language it is written. That the two halves are separate implementations of
 * one documented format, rather than one shared library, is what makes it a protocol.
 * See docs/PROTOCOL.md.
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
     * The IR version this build speaks.
     *
     * Declared rather than derived, because this program has no compiler: it forwards
     * an IR it never decodes, and the only thing it can honestly say is which version
     * it was built to carry. Elephentity declares the same version independently, and
     * `assertVersions()` is what turns a disagreement into a refusal — in both
     * directions, at both ends.
     */
    public const IR_VERSION = '1.2';

    /**
     * The two things a builder can be asked for.
     *
     * A discriminator rather than a second wire format, because everything else about
     * the exchange is identical: one JSON object in, one JSON object out, versions
     * outside the payload. `request` is optional and absent means `generate`, so a
     * builder written before describe existed still answers a generate request exactly
     * as it did — which is what "the envelope may gain optional fields and nothing
     * else" was reserving room for.
     */
    public const REQUEST_GENERATE = 'generate';

    public const REQUEST_DESCRIBE = 'describe';

    /**
     * Wrap an already-encoded IR for one target.
     *
     * The schema arrives encoded and leaves untouched. Nothing between the compiler and
     * the builder needs to understand an entity, and an orchestrator that decoded one
     * would need releasing every time the IR gained a field.
     *
     * @param array<string, mixed> $schema The IR, as the compiler encoded it.
     *
     * @return array<string, mixed>
     */
    public static function encodeRequest(string $target, TargetRequest $request, array $schema): array
    {
        return [
            'elephentity' => self::VERSION,
            'irVersion' => self::IR_VERSION,
            'request' => self::REQUEST_GENERATE,
            'target' => $target,
            'config' => (object) $request->config,
            'outputDirectory' => $request->outputDirectory,
            'schema' => $schema,
        ];
    }

    /**
     * Ask a builder what it provides, before there is anything to generate.
     *
     * Deliberately carries no schema and no config. A description is what the compiler
     * needs in order to *compile* — which integrations a spec may name, which drivers
     * are installed — so it has to be answerable before a spec has been read, and a
     * builder that needed the IR to answer would have made it unanswerable.
     *
     * @return array<string, mixed>
     */
    public static function encodeDescribeRequest(string $target): array
    {
        return [
            'elephentity' => self::VERSION,
            'irVersion' => self::IR_VERSION,
            'request' => self::REQUEST_DESCRIBE,
            'target' => $target,
        ];
    }

    /**
     * What a builder said it provides, carried and never read.
     *
     * The same discipline as the IR, for the same reason: this program forwards
     * `provides` to the compiler without knowing what an integration is, so a new kind
     * of thing a builder can provide needs a new compiler and a new builder and no
     * release of this. Validated only far enough to be sure it is an object — a
     * malformed one has to fail here rather than as a confusing error two hops later.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function decodeDescription(array $data): array
    {
        self::assertVersions($data);

        $provides = $data['provides'] ?? [];

        if (!is_array($provides)) {
            throw new ProtocolException('"provides" must be an object.');
        }

        /** @var array<string, mixed> $provides */
        return $provides;
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
     * The gate, in every direction.
     *
     * Public because it guards both exchanges this program takes part in: the request
     * arriving from the compiler and the response coming back from a builder. One gate
     * rather than two is the point — a second copy is a second thing to forget to
     * tighten.
     *
     * A hard refusal rather than a warning: a builder that half-understands the IR
     * generates subtly wrong code, and the core then signs it. A signed file carrying
     * the framework's correctness guarantee, produced from a misread IR, is the worst
     * failure this system has. Not building is strictly better.
     *
     * @param array<string, mixed> $data
     */
    public static function assertVersions(array $data): void
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
