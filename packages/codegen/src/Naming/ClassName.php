<?php

declare(strict_types=1);

namespace PheFr\Codegen\Naming;

/**
 * A class name, split where every generator needs it split.
 *
 * Handles global-namespace classes too: generated code never lives there, but it
 * routinely references DateTimeImmutable, which does.
 */
final readonly class ClassName
{
    public string $namespace;

    public string $short;

    public function __construct(public string $fullyQualified)
    {
        $name = ltrim($fullyQualified, '\\');
        $separator = strrpos($name, '\\');

        if (false === $separator) {
            $this->namespace = '';
            $this->short = $name;

            return;
        }

        $this->namespace = substr($name, 0, $separator);
        $this->short = substr($name, $separator + 1);
    }
}
