<?php

declare(strict_types=1);

namespace PheFr\Codegen\Naming;

use Nette\PhpGenerator\PhpNamespace;
use PheFr\Codegen\GeneratedFile;

/**
 * The mechanics every generator shares: open a namespace, print it, address the file.
 */
final readonly class Emitter
{
    public function __construct(
        private Names $names,
        private Printer $printer = new Printer(),
    ) {
    }

    public function open(string $fullyQualified): PhpNamespace
    {
        return new PhpNamespace((new ClassName($fullyQualified))->namespace);
    }

    public function shortName(string $fullyQualified): string
    {
        return (new ClassName($fullyQualified))->short;
    }

    public function file(string $fullyQualified, PhpNamespace $namespace): GeneratedFile
    {
        return new GeneratedFile(
            $this->names->pathFor($fullyQualified),
            $this->printer->print($namespace),
        );
    }
}
