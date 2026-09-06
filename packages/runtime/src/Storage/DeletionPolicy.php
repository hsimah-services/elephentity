<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

/**
 * What happens to the rows that depend on one being deleted.
 *
 * "Depend" means holding the foreign key, which is the same rule SQL uses. For
 * `Post.comments` the comments hold `post_id`, so deleting a Post applies this policy
 * to the comments. For `Inventory.item` the inventory row holds `item_id`, so deleting
 * an Item applies it to the inventory rows.
 *
 * Mirrors the spec's `onDelete` rather than importing it: the runtime does not depend
 * on the schema package, and generated code maps between them.
 */
enum DeletionPolicy: string
{
    /** Refuse the delete while anything still depends on the row. */
    case Restrict = 'restrict';

    /** Delete the dependents too, applying their own policies in turn. */
    case Cascade = 'cascade';

    /** Keep the dependents and clear their reference. */
    case Nullify = 'nullify';
}
