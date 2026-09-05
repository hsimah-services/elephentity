<?php

declare(strict_types=1);

namespace PheFr\Cli\Command;

use PheFr\Cli\ProjectConfig;
use PheFr\Codegen\Codegen;
use PheFr\Codegen\GeneratorConfig;
use PheFr\Codegen\Output\Writer;
use PheFr\Codegen\Output\WriteReport;
use PheFr\Schema\SchemaCompiler;
use PheFr\Schema\SpecSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compiles the specs and writes the generated tree.
 *
 * With --check nothing is written: the tree is regenerated in memory and compared
 * against disk. That is the CI gate, and it catches all three ways the tree can drift
 * — a hand-edited file, a stale file the schema no longer produces, and a deleted one.
 */
#[AsCommand(
    name: 'generate',
    description: 'Generate PHP from the specs. Use --check to verify without writing.',
)]
final class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'check',
            null,
            InputOption::VALUE_NONE,
            'Report what would change and fail if anything would, without writing.',
        );

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
        $check = true === $input->getOption('check');
        $directory = $input->getOption('project');

        if (!is_string($directory)) {
            $io->error('--project must be a directory path.');

            return Command::INVALID;
        }

        $config = ProjectConfig::load($directory);
        $root = rtrim($directory, '/');

        $compiled = (new SchemaCompiler())->compile(
            new SpecSource($root . '/' . $config->specDirectory),
        );

        if (!$compiled->isSuccess()) {
            $io->error(sprintf('%d problem(s) in the specs; nothing was generated.', count($compiled->errors)));

            foreach ($compiled->errors as $error) {
                $io->writeln('  ' . $error->describe());
            }

            return Command::FAILURE;
        }

        $outputDirectory = $root . '/' . $config->outputDirectory;

        $files = (new Codegen(new GeneratorConfig(
            $config->rootNamespace,
            $outputDirectory,
            $config->typeNamespace,
        )))->generate($compiled->schema());

        $writer = new Writer($outputDirectory);
        $report = $check ? $writer->check($files) : $writer->write($files);

        return $check
            ? $this->reportCheck($io, $report)
            : $this->reportWrite($io, $report, count($files));
    }

    private function reportCheck(SymfonyStyle $io, WriteReport $report): int
    {
        if ($report->isClean()) {
            $io->success(sprintf('Generated tree is up to date (%d files).', count($report->unchanged)));

            return Command::SUCCESS;
        }

        $io->error(sprintf('Generated tree is out of date: %d file(s) differ.', $report->changeCount()));

        $this->list($io, 'Hand-edited', $report->tampered);
        $this->list($io, 'Would be created', $report->created);
        $this->list($io, 'Would be updated', $report->updated);
        $this->list($io, 'No longer produced by the schema', $report->deleted);

        $io->writeln('Run <info>phefr generate</info> and commit the result.');

        return Command::FAILURE;
    }

    private function reportWrite(SymfonyStyle $io, WriteReport $report, int $total): int
    {
        foreach ($report->tampered as $path) {
            $io->warning(sprintf('%s had been edited by hand; it has been regenerated.', $path));
        }

        $io->success(sprintf(
            '%d file(s): %d created, %d updated, %d unchanged, %d removed.',
            $total,
            count($report->created),
            count($report->updated),
            count($report->unchanged),
            count($report->deleted),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $paths
     */
    private function list(SymfonyStyle $io, string $heading, array $paths): void
    {
        if ([] === $paths) {
            return;
        }

        $io->writeln(sprintf('<comment>%s:</comment>', $heading));

        foreach ($paths as $path) {
            $io->writeln('  ' . $path);
        }

        $io->writeln('');
    }
}
