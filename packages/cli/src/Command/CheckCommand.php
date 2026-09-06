<?php

declare(strict_types=1);

namespace PheFr\Cli\Command;

use Closure;
use PheFr\Cli\ProjectConfig;
use PheFr\Codegen\GeneratorConfig;
use PheFr\Codegen\Naming\Names;
use PheFr\Schema\SchemaCompiler;
use PheFr\Schema\SpecSource;
use PheFr\WPGraphQL\Conformance\ConformanceChecker;
use PheFr\WPGraphQL\Manifest\ManifestBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Proves the GraphQL surface and the generated classes still agree.
 *
 * Distinct from `generate --check`, which asks whether the tree matches the spec. This
 * asks whether the tree is *coherent*: every field the API exposes must resolve to a
 * method that exists. A convention only asserted in prose is one that eventually
 * breaks, so this is the gate that turns it into a build failure.
 */
#[AsCommand(
    name: 'check',
    description: 'Verify every exposed GraphQL field resolves to a method that exists.',
)]
final class CheckCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'project',
            'p',
            InputOption::VALUE_REQUIRED,
            'Directory holding phefr.json.',
            '.',
        );
    }

    /**
     * Load generated classes without depending on the project's autoloader.
     *
     * PheFr owns the layout of this tree — the namespace below the root mirrors the
     * directory exactly — so it can always find its own output. Relying on the
     * project's Composer configuration would make the gate silently pass whenever that
     * configuration was wrong, which is precisely when it should fail.
     */
    private function autoloadGenerated(string $root, string $directory): void
    {
        spl_autoload_register(static function (string $class) use ($root, $directory): void {
            if (!str_starts_with($class, $root . '\\')) {
                return;
            }

            $relative = substr($class, strlen($root) + 1);
            $path = rtrim($directory, '/') . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getOption('project');

        if (!is_string($directory)) {
            $io->error('--project must be a directory path.');

            return Command::INVALID;
        }

        $config = ProjectConfig::load($directory);

        $compiled = (new SchemaCompiler())->compile(
            new SpecSource(rtrim($directory, '/') . '/' . $config->specDirectory),
        );

        if (!$compiled->isSuccess()) {
            $io->error('The specs do not compile; fix them before checking conformance.');

            foreach ($compiled->errors as $error) {
                $io->writeln('  ' . $error->describe());
            }

            return Command::FAILURE;
        }

        $schema = $compiled->schema();
        $manifest = (new ManifestBuilder())->build($schema);

        $outputDirectory = rtrim($directory, '/') . '/' . $config->outputDirectory;

        $this->autoloadGenerated(trim($config->rootNamespace, '\\'), $outputDirectory);

        // Ask the generator where it put things rather than restating the convention:
        // a second copy of the layout rule is a second thing to forget to update.
        $names = new Names(new GeneratorConfig(
            $config->rootNamespace,
            $outputDirectory,
            $config->typeNamespace,
        ));

        $classFor = Closure::fromCallable(
            static function (string $entity) use ($names, $schema): string {
                $definition = $schema->entity($entity);

                return null === $definition ? $entity : $names->entity($definition);
            },
        );


        $problems = (new ConformanceChecker($manifest, $classFor))->check();

        if ([] !== $problems) {
            $io->error(sprintf('%d conformance problem(s).', count($problems)));

            foreach ($problems as $problem) {
                $io->writeln('  ' . $problem);
            }

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Conformant: %d type(s), every field resolves.',
            count($manifest->objects),
        ));

        return Command::SUCCESS;
    }
}
