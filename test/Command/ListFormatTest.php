<?php

namespace Tuchsoft\IssueReporter\Test\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tuchsoft\IssueReporter\Command\ListFormat;

#[CoversClass(ListFormat::class)]
#[Group('Command')]
class ListFormatTest extends TestCase
{
    public function testListsFormats(): void
    {
        $tester = new CommandTester(new ListFormat());
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Available output format', $display);
        self::assertStringContainsString('raw', $display);
    }

    public function testSearchFiltersFormats(): void
    {
        $tester = new CommandTester(new ListFormat());
        $exit = $tester->execute(['search' => 'phpcs']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('phpcs', $tester->getDisplay());
    }
}
