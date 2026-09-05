<?php

declare(strict_types=1);

namespace PheFr\Codegen\Naming;

use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;

/**
 * Renders a namespace to the body of a generated file.
 *
 * Only the body: the open tag, strict_types declaration and signed header are the
 * signer's, so that exactly one place decides how many lines precede the digest.
 */
final readonly class Printer
{
    public function print(PhpNamespace $namespace): string
    {
        $printer = new PsrPrinter();

        return rtrim($printer->printNamespace($namespace), "\n") . "\n";
    }
}
