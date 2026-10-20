<?php

declare(strict_types=1);

namespace Eleph\Codegen\Command;

use Eleph\Codegen\Builders;
use Eleph\Codegen\Config\ProjectConfig;
use Eleph\Codegen\Protocol\Envelope;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Says whether this project could generate, without generating.
 *
 * Builders are resolved and never fetched, so the two ways a working setup breaks are
 * a builder that was never installed and one that is installed but not executable.
 * Both are cheap to check and neither needs a compiled spec, which makes this the first
 * thing to run when `generate` reports something confusing — and the only diagnostic
 * available at all when the compiler itself will not start.
 *
 * It also prints the versions this build speaks, because the other common failure is a
 * builder and an orchestrator that were installed months apart.
 */
#[AsCommand(
    name: 'doctor',
    description: 'Check that every configured builder is installed and runnable.',
)]
final class DoctorCommand extends Command
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
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getOption('project');

        if (!is_string($directory)) {
            $io->error('--project must be a directory path.');

            return Command::INVALID;
        }

        $io->writeln(sprintf(
            'eleph-codegen speaks protocol %d, IR %s.',
            Envelope::VERSION,
            Envelope::IR_VERSION,
        ));

        $config = ProjectConfig::load($directory);
        $builders = new Builders(rtrim($directory, '/'), $config->buildersDirectory);
        $problems = [];

        foreach ($config->targets as $name => $target) {
            try {
                $command = $builders->resolve($target);
            } catch (RuntimeException $exception) {
                $problems[] = $exception->getMessage();
                $io->writeln(sprintf('  <error>%s</error> → %s: not usable', $name, $target->builder));

                continue;
            }

            $io->writeln(sprintf(
                '  <info>%s</info> → %s (writes %s)',
                $name,
                $command[0],
                $target->outputDirectory,
            ));
        }

        if ([] !== $problems) {
            $io->error(sprintf('%d of %d target(s) cannot run.', count($problems), count($config->targets)));

            foreach ($problems as $problem) {
                $io->writeln('  ' . $problem);
            }

            return Command::FAILURE;
        }

        $io->success(sprintf('%d target(s) ready.', count($config->targets)));

        return Command::SUCCESS;
    }
}
