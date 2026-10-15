<?php

namespace Tuchsoft\IssueReporter\Test\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tuchsoft\IssueReporter\Command\Convert;
use Tuchsoft\IssueReporter\Factory;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\Base\ParsableFormatInterface;
use Tuchsoft\IssueReporter\Format\Checkstyle;
use Tuchsoft\IssueReporter\Format\Raw;
use Tuchsoft\IssueReporter\Report;

#[CoversClass(Convert::class)]
#[Group('Command')]
class ConvertTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->files = [];
    }

    private function tmp(string $suffix = '.json'): string
    {
        $base = tempnam(sys_get_temp_dir(), 'ir_');
        @unlink($base);
        $path = $base . $suffix;
        $this->files[] = $path;

        return $path;
    }

    private function writeRawReport(string $name, int $errors, int $warnings): string
    {
        $report = new Report($name, '/app');
        $report->start();
        for ($i = 0; $i < $errors; $i++) {
            $report->error("E$i", "error $i", "src/f$i.php", 10);
        }
        for ($i = 0; $i < $warnings; $i++) {
            $report->warning("W$i", "warning $i", "src/f$i.php", 20);
        }
        $report->complete();

        $path = $this->tmp();
        file_put_contents($path, (new Raw())->generate($report));

        return $path;
    }

    public function testConvertSingleFileToRaw(): void
    {
        $in = $this->writeRawReport('In', 2, 1);
        $out = $this->tmp();

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$in],
            '--to' => 'raw',
            '--output' => $out,
        ]);

        self::assertSame(0, $exit);
        $data = json_decode(file_get_contents($out), true);
        self::assertSame(2, $data['totalErrors']);
        self::assertSame(1, $data['totalWarnings']);
        self::assertCount(1, $data['subReports']);
    }

    public function testMergeMultipleInputs(): void
    {
        $a = $this->writeRawReport('A', 1, 0);
        $b = $this->writeRawReport('B', 1, 1);
        $out = $this->tmp();

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$a, $b],
            '--to' => 'raw',
            '--output' => $out,
        ]);

        self::assertSame(0, $exit);
        $data = json_decode(file_get_contents($out), true);
        self::assertSame(2, $data['totalErrors']);
        self::assertSame(1, $data['totalWarnings']);
        self::assertCount(2, $data['subReports']);
    }

    public function testPerFileFormatPrefix(): void
    {
        $in = $this->writeRawReport('Prefixed', 1, 0);
        $out = $this->tmp();

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => ["raw:$in"],
            '--output' => $out,
        ]);

        self::assertSame(0, $exit);
        $data = json_decode(file_get_contents($out), true);
        self::assertSame(1, $data['totalErrors']);
    }

    public function testUnknownOutputFormatFails(): void
    {
        $in = $this->writeRawReport('X', 1, 0);

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$in],
            '--to' => 'does-not-exist',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown output format', $tester->getErrorOutput());
    }

    public function testNonParsableInputFormatFails(): void
    {
        $in = $this->writeRawReport('Y', 1, 0);

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => ["infomd:$in"],
            '--to' => 'raw',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('not parsable', $tester->getErrorOutput());
    }

    public function testConfigFileBuildsReport(): void
    {
        $a = $this->writeRawReport('A', 1, 1);
        $b = $this->writeRawReport('B', 1, 0);
        $out = $this->tmp();

        $config = $this->tmp();
        file_put_contents($config, json_encode([
            'name' => 'CI',
            'from' => 'raw',
            'to' => 'raw',
            'output' => $out,
            'inputs' => [
                ['type' => 'raw', 'path' => $a, 'name' => 'first'],
                ['name' => 'group', 'inputs' => [
                    ['type' => 'raw', 'path' => $b, 'name' => 'second'],
                ]],
                ['name' => 'manual', 'issues' => [
                    ['code' => 'E1', 'severity' => 5, 'message' => 'boom', 'path' => 'src/A.php', 'line' => 10],
                ]],
            ],
        ]));

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute(['--config' => $config]);

        self::assertSame(0, $exit);
        $data = json_decode(file_get_contents($out), true);
        self::assertSame('CI', $data['name']);
        self::assertSame(3, $data['totalErrors']);
        self::assertSame(1, $data['totalWarnings']);
        self::assertCount(3, $data['subReports']);
    }

    public function testConfigOverridesOtherCliFlags(): void
    {
        // When --config is given, every other flag/argument is ignored.
        $in = $this->writeRawReport('A', 1, 0);
        $recipeOut = $this->tmp();
        $cliOut = $this->tmp();
        @unlink($cliOut);

        $config = $this->tmp();
        file_put_contents($config, json_encode([
            'from' => 'raw',
            'to' => 'raw',
            'output' => $recipeOut,
            'inputs' => [['type' => 'raw', 'path' => $in, 'name' => 'x']],
        ]));

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            '--config' => $config,
            'input' => ['should-be-ignored.json'],
            '--to' => 'not-a-format',
            '--output' => $cliOut,
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($recipeOut);
        self::assertFileDoesNotExist($cliOut);
    }

    public function testConfigUnknownKeyFails(): void
    {
        $config = $this->tmp();
        file_put_contents($config, '{"inptu": []}');

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute(['--config' => $config], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown key', $tester->getErrorOutput());
    }

    public function testUnknownFromFormatFails(): void
    {
        $in = $this->writeRawReport('X', 1, 0);

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$in],
            '--from' => 'nope',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown input format', $tester->getErrorOutput());
    }

    public function testNonParsableFromFormatFails(): void
    {
        $in = $this->writeRawReport('X', 1, 0);

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$in],
            '--from' => 'infomd',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('cannot parse input', $tester->getErrorOutput());
    }

    public function testUnreadableInputFails(): void
    {
        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => ['/no/such/file-xyz.json'],
            '--to' => 'raw',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Failed to parse', $tester->getErrorOutput());
    }

    public function testDuplicateInputNamesAreSuffixed(): void
    {
        $a = $this->writeRawReport('SameFile', 1, 0);
        $b = $this->writeRawReport('SameFile', 1, 0);
        $out = $this->tmp();

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$a, $b],
            '--to' => 'raw',
            '--output' => $out,
        ]);

        self::assertSame(0, $exit);
        $data = json_decode(file_get_contents($out), true);
        $names = array_column($data['subReports'], 'name');
        self::assertContains('SameFile', $names);
        self::assertContains('SameFile (2)', $names);
    }

    public function testSuccessMessageOnStderrWhenWritingToFile(): void
    {
        $in = $this->writeRawReport('X', 1, 0);
        $out = $this->tmp();

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$in],
            '--to' => 'raw',
            '--output' => $out,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Report written to', $tester->getErrorOutput());
    }

    public function testNameAndBasePathAppliedToRoot(): void
    {
        $in = $this->writeRawReport('X', 1, 0);
        $out = $this->tmp();

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$in],
            '--to' => 'raw',
            '--output' => $out,
            '--name' => 'MyRoot',
            '--base-path' => '/custom',
        ]);

        self::assertSame(0, $exit);
        $data = json_decode(file_get_contents($out), true);
        self::assertSame('MyRoot', $data['name']);
        self::assertStringContainsString('custom', $data['basePath']);
    }

    public function testRegisterOptionsAccepted(): void
    {
        $in = $this->writeRawReport('X', 1, 0);
        $out = $this->tmp();

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => [$in],
            '--to' => 'raw',
            '--output' => $out,
            '--register-namespace' => ['Tuchsoft\\IssueReporter\\Format'],
            '--register-class' => [Raw::class],
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($out);
    }

    public function testConfigRegisterKeyAccepted(): void
    {
        $in = $this->writeRawReport('A', 1, 0);
        $out = $this->tmp();

        $config = $this->tmp();
        file_put_contents($config, json_encode([
            'to' => 'raw',
            'output' => $out,
            'register' => ['Tuchsoft\\IssueReporter\\Format'],
            'inputs' => [['type' => 'raw', 'path' => $in, 'name' => 'x']],
        ]));

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute(['--config' => $config]);

        self::assertSame(0, $exit);
        self::assertFileExists($out);
    }

    public function testConfigUnknownOutputFormatFails(): void
    {
        $config = $this->tmp();
        file_put_contents($config, '{"to":"nope"}');

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute(['--config' => $config], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown output format', $tester->getErrorOutput());
    }

    public function testConfigUnknownFromFormatFails(): void
    {
        $config = $this->tmp();
        file_put_contents($config, '{"from":"nope","to":"raw"}');

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute(['--config' => $config], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('is unknown or not parsable', $tester->getErrorOutput());
    }

    public function testConfigNonParsableFromFails(): void
    {
        $config = $this->tmp();
        file_put_contents($config, '{"from":"infomd","to":"raw"}');

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute(['--config' => $config], ['capture_stderr_separately' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('is unknown or not parsable', $tester->getErrorOutput());
    }

    public function testConfigSuccessMessageOnStderrWhenWritingToFile(): void
    {
        $in = $this->writeRawReport('A', 1, 0);
        $out = $this->tmp();

        $config = $this->tmp();
        file_put_contents($config, json_encode([
            'to' => 'raw',
            'output' => $out,
            'inputs' => [['type' => 'raw', 'path' => $in, 'name' => 'x']],
        ]));

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute(['--config' => $config], ['capture_stderr_separately' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Report written to', $tester->getErrorOutput());
    }

    public function testMachineParsedInputRendersReadableFormat(): void
    {
        // A report parsed from a machine format has issues without an explicit
        // relativePath; the readable formats must still render it (regression).
        $report = new Report('Src', '/app');
        $report->start();
        $report->error('E101', 'broke', 'src/A.php', 10, 5);
        $report->complete();

        $checkstyleFile = $this->tmp('.xml');
        file_put_contents($checkstyleFile, (new Checkstyle())->generate($report));
        $out = $this->tmp('.md');

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => ["checkstyle:$checkstyleFile"],
            '--to' => 'infomd',
            '--output' => $out,
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($out);
        $rendered = file_get_contents($out);
        self::assertStringContainsString('A.php', $rendered);
        self::assertStringContainsString('broke', $rendered);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyFormat(): array
    {
        $cases = [];
        foreach (Factory::getRegistered(Factory::FORMAT) as $name => $fqn) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * Every registered output format (parsable and native) must render a
     * machine-parsed report without crashing and produce some output.
     */
    #[DataProvider('everyFormat')]
    public function testConvertsToEveryFormat(string $to): void
    {
        $in = $this->checkstyleInput();
        $out = $this->tmp();

        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => ["checkstyle:$in"],
            '--to' => $to,
            '--output' => $out,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(0, $exit, "convert --to '$to' failed: " . $tester->getErrorOutput());
        self::assertFileExists($out);
        self::assertNotSame('', file_get_contents($out));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyParsableFormat(): array
    {
        $cases = [];
        foreach (Factory::getRegistered(Factory::FORMAT) as $name => $fqn) {
            if (is_a($fqn, ParsableFormatInterface::class, true)) {
                $cases[$name] = [$name];
            }
        }

        return $cases;
    }

    /**
     * A report generated in each parsable format must parse back with the same
     * error/warning counts (basic severity + count integrity).
     */
    #[DataProvider('everyParsableFormat')]
    public function testConvertsFromEveryParsableFormat(string $from): void
    {
        $report = new Report('Src', '/app');
        $report->start();
        $report->error('E101', 'broke', 'src/A.php', 10, 5);
        $report->warning('W201', 'careful', 'src/B.php', 3);
        $report->complete();

        /** @var FormatInterface $format */
        $format = Factory::create(Factory::FORMAT, $from, []);
        $in = $this->tmp('.txt');
        file_put_contents($in, $format->generate($report));

        $out = $this->tmp();
        $tester = new CommandTester(new Convert());
        $exit = $tester->execute([
            'input' => ["$from:$in"],
            '--to' => 'raw',
            '--output' => $out,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(0, $exit, "convert from '$from' failed: " . $tester->getErrorOutput());
        $data = json_decode(file_get_contents($out), true);
        self::assertSame(1, $data['totalErrors'], "errors not preserved through '$from'");
        self::assertSame(1, $data['totalWarnings'], "warnings not preserved through '$from'");
    }

    private function checkstyleInput(): string
    {
        $report = new Report('Src', '/app');
        $report->start();
        $report->error('E101', 'broke', 'src/A.php', 10, 5);
        $report->warning('W201', 'careful', 'src/B.php', 3);
        $report->complete();

        $in = $this->tmp('.xml');
        file_put_contents($in, (new Checkstyle())->generate($report));

        return $in;
    }
}
