<?php

namespace Tuchsoft\IssueReporter\Test\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tuchsoft\IssueReporter\Command\ListTransformer;

#[CoversClass(ListTransformer::class)]
#[Group('Command')]
class ListTransformerTest extends TestCase
{
    public function testListsTransformers(): void
    {
        $tester = new CommandTester(new ListTransformer());
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Available transformer', $display);
        self::assertStringContainsString('messagereplacer', $display);
        self::assertStringContainsString('Enabled by default', $display);
    }
}
