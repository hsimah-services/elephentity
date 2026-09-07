<?php

declare(strict_types=1);

namespace Eleph\Cli\Command;

use Eleph\Cli\ClassMap;
use Eleph\Cli\Codegen;
use Eleph\Cli\Installed;
use Eleph\Cli\ProjectConfig;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\WPGraphQL\Conformance\ConformanceChecker;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use JsonException;
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

    /**
     * Where the PHP target writes, according to the program that writes it.
     *
     * @throws RuntimeException When the generator cannot be found or cannot answer.
     */
    private function outputDirectory(string $root, ProjectConfig $config): ?string
    {
        $json = (new Codegen($root, $config->codegen))->capture(['targets', '--project', $root]);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf(
                'The generator did not describe its targets: %s',
                $exception->getMessage(),
            ));
        }

        $targets = is_array($decoded) ? ($decoded['targets'] ?? null) : null;
        $php = is_array($targets) ? ($targets[self::PHP_TARGET] ?? null) : null;
        $output = is_array($php) ? ($php['output'] ?? null) : null;

        if (null !== $php && (!is_string($output) || '' === $output)) {
            throw new RuntimeException(sprintf(
                'The generator described the "%s" target without an output directory.',
                self::PHP_TARGET,
            ));
        }

        return is_string($output) ? $output : null;
    }

    /**
     * What the project's builders provide, so the specs are read against the same
     * declarations `generate` reads them against.
     *
     * @throws RuntimeException When the generator cannot be found or cannot answer.
     */
    private function installed(string $root, ProjectConfig $config): Installed
    {
        return Installed::describedBy(new Codegen($root, $config->codegen), $root);
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
        $root = rtrim($directory, '/');

        $compiled = (new SchemaCompiler(integrations: $this->installed($root, $config)->integrations))->compile(
            new SpecSource($root . '/' . $config->specDirectory),
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

        // The generator parses the targets block, so it is asked rather than second
        // guessed. Two parsers of one file eventually disagree about something small,
        // and the disagreement surfaces as a gate checking the wrong directory.
        try {
            $outputDirectory = $this->outputDirectory($root, $config);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (null === $outputDirectory) {
            $io->error(sprintf(
                'No "%s" target in %s; there are no generated classes to check against.',
                self::PHP_TARGET,
                ProjectConfig::FILENAME,
            ));

            return Command::FAILURE;
        }

        // The tree says what it contains. Asking the generator for that too would mean
        // this gate could only run where the PHP builder is installed, which is exactly
        // the coupling a builder is meant not to have.
        try {
            $classes = ClassMap::load($root . '/' . $outputDirectory);
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
