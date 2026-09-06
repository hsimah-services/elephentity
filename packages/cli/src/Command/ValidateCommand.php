<?php

declare(strict_types=1);

namespace Eleph\Cli\Command;

use Eleph\Cli\Integrations;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $root = $input->getArgument('spec');

        if (!is_string($root)) {
            $io->error('The spec argument must be a directory path.');

            return Command::INVALID;
        }

        if (!is_dir($root)) {
            $io->error(sprintf('No such directory: %s', $root));

            return Command::INVALID;
        }

        $result = (new SchemaCompiler(integrations: Integrations::registry()))->compile(new SpecSource($root));

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

        return Command::SUCCESS;
    }
}
