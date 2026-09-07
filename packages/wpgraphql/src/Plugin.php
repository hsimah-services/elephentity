<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL;

use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Registration\MutationRegistrar;
use Eleph\WPGraphQL\Registration\TypeRegistrar;
use RuntimeException;

/**
 * The whole GraphQL layer, as one object a WordPress plugin can hold.
 *
 * Nothing in Elephentity depends on this. A project that speaks no GraphQL never
 * enables the integration, never generates a manifest, and never loads this class —
 * which is the point of it being a plugin layer rather than a feature.
 *
 * Registration is a loop over the manifest, not generated code. WPGraphQL takes a
 * closure for every resolver, so there is nothing a generator would do better here,
 * and a generated copy per entity would be one more tree to keep in step with the spec.
 *
 * A whole WordPress plugin then reads:
 *
 *     add_action('plugins_loaded', static function (): void {
 *         Plugin::fromManifest(__DIR__ . '/generated/graphql-manifest.php', $gateway)->boot();
 *     });
 */
final readonly class Plugin
{
    public function __construct(
        private Manifest $manifest,
        private EntityGateway $gateway,
        /**
         * Needed only by a project with declared value types: Money is a Money on the
         * entity and an Int over the wire, and the write processor is what already
         * knows how to get from one to the other.
         */
        private ?ProcessorRegistry $processors = null,
    ) {
    }

    /**
     * Load the compiled manifest from disk.
     *
     * The file is generated PHP that rebuilds the object, so this is an include rather
     * than a parse — opcache holds it and nothing is worked out per request.
     */
    public static function fromManifest(
        string $path,
        EntityGateway $gateway,
        ?ProcessorRegistry $processors = null,
    ): self {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No GraphQL manifest at %s. Run `eleph generate`, and check the project enables the wpgraphql integration.',
                $path,
            ));
        }

        /** @var mixed $manifest */
        $manifest = require $path;

        if (!$manifest instanceof Manifest) {
            throw new RuntimeException(sprintf('%s did not return a Manifest.', $path));
        }

        return new self($manifest, $gateway, $processors);
    }

    /**
     * Hook everything up. Call once, on `plugins_loaded` or earlier.
     */
    public function boot(): void
    {
        add_action('graphql_register_types', $this->register(...));
    }

    /**
     * Registration itself, separated from the hook so it can be driven directly.
     */
    public function register(): void
    {
        (new TypeRegistrar($this->manifest, $this->gateway, processors: $this->processors))->register();
        (new MutationRegistrar($this->manifest, $this->gateway))->register();
    }
}
