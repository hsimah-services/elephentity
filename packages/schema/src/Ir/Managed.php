<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * A field the framework fills, and when.
 *
 * Timestamps are the case that forced it. `createdAt` declared `required: true` made
 * every API client invent a creation time, and `immutable: true` then meant it could
 * never be corrected — the constraint enforced in the one place it should not be, on
 * a value no caller is in a position to know.
 *
 * A managed field is settable by nobody: no mutator setter, no input applier branch,
 * and absent from the generated create and update inputs. The unit of work stamps it
 * before verification, so a `required` check downstream sees a value that is really
 * there rather than one that will be.
 *
 * Deliberately a closed set rather than a `default: now` expression. The framework has
 * to *implement* each policy, and a value the spec can name but the runtime cannot
 * produce is the exact failure this enum exists to stop repeating.
 */
enum Managed: string
{
    /** Stamped when the row is inserted, and never again. */
    case Created = 'created';

    /** Stamped on every write, insert included. */
    case Modified = 'modified';
}
