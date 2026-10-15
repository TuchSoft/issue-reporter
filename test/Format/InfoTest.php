<?php

namespace Tuchsoft\IssueReporter\Test\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\Info;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Test\Base\AbstractFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;

/**
 * Adversarial tests for the native ANSI text format "info".
 *
 * The format is not parsable (NativeFormatInterface), so there is no round-trip:
 * we assert concrete properties of generate() output instead.
 */
#[CoversClass(Info::class)]
#[Group('Info')]
class InfoTest extends AbstractFormatTest
{
    use ReportProvider;

    protected FormatInterface $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new Info();
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
    // Data providers
    // ----------------------------------------------------------------------

    public static function optionMatrixProvider(): array
    {
        $optionSets = [
            'default' => [],
            'no-color' => ['color' => false],
            'no-emoji' => ['emoji' => false],
            'plain' => ['color' => false, 'emoji' => false],
            'narrow' => ['max-width' => 20],
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
        $out = (new Info($options))->generate($this->buildShape($shape));

        self::assertNotSame('', $out, 'output must never be empty');
        self::assertStringContainsString('-----', $out, 'the output must contain table borders');

        self::assertSame(
            $options['color'] ?? true,
            str_contains($out, "\033["),
            'ANSI escape codes must be present iff color is enabled'
        );

        if ($shape === 'empty') {
            self::assertStringContainsString('No issues found', $out);
            self::assertStringNotContainsString('ERROR', $out, 'empty report must not show any severity');
            return;
        }

        // Headers/labels are wrapped in ANSI codes when color is on, so strip
        // them before doing text-boundary assertions.
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $out);

        $emojiOn = $options['emoji'] ?? true;
        $hasEmoji = str_contains($plain, '❌') || str_contains($plain, '⚠') || str_contains($plain, '💡');
        self::assertSame($emojiOn, $hasEmoji, 'the emoji glyph must follow the emoji option');

        self::assertSame(
            (bool) ($options['show-ref'] ?? false),
            (bool) preg_match('/\bRef\b/', $plain),
            'the Ref column must follow the show-ref option'
        );
        self::assertSame(
            (bool) ($options['show-help'] ?? true),
            (bool) preg_match('/\bHelp\b/', $plain),
            'the Help column must follow the show-help option'
        );

        // Severity labels are always shown, regardless of emoji/color.
        self::assertMatchesRegularExpression('/\bERROR\b/', $plain);
    }

    // ----------------------------------------------------------------------
    // Content properties
    // ----------------------------------------------------------------------

    public function testContainsRelativePathMessagesAndSeverityLabels(): void
    {
        $out = (new Info(['color' => false, 'emoji' => false]))->generate($this->standardReport());

        self::assertStringContainsString('src/A.php', $out);
        self::assertStringContainsString('src/B.php', $out);
        self::assertStringContainsString('boom error', $out);
        self::assertStringContainsString('careful warning', $out);
        self::assertStringContainsString('nice tip', $out);
        self::assertStringContainsString('ERROR', $out);
        self::assertStringContainsString('WARNING', $out);
        self::assertStringContainsString('TIP', $out);
        self::assertStringContainsString('10:5', $out);
    }

    public function testColorOptionControlsAnsiEscapes(): void
    {
        $withColor = (new Info(['color' => true]))->generate($this->standardReport());
        self::assertStringContainsString("\033[", $withColor, 'colored output must emit ANSI escape codes');

        $withoutColor = (new Info(['color' => false]))->generate($this->standardReport());
        self::assertStringNotContainsString("\033[", $withoutColor, 'uncolored output must not emit ANSI escape codes');
    }

    public function testEmojiOptionTogglesGlyph(): void
    {
        $withEmoji = (new Info(['emoji' => true, 'color' => false]))->generate($this->standardReport());
        self::assertStringContainsString('❌', $withEmoji);
        self::assertStringContainsString('⚠', $withEmoji);
        self::assertStringContainsString('💡', $withEmoji);

        $withoutEmoji = (new Info(['emoji' => false, 'color' => false]))->generate($this->standardReport());
        self::assertStringNotContainsString('❌', $withoutEmoji);
        self::assertStringNotContainsString('💡', $withoutEmoji);
        // Labels survive emoji removal.
        self::assertStringContainsString('ERROR', $withoutEmoji);
    }

    public function testShowRefOptionTogglesRefColumn(): void
    {
        $withoutRef = (new Info(['show-ref' => false, 'color' => false]))->generate($this->standardReport());
        self::assertDoesNotMatchRegularExpression('/\bRef\b/', $withoutRef);
        self::assertStringNotContainsString('https://ex.com/e1', $withoutRef);

        $withRef = (new Info(['show-ref' => true, 'color' => false]))->generate($this->standardReport());
        self::assertMatchesRegularExpression('/\bRef\b/', $withRef);
        self::assertStringContainsString('https://ex.com/e1', $withRef);
    }

    public function testShowHelpOptionTogglesHelpColumn(): void
    {
        $withHelp = (new Info(['show-help' => true, 'color' => false]))->generate($this->standardReport());
        self::assertMatchesRegularExpression('/\bHelp\b/', $withHelp);
        self::assertStringContainsString('fix it', $withHelp);

        $withoutHelp = (new Info(['show-help' => false, 'color' => false]))->generate($this->standardReport());
        self::assertDoesNotMatchRegularExpression('/\bHelp\b/', $withoutHelp);
        self::assertStringNotContainsString('fix it', $withoutHelp);
    }

    // ----------------------------------------------------------------------
    // Hostile / edge shapes
    // ----------------------------------------------------------------------

    public function testEmptyReportPrintsNoIssuesMessage(): void
    {
        $out = (new Info())->generate($this->emptyReport());

        self::assertStringContainsString('No issues found', $out);
        self::assertStringNotContainsString('Detailed Issues', $out);
    }

    public function testOnlySubReportsStillRenderDetails(): void
    {
        $out = (new Info())->generate($this->subReportOnlyReport());

        // The parent has no own issue, but the recursive issues still render.
        self::assertStringContainsString('Detailed Issues', $out);
        self::assertStringContainsString('src/C.php', $out);
        self::assertStringContainsString('child error', $out);
    }

    public function testDeeplyNestedSubReportsRenderDeepestIssue(): void
    {
        $out = (new Info())->generate($this->nestedReport());

        self::assertStringContainsString('deep/grand.php', $out);
        self::assertStringContainsString('deep error', $out);
    }

    public function testLineAndColumnRendering(): void
    {
        $withColumn = (new Info(['color' => false]))->generate($this->reportWithIssue('m', 'h', 'X1', 10, 5));
        self::assertStringContainsString('10:5', $withColumn);

        // Column 0 must not emit a trailing ":0".
        $noColumn = (new Info(['color' => false]))->generate($this->reportWithIssue('m', 'h', 'X1', 4, 0));
        self::assertStringContainsString('4', $noColumn);
        self::assertStringNotContainsString('4:0', $noColumn);

        // No line and no column at all.
        $none = (new Info(['color' => false]))->generate($this->reportWithIssue('m', 'h', 'X1', 0, 0));
        self::assertStringNotContainsString('0:0', $none);
        self::assertStringContainsString('m', $none);
    }

    public function testUnicodeMessageAndPathArePreserved(): void
    {
        $report = new Report('Test Report', '/project/base');
        $report->start();
        $report->addIssue(new Issue('U1', Issue::SEVERITY_ERROR, 'Unicode: é 😀', '/project/base/src/café.php', 1, 1));
        $report->complete();
        $report->setTimeStart(1000.0);
        $report->setTimeEnd(1000.05);

        $out = (new Info(['color' => false, 'emoji' => false]))->generate($report);

        self::assertStringContainsString('é', $out);
        self::assertStringContainsString('😀', $out);
        self::assertStringContainsString('café.php', $out);
    }

    public function testMaxWidthZeroKeepsLongMessageIntact(): void
    {
        $message = 'alpha beta gamma delta epsilon zeta eta theta';
        $unwrapped = (new Info(['max-width' => 0, 'color' => false]))->generate($this->reportWithIssue($message));

        self::assertStringContainsString($message, $unwrapped);
    }

    public function testSmallMaxWidthWrapsLongMessage(): void
    {
        $message = 'alpha beta gamma delta epsilon zeta eta theta';
        $wrapped = (new Info(['max-width' => 20, 'color' => false]))->generate($this->reportWithIssue($message));

        self::assertStringNotContainsString($message, $wrapped, 'a long message must be wrapped when max-width is small');
        // Content is not lost, only wrapped.
        self::assertStringContainsString('alpha', $wrapped);
        self::assertStringContainsString('theta', $wrapped);
    }

    // ----------------------------------------------------------------------
    // Provided reports (ReportProvider)
    // ----------------------------------------------------------------------

    public function testRendersProvidedReports(): void
    {
        $out = (new Info())->generate($this->createTestReport([]));
        self::assertStringContainsString('This is a standard test message.', $out);

        $fixed = (new Info())->generate($this->getFixedTestReport());
        self::assertStringContainsString('critical error', $fixed);
        self::assertStringContainsString('File1.php', $fixed);
    }

    // ----------------------------------------------------------------------
    // Suspected source bug: summary labels every row with the parent name.
    // ----------------------------------------------------------------------

    public function testSummaryLabelsEachRowWithItsOwnReportName(): void
    {
        $out = (new Info())->generate($this->subReportOnlyReport());

        self::assertStringContainsString(
            'Child Report',
            $out,
            'the summary must expose the sub-report name, not repeat the parent name'
        );
    }
}
