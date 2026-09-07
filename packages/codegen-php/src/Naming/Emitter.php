<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Naming;

use Eleph\Codegen\GeneratedFile;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpNamespace;

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

    /**
     * Seal a class behind a named constructor.
     *
     * `new Item(...)` next to `Item::of(...)` says nothing about which is intended;
     * one entry point does. It also matches the house style the runtime already uses —
     * `EntityId::of()`, `Verification::ok()`, `Cursor::of()` — so generated code reads
     * like the code it sits beside.
     *
     * Promoted properties still declare the shape, so the constructor stays; it simply
     * becomes private, and the factory mirrors its signature exactly.
     *
     * Applied to what generated code builds — entities and contexts — and deliberately
     * not to what a container builds. Mutators, finders, hydrators and bridges are
     * services, and every mainstream container autowires through a public constructor:
     * sealing those would trade a small gain in uniformity for an explicit service
     * definition per entity, forever.
     */
    public function namedConstructor(ClassType $type, Method $constructor, string $name = 'of'): void
    {
        $constructor->setPrivate();

        $factory = $type->addMethod($name)
            ->setPublic()
            ->setStatic()
            ->setReturnType('self');

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $copy = $factory->addParameter($parameter->getName())
                ->setType($parameter->getType())
                ->setNullable($parameter->isNullable());

            if ($parameter->hasDefaultValue()) {
                $copy->setDefaultValue($parameter->getDefaultValue());
            }

            $arguments[] = '$' . $parameter->getName();
        }

        $factory->setBody(sprintf(
            'return new self(%s);',
            implode(', ', $arguments),
        ));
    }

    public function file(string $fullyQualified, PhpNamespace $namespace): GeneratedFile
    {
        return new GeneratedFile(
            $this->names->pathFor($fullyQualified),
            $this->printer->print($namespace),
        );
    }
}
