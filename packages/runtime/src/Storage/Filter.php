<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

use InvalidArgumentException;

/**
 * One condition on a field. Filters within a criteria are conjunctive.
 */
final readonly class Filter
{
    /**
     * @param scalar|list<scalar>|null $value
     */
    public function __construct(
        public string $field,
        public Comparison $comparison,
        public string|int|float|bool|array|null $value = null,
    ) {
        if (!$comparison->takesValue() && null !== $value) {
            throw new InvalidArgumentException(sprintf(
                'The %s comparison takes no value.',
                $comparison->value,
            ));
        }
    }
}
