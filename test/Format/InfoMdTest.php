<?php

namespace Tuchsoft\IssueReporter\Test\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\InfoMd;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Test\Base\AbstractFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;

/**
 * Adversarial tests for the native Markdown format "infomd".
 *
 * The format is not parsable (NativeFormatInterface), so there is no round-trip:
 * we assert concrete properties of generate() output instead.
 */
#[CoversClass(InfoMd::class)]
#[Group('InfoMd')]
class InfoMdTest extends AbstractFormatTest
{
    use ReportProvider;

    protected FormatInterface $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new InfoMd();
    }

    // ----------------------------------------------------------------------
    // Report builders
    // ----------------------------------------------------------------------

    private function emptyReport(): Report
    {
        $report = new Report('Empty Report', '/project/base');
        $report->start();
        $report->complete();
        $report->setTimeStart(1000.0);
        $report->setTimeEnd(1000.05);

        return $report;
    }

    private function standardReport(): Report
    {
        $report = new Report('Test Report', '/project/base');
        $report->start();
        // Issue ctor order is ($ref, $help).
        $report->addIssue(new Issue('E1', Issue::SEVERITY_ERROR, 'boom error', '/project/base/src/A.php', 10, 5, 'https://ex.com/e1', 'fix it'));
        $report->addIssue(new Issue('W1', Issue::SEVERITY_WARNING, 'careful warning', '/project/base/src/B.php', 3, 0, '', 'watch out'));
        $report->addIssue(new Issue('T1', Issue::SEVERITY_TIP, 'nice tip', '/project/base/src/B.php', 0, 0, '', 'consider'));
        $report->complete();
        $report->setTimeStart(1000.0);
        $report->setTimeEnd(1000.05);

        return $report;
    }

    private function subReportOnlyReport(): Report
    {
        $parent = new Report('Parent Report', '/project/base');
        $parent->start();
        $child = new Report('Child Report', '/project/base');
        $child->start();
        $child->addIssue(new Issue('C1', Issue::SEVERITY_ERROR, 'child error', '/project/base/src/C.php', 1, 1, 'https://ex.com/c1', 'child help'));
        $child->complete();
        $parent->mergeIn($child);
        $parent->complete();
        $parent->setTimeStart(1000.0);
        $parent->setTimeEnd(1000.05);

        return $parent;
    }

    private function nestedReport(): Report
    {
        $root = new Report('Root Report', '/project/base');
        $root->start();
        $mid = new Report('Mid Report', '/project/base');
        $mid->start();
        $leaf = new Report('Leaf Report', '/project/base');
        $leaf->start();
        $leaf->addIssue(new Issue('L1', Issue::SEVERITY_ERROR, 'deep error', '/project/base/deep/grand.php', 7, 2, '', 'deep help'));
        $leaf->complete();
        $mid->mergeIn($leaf);
        $mid->complete();
        $root->mergeIn($mid);
        $root->complete();
        $root->setTimeStart(1000.0);
        $root->setTimeEnd(1000.05);

        return $root;
    }

    private function reportWithIssue(
        string $message,
        string $help = '',
        string $code = 'X1',
        int $line = 10,
        int $col = 5,
        string $ref = ''
    ): Report {
        $report = new Report('Test Report', '/project/base');
        $report->start();
        $report->addIssue(new Issue($code, Issue::SEVERITY_ERROR, $message, '/project/base/src/A.php', $line, $col, $ref, $help));
        $report->complete();
        $report->setTimeStart(1000.0);
        $report->setTimeEnd(1000.05);

        return $report;
    }

    private function buildShape(string $shape): Report
    {
        return match ($shape) {
            'empty' => $this->emptyReport(),
            'subreportOnly' => $this->subReportOnlyReport(),
            'nested' => $this->nestedReport(),
            default => $this->standardReport(),
        };
    }

    // ----------------------------------------------------------------------
    // Markdown helpers
    // ----------------------------------------------------------------------

    /**
     * Splits the output into contiguous blocks of "|"-prefixed lines (markdown tables).
     *
     * @return string[][]
     */
    private function markdownTables(string $out): array
    {
        $tables = [];
        $current = [];
        foreach (explode("\n", $out) as $line) {
            if (str_starts_with($line, '|')) {
                $current[] = $line;
            } elseif ($current) {
                $tables[] = $current;
                $current = [];
            }
        }
        if ($current) {
            $tables[] = $current;
        }

        return $tables;
    }

    /** Counts cell separators that are not escaped with a backslash. */
    private function unescapedPipes(string $row): int
    {
        return (int) preg_match_all('/(?<!\\\\)\|/', $row);
    }

    private function assertTablesAreConsistent(string $out): void
    {
        $tables = $this->markdownTables($out);
        self::assertNotEmpty($tables, 'the output must contain at least one markdown table');
        foreach ($tables as $index => $table) {
            $expected = $this->unescapedPipes($table[0]);
            foreach ($table as $row) {
                self::assertSame(
                    $expected,
                    $this->unescapedPipes($row),
                    "table #$index: row '$row' must keep the same number of cells as the header"
                );
            }
        }
    }

    // ----------------------------------------------------------------------
    // Data providers
    // ----------------------------------------------------------------------

    public static function optionMatrixProvider(): array
    {
        $optionSets = [
            'default' => [],
            'no-emoji' => ['emoji' => false],
            'show-ref' => ['show-ref' => true],
            'no-help' => ['show-help' => false],
        ];
        $shapes = ['standard', 'empty', 'subreportOnly', 'nested'];

        $cases = [];
        foreach ($optionSets as $optionName => $options) {
            foreach ($shapes as $shape) {
                $cases["$optionName/$shape"] = [$options, $shape];
            }
        }

        return $cases;
    }

    // ----------------------------------------------------------------------
    // Option x shape matrix
    // ----------------------------------------------------------------------

    #[DataProvider('optionMatrixProvider')]
    public function testOptionAndShapeMatrix(array $options, string $shape): void
    {
        $out = (new InfoMd($options))->generate($this->buildShape($shape));

        self::assertNotSame('', $out, 'output must never be empty');
        self::assertStringContainsString('|Report|Files|Errors|Warnings|Tips|Time|', $out, 'summary table header');
        self::assertMatchesRegularExpression('/^\|:?-[-\|:]*\|$/m', $out, 'markdown table separator row');
        $this->assertTablesAreConsistent($out);

        if ($shape === 'empty') {
            self::assertStringContainsString('No issues found', $out);
            self::assertStringNotContainsString('ERROR', $out);
            return;
        }

        self::assertStringContainsString('|Line| |Code|Message|', $out, 'detail table header');

        $emojiOn = $options['emoji'] ?? true;
        $hasEmoji = str_contains($out, '❌') || str_contains($out, '⚠') || str_contains($out, '💡');
        self::assertSame($emojiOn, $hasEmoji, 'the emoji glyph must follow the emoji option');

        self::assertSame(
            (bool) ($options['show-ref'] ?? false),
            (bool) preg_match('/\|Ref\|/', $out),
            'the Ref column must follow the show-ref option'
        );
        self::assertSame(
            (bool) ($options['show-help'] ?? true),
            (bool) preg_match('/\|Help\|/', $out),
            'the Help column must follow the show-help option'
        );

        self::assertStringContainsString('ERROR', $out);
    }

    // ----------------------------------------------------------------------
    // Structure / content
    // ----------------------------------------------------------------------

    public function testProducesWellFormedMarkdownStructure(): void
    {
        $out = (new InfoMd(['emoji' => false]))->generate($this->standardReport());

        self::assertStringContainsString('|Report|Files|Errors|Warnings|Tips|Time|', $out);
        self::assertStringContainsString('|:-|:-|:-|:-|:-|:-|', $out);
        self::assertStringContainsString('File: **src/A.php**', $out);
        self::assertStringContainsString('File: **src/B.php**', $out);
        self::assertStringContainsString('boom error', $out);
        self::assertStringContainsString('10:5', $out);
        self::assertStringContainsString('ERROR', $out);
        self::assertStringContainsString('WARNING', $out);
        self::assertStringContainsString('TIP', $out);
        self::assertStringContainsString('Detailed Issues', $out);

        // Every pipe-line must be part of a consistent table.
        $this->assertTablesAreConsistent($out);
    }

    public function testEmojiOptionTogglesGlyph(): void
    {
        $withEmoji = (new InfoMd(['emoji' => true]))->generate($this->standardReport());
        self::assertStringContainsString('❌', $withEmoji);
        self::assertStringContainsString('💡', $withEmoji);

        $withoutEmoji = (new InfoMd(['emoji' => false]))->generate($this->standardReport());
        self::assertStringNotContainsString('❌', $withoutEmoji);
        self::assertStringNotContainsString('💡', $withoutEmoji);
        self::assertStringContainsString('ERROR', $withoutEmoji);
    }

    public function testShowRefOptionTogglesRefColumn(): void
    {
        $withRef = (new InfoMd(['show-ref' => true]))->generate($this->standardReport());
        self::assertStringContainsString('|Ref|', $withRef);
        self::assertStringContainsString('https://ex.com/e1', $withRef);

        $withoutRef = (new InfoMd(['show-ref' => false]))->generate($this->standardReport());
        self::assertStringNotContainsString('|Ref|', $withoutRef);
        self::assertStringNotContainsString('https://ex.com/e1', $withoutRef);
    }

    public function testShowHelpOptionTogglesHelpColumn(): void
    {
        $withHelp = (new InfoMd(['show-help' => true]))->generate($this->standardReport());
        self::assertStringContainsString('|Help|', $withHelp);
        self::assertStringContainsString('fix it', $withHelp);

        $withoutHelp = (new InfoMd(['show-help' => false]))->generate($this->standardReport());
        self::assertStringNotContainsString('|Help|', $withoutHelp);
        self::assertStringNotContainsString('fix it', $withoutHelp);
    }

    // ----------------------------------------------------------------------
    // Escaping (hostile)
    // ----------------------------------------------------------------------

    public function testMessageContainingPipeKeepsTableWellFormed(): void
    {
        $out = (new InfoMd())->generate($this->reportWithIssue('alpha | beta'));

        // A literal pipe inside a message must not introduce an extra table cell.
        $this->assertTablesAreConsistent($out);
    }

    public function testHelpContainingPipeKeepsTableWellFormed(): void
    {
        $out = (new InfoMd(['show-help' => true]))->generate($this->reportWithIssue('safe message', 'help | with | pipes'));

        $this->assertTablesAreConsistent($out);
    }

    public function testMessageWithNewlineDoesNotBreakTableRow(): void
    {
        $out = (new InfoMd())->generate($this->reportWithIssue("first line\nsecond line"));

        self::assertStringNotContainsString(
            "first line\nsecond line",
            $out,
            'a raw newline inside a message must not split a markdown table row'
        );
    }

    public function testInlineMarkdownCharactersArePreservedLiterally(): void
    {
        $message = 'has `code` and *star* and _under_';
        $out = (new InfoMd())->generate($this->reportWithIssue($message));

        self::assertStringContainsString('`code`', $out);
        self::assertStringContainsString('*star*', $out);
        self::assertStringContainsString('_under_', $out);
        // The row must still be a valid table row.
        $this->assertTablesAreConsistent($out);
    }

    // ----------------------------------------------------------------------
    // Hostile / edge shapes
    // ----------------------------------------------------------------------

    public function testEmptyReportPrintsNoIssuesMessage(): void
    {
        $out = (new InfoMd())->generate($this->emptyReport());

        self::assertStringContainsString('No issues found', $out);
        self::assertStringNotContainsString('Detailed Issues', $out);
    }

    public function testOnlySubReportsStillRenderDetails(): void
    {
        $out = (new InfoMd())->generate($this->subReportOnlyReport());

        self::assertStringContainsString('Detailed Issues', $out);
        self::assertStringContainsString('src/C.php', $out);
        self::assertStringContainsString('child error', $out);
    }

    public function testDeeplyNestedSubReportsRenderDeepestIssue(): void
    {
        $out = (new InfoMd())->generate($this->nestedReport());

        self::assertStringContainsString('deep/grand.php', $out);
        self::assertStringContainsString('deep error', $out);
    }

    public function testUnicodeMessageAndPathArePreserved(): void
    {
        $report = new Report('Test Report', '/project/base');
        $report->start();
        $report->addIssue(new Issue('U1', Issue::SEVERITY_ERROR, 'Unicode: é 😀', '/project/base/src/café.php', 1, 1));
        $report->complete();
        $report->setTimeStart(1000.0);
        $report->setTimeEnd(1000.05);

        $out = (new InfoMd(['emoji' => false]))->generate($report);

        self::assertStringContainsString('é', $out);
        self::assertStringContainsString('😀', $out);
        self::assertStringContainsString('café.php', $out);
    }

    public function testLineAndColumnRendering(): void
    {
        $withColumn = (new InfoMd())->generate($this->reportWithIssue('m', 'h', 'X1', 10, 5));
        self::assertStringContainsString('10:5', $withColumn);

        $noColumn = (new InfoMd())->generate($this->reportWithIssue('m', 'h', 'X1', 4, 0));
        self::assertStringContainsString('|4|', $noColumn);
        self::assertStringNotContainsString('4:0', $noColumn);

        $none = (new InfoMd())->generate($this->reportWithIssue('m', 'h', 'X1', 0, 0));
        self::assertStringNotContainsString('0:0', $none);
    }

    // ----------------------------------------------------------------------
    // Provided reports (ReportProvider)
    // ----------------------------------------------------------------------

    public function testRendersProvidedReports(): void
    {
        $out = (new InfoMd())->generate($this->createTestReport([]));
        self::assertStringContainsString('This is a standard test message.', $out);

        $fixed = (new InfoMd())->generate($this->getFixedTestReport());
        self::assertStringContainsString('critical error', $fixed);
        self::assertStringContainsString('File1.php', $fixed);
    }

    // ----------------------------------------------------------------------
    // Suspected source bug: summary labels every row with the parent name.
    // ----------------------------------------------------------------------

    public function testSummaryLabelsEachRowWithItsOwnReportName(): void
    {
        $out = (new InfoMd())->generate($this->subReportOnlyReport());

        self::assertStringContainsString(
            'Child Report',
            $out,
            'the summary must expose the sub-report name, not repeat the parent name'
        );
    }
}
