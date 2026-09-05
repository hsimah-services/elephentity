<?php

declare(strict_types=1);

namespace PheFr\Runtime\Capability;

/**
 * Something a storage adaptor may or may not be able to do.
 *
 * Backends differ genuinely, and flattening the port to a lowest common denominator
 * would make every adaptor as weak as the weakest. Adaptors declare instead, and the
 * layers above require what they need — so an unsupported combination fails at boot
 * with a clear reason rather than misbehaving at runtime.
 */
enum Capability: string
{
    /** Writes can be grouped so that all of them apply or none do. */
    case Transactions = 'transactions';

    /** Text can be searched by relevance rather than by exact or prefix match. */
    case FullTextSearch = 'fullTextSearch';

    /** Result sets can be counted and narrowed by facet without a second round trip. */
    case Faceting = 'faceting';

    /** Rows can be locked for update within a transaction. */
    case RowLocking = 'rowLocking';

    /** The backend enforces referential integrity itself. */
    case ForeignKeys = 'foreignKeys';
}
