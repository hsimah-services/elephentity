<?php

declare(strict_types=1);

namespace PheFr\Cli\Command;

use PheFr\Cli\ProjectConfig;
use PheFr\Schema\Spec\FormatChecker;
use PheFr\Schema\SpecSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks that specs are written in canonical form.
 *
 * The spec is the entity's changelog, so a diff should show what changed and nothing
 * else. Without a fixed key order, the same field written by two authors — or the same
 * LLM on two different days — produces diff noise that buries the real change.
 *
 * This reports rather than rewrites: PHP's YAML parsers discard comments, so a
 * parse-and-dump formatter would delete every explanatory note in the file. Losing
 * an author's reasoning to fix an ordering nit is a bad trade, so until there is a
 * comment-preserving emitter, the fix stays manual and the report stays precise.
 */
#[AsCommand(
    name: 'fmt',
    description: 'Check that specs are written in canonical key order.',
)]
final class FmtCommand extends Command
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getOption('project');

        if (!is_string($directory)) {
            $io->error('--project must be a directory path.');

            return Command::INVALID;
        }

        $config = ProjectConfig::load($directory);

        $problems = (new FormatChecker())->check(
            new SpecSource(rtrim($directory, '/') . '/' . $config->specDirectory),
        );

        if ([] !== $problems) {
            $io->error(sprintf('%d formatting problem(s).', count($problems)));

            foreach ($problems as $problem) {
                $io->writeln('  ' . $problem->describe());
            }

            return Command::FAILURE;
        }

        $io->success('Specs are in canonical form.');

        return Command::SUCCESS;
    }
}
