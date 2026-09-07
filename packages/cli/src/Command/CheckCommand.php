<?php

declare(strict_types=1);

namespace Eleph\Cli\Command;

use Eleph\Cli\ClassMap;
use Eleph\Cli\Integrations;
use Eleph\Cli\ProjectConfig;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\WPGraphQL\Conformance\ConformanceChecker;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use RuntimeException;
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
    /**
     * Conformance is a statement about generated PHP classes, so it names the PHP
     * target specifically: a project generating only TypeScript has no classes for the
     * GraphQL surface to resolve against.
     */
    private const PHP_TARGET = 'php';

    protected function configure(): void
    {
        $this->addOption(
            'project',
            'p',
            InputOption::VALUE_REQUIRED,
            'Directory holding eleph.json.',
            '.',
        );
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

        $compiled = (new SchemaCompiler(integrations: Integrations::registry()))->compile(
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

        $php = $config->target(self::PHP_TARGET);

        if (null === $php) {
            $io->error(sprintf(
                'No "%s" target in %s; there are no generated classes to check against.',
                self::PHP_TARGET,
                ProjectConfig::FILENAME,
            ));

            return Command::FAILURE;
        }

        // The tree says what it contains. Asking the generator instead would mean this
        // gate could only run where the PHP generator is installed, which is exactly
        // the coupling a builder is meant not to have.
        try {
            $classes = ClassMap::load(rtrim($directory, '/') . '/' . $php->outputDirectory);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $classes->autoload();

        $problems = (new ConformanceChecker($manifest, $classes->classFor(...)))->check();

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
