<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

/**
 * When the framework fills a field it owns.
 *
 * A mirror of the spec's own enum, for the same reason TriggerPhase is mirrored: the
 * runtime must not depend on the schema package, which is a build-time artefact and
 * does not ship. Generated code names the case and the two stay in step because one
 * generator writes both ends.
 */
enum Managed: string
{
    /** Stamped when the row is inserted, and never again. */
    case Created = 'created';

    /** Stamped on every write, insert included. */
    case Modified = 'modified';
}
