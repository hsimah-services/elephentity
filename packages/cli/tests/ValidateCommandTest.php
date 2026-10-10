<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\Command\ValidateCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ValidateCommandTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/eleph-validate-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/spec/entities', 0o775, true);
        file_put_contents($this->project . '/spec/project.yml', <<<'YAML'
            project: Fixture
            storage:
              driver: memory
            integrations:
              demo: {}
            YAML);
        file_put_contents($this->project . '/spec/entities/Note.yml', <<<'YAML'
            entity: Note
            use: [Stamped]
            integrations:
              demo: {}
            storage:
              table: note
              handle: note
            fields:
              status:
                type: NoteStatus
            YAML);
        file_put_contents($this->project . '/provides.json', <<<'JSON'
            {
              "targets": {
                "memory": {
                  "drivers": ["memory"],
                  "storage": {"handle": {"maxLength": 4}},
                  "integrations": {"demo": {}},
                  "patterns": {
                    "Stamped": {
                      "pattern": "Stamped",
                      "fields": {"createdAt": {"type": "datetime", "managed": "created"}}
                    }
                  },
                  "types": {
                    "NoteStatus": {"type": "NoteStatus", "primitive": "string", "values": ["draft", "published"]}
                  }
                }
              }
            }
            JSON);
    }

    protected function tearDown(): void
    {
        unlink($this->project . '/spec/entities/Note.yml');
        unlink($this->project . '/spec/project.yml');
        unlink($this->project . '/provides.json');
        rmdir($this->project . '/spec/entities');
        rmdir($this->project . '/spec');
        rmdir($this->project);
    }

    public function testSnapshotValidatesPooledSpecsAndIntegrationsWithoutAProjectOrGeneratedFiles(): void
    {
        $tester = $this->validate();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 entity, 1 type', $tester->getDisplay());
        self::assertStringContainsString('installed builder compatibility was not checked', $tester->getDisplay());
        self::assertFileDoesNotExist($this->project . '/eleph.json');
        self::assertSame(['.', '..', 'provides.json', 'spec'], scandir($this->project));
    }

    public function testSnapshotStillChecksDriverStorageRules(): void
    {
        $this->replaceSpec('handle: note', 'handle: toolong');
        $tester = $this->validate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('handle', $tester->getDisplay());
    }

    public function testSnapshotStillRejectsUnresolvedReferences(): void
    {
        $this->replaceSpec('type: NoteStatus', 'type: Missing');
        $tester = $this->validate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Missing', $tester->getDisplay());
    }

    public function testSnapshotStillRejectsUnknownIntegrations(): void
    {
        $this->replaceSpec('demo: {}', 'unknown: {}');
        $tester = $this->validate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('unknown', $tester->getDisplay());
    }

    #[DataProvider('invalidSnapshots')]
    public function testInvalidSnapshotFailsWithoutFallingBackToDiscovery(string $json): void
    {
        file_put_contents($this->project . '/provides.json', $json);
        $tester = $this->validate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('No eleph.json', $tester->getDisplay());
        self::assertStringNotContainsString('Specs are valid', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSnapshots(): iterable
    {
        yield 'invalid JSON' => ['{'];
        yield 'missing targets' => ['{}'];
        yield 'malformed integration' => ['{"targets":{"demo":{"integrations":{"demo":{"entityConfig":true}}}}}'];
    }

    public function testMissingSnapshotFailsWithAUsefulError(): void
    {
        $tester = $this->validate(['--provides' => $this->project . '/missing.json']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('readable describe JSON file', $tester->getDisplay());
    }

    public function testDefaultValidationStillRequiresLiveProjectDiscovery(): void
    {
        $tester = new CommandTester(new ValidateCommand());
        $tester->execute(['spec' => $this->project . '/spec', '--project' => $this->project]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No eleph.json', $tester->getDisplay());
    }

    private function replaceSpec(string $from, string $to): void
    {
        $path = $this->project . '/spec/entities/Note.yml';
        file_put_contents($path, str_replace($from, $to, (string) file_get_contents($path)));
    }

    /** @param array<string, string> $input */
    private function validate(array $input = []): CommandTester
    {
        $tester = new CommandTester(new ValidateCommand());
        $tester->execute([
            'spec' => $this->project . '/spec',
            '--project' => $this->project,
            '--provides' => $this->project . '/provides.json',
            ...$input,
        ]);

        return $tester;
    }
}
