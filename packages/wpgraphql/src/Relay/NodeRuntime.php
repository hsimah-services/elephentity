<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Relay;

use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\WPGraphQL\Manifest\Manifest;

/**
 * What `node(id: …)` needs, reachable from the two places WPGraphQL calls into.
 *
 * Static, and the only static in the package — not a preference. WPGraphQL builds a
 * data loader itself from a class name (`new $class($context)`), and resolves a node's
 * concrete type through a filter handed nothing but the object. Neither gives the
 * plugin a seam to pass the manifest and the gateway through, so they are bound once
 * at boot and read back here.
 *
 * Nothing else in the framework may reach for this. It is a shim over WPGraphQL's
 * wiring, not a service locator.
 */
final class NodeRuntime
{
    private static ?EntityGateway $gateway = null;

    /** @var array<string, string> GraphQL type name => entity name. */
    private static array $entities = [];

    /** @var array<class-string, string> Read model class => GraphQL type name. */
    private static array $types = [];

    public static function bind(Manifest $manifest, EntityGateway $gateway): void
    {
        self::$gateway = $gateway;
        self::$entities = [];

        foreach ($manifest->objects as $object) {
            self::$entities[$object->name] = $object->entity;
        }
    }

    /**
     * One node, from the key WPGraphQL split out of the global id.
     *
     * Null for anything unrecognised rather than an exception: `node` is typed
     * nullable, and a client guessing at ids should get nothing back rather than an
     * error naming the types that exist.
     */
    public static function load(string $key): ?object
    {
        $identifier = GlobalId::fromLoaderKey($key);
        $gateway = self::$gateway;

        if (null === $identifier || null === $gateway) {
            return null;
        }

        $entity = self::$entities[$identifier->type] ?? null;

        if (null === $entity) {
            return null;
        }

        $id = GlobalId::entityId($identifier->id);
        $node = null === $id ? null : $gateway->find($entity, $id);

        if (null !== $node) {
            // The answer to the resolveType question this same request is about to
            // ask. A read model carries no type name and the filter is handed nothing
            // else, so the one place that knows both records the pairing.
            self::$types[$node::class] = $identifier->type;
        }

        return $node;
    }

    /**
     * Which GraphQL type an object is, for `graphql_resolve_node_type`.
     *
     * Only objects that came back through load() are known, which is every object that
     * can reach an interface-typed position: the manifest declares concrete types
     * everywhere else, so `node` is the only field whose value has to be identified
     * after the fact.
     */
    public static function typeOf(object $node): ?string
    {
        return self::$types[$node::class] ?? null;
    }
}
