<?php

declare(strict_types=1);

namespace Eleph\Cli\Command;

use Eleph\Cli\Codegen;
use Eleph\Cli\Installed;
use Eleph\Cli\ProjectConfig;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gate one of the build: are the specs well-formed and semantically closed?
 *
 * Every problem is reported in a single run rather than one per invocation, so a spec
 * with five mistakes takes one pass to fix rather than five.
 */
#[AsCommand(
    name: 'validate',
    description: 'Check that the specs are well-formed and semantically closed.',
)]
final class ValidateCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'spec',
            InputArgument::OPTIONAL,
            'Directory holding entities/, patterns/ and types/.',
            'spec',
        );

        // Needed because what a spec may say depends on what is installed: the
        // integrations it may name are declared by the project's builders, and finding
        // those means finding eleph.json.
        $this->addOption(
            'project',
            'p',
            InputOption::VALUE_REQUIRED,
            'Directory holding eleph.json.',
            '.',
        );
        $this->addOption(
            'provides',
            null,
            InputOption::VALUE_REQUIRED,
            'Saved eleph-codegen describe JSON; validate without invoking builders or reading eleph.json.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $root = $input->getArgument('spec');
        $project = $input->getOption('project');
        $provides = $input->getOption('provides');

        if (!is_string($project)) {
            $io->error('--project must be a directory path.');

            return Command::INVALID;
        }

        if (!is_string($root)) {
            $io->error('The spec argument must be a directory path.');

            return Command::INVALID;
        }

        if (!is_dir($root)) {
            $io->error(sprintf('No such directory: %s', $root));

            return Command::INVALID;
        }

        try {
            if (null === $provides) {
                // Live discovery remains the default: the installed builders own the
                // integrations, pooled specs and storage rules validation uses.
                $installed = Installed::describedBy(
                    new Codegen($project, ProjectConfig::load($project)->codegen),
                    $project,
                );
            } else {
                if (!is_string($provides) || !is_file($provides)) {
                    throw new RuntimeException('--provides must name a readable describe JSON file.');
                }

                $json = @file_get_contents($provides);

                if (false === $json) {
                    throw new RuntimeException(sprintf('Cannot read %s.', $provides));
                }

                $installed = Installed::fromJson($json);
            }
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());
            if (null === $provides) {
                $io->writeln('Point --project at the directory holding eleph.json, or use --provides with saved describe JSON.');
            }

            return Command::FAILURE;
        }

        $result = (new SchemaCompiler(
            integrations: $installed->integrations,
            pooledPatterns: $installed->patterns,
            pooledTypes: $installed->types,
            storageRules: $installed->storageRules,
        ))->compile(new SpecSource($root));

        if (!$result->isSuccess()) {
            $io->error(sprintf('%d problem(s) found in %s', count($result->errors), $root));

            foreach ($result->errors as $error) {
                $io->writeln('  ' . $error->describe());
            }

            return Command::FAILURE;
        }

        $schema = $result->schema();

        $io->success(sprintf(
            'Specs are valid: %d entit%s, %d type%s.',
            count($schema->entities),
            1 === count($schema->entities) ? 'y' : 'ies',
            count($schema->types),
            1 === count($schema->types) ? '' : 's',
        ));

        if (null !== $provides) {
            $io->writeln('Validated against supplied metadata; installed builder compatibility was not checked.');
        }

        return Command::SUCCESS;
    }
}
