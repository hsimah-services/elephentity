<?php

declare(strict_types=1);

namespace Eleph\Codegen\Command;

use Eleph\Codegen\Config\ProjectConfig;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Answers what a project generates, as JSON.
 *
 * The `targets` block of eleph.json is read here and nowhere else. Other tools need
 * parts of it — `eleph check` has to know which directory the PHP tree landed in — and
 * the alternative is each of them parsing the file again and disagreeing about, say,
 * whether a trailing slash on `output` matters. One parser, asked over a pipe.
 *
 * Machine output, so it goes to stdout unadorned and nothing else does.
 */
#[AsCommand(
    name: 'targets',
    description: 'Print the configured targets as JSON.',
)]
final class TargetsCommand extends Command
{
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
        $directory = $input->getOption('project');

        if (!is_string($directory)) {
            $output->writeln('--project must be a directory path.');

            return Command::INVALID;
        }

        $config = ProjectConfig::load($directory);
        $targets = [];

        foreach ($config->targets as $name => $target) {
            $targets[$name] = [
                'output' => $target->outputDirectory,
                'builder' => $target->builder,
            ];
        }

        try {
            $json = json_encode(
                ['spec' => $config->specDirectory, 'targets' => (object) $targets],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            $output->writeln('Could not encode the targets: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        $output->writeln($json);

        return Command::SUCCESS;
    }
}
