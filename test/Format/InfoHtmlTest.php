<?php

namespace Tuchsoft\IssueReporter\Test\Format;

use DOMDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\InfoHtml;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Test\Base\AbstractFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;

/**
 * Adversarial tests for the native HTML format "infohtml".
 *
 * The format is not parsable (NativeFormatInterface), so there is no round-trip:
 * we assert concrete properties of generate() output instead, including that the
 * output is well-formed HTML and that user supplied text is escaped (no XSS).
 */
#[CoversClass(InfoHtml::class)]
#[Group('InfoHtml')]
class InfoHtmlTest extends AbstractFormatTest
{
    use ReportProvider;

    protected FormatInterface $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new InfoHtml();
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
    // HTML helper
    // ----------------------------------------------------------------------

    private function parseHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($ok, 'the generated HTML must be loadable by DOMDocument');
        $fatal = array_filter($errors, fn($e) => $e->level >= LIBXML_ERR_ERROR);
        self::assertSame([], $fatal, 'the generated HTML must be well-formed (no libxml errors)');

        return $dom;
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
        $out = (new InfoHtml($options))->generate($this->buildShape($shape));

        self::assertNotSame('', $out, 'output must never be empty');
        self::assertSame(
            substr_count($out, '<table>'),
            substr_count($out, '</table>'),
            '<table> must be balanced'
        );

        $dom = $this->parseHtml($out);
        self::assertGreaterThanOrEqual(1, $dom->getElementsByTagName('table')->length, 'at least the summary table');
        self::assertGreaterThanOrEqual(1, $dom->getElementsByTagName('h1')->length, 'the report title must be an <h1>');

        if ($shape === 'empty') {
            self::assertStringContainsString('No issues found', $out);
            self::assertStringNotContainsString('<h2>Detailed Issues</h2>', $out);
            return;
        }

        self::assertStringContainsString('<h2>Detailed Issues</h2>', $out);
        self::assertStringContainsString('<span style="color:red;">ERROR</span>', $out);

        $emojiOn = $options['emoji'] ?? true;
        $hasEmoji = str_contains($out, '❌') || str_contains($out, '⚠') || str_contains($out, '💡');
        self::assertSame($emojiOn, $hasEmoji, 'the emoji glyph must follow the emoji option');

        self::assertSame(
            (bool) ($options['show-ref'] ?? false),
            (bool) preg_match('/>Ref</', $out),
            'the Ref column must follow the show-ref option'
        );
        self::assertSame(
            (bool) ($options['show-help'] ?? true),
            (bool) preg_match('/>Help</', $out),
            'the Help column must follow the show-help option'
        );
    }

    // ----------------------------------------------------------------------
    // Structure / content
    // ----------------------------------------------------------------------

    public function testProducesWellFormedHtmlStructure(): void
    {
        $out = (new InfoHtml(['emoji' => false]))->generate($this->standardReport());
        $dom = $this->parseHtml($out);

        self::assertSame('Test Report', $dom->getElementsByTagName('h1')->item(0)->textContent);
        self::assertSame('Detailed Issues', $dom->getElementsByTagName('h2')->item(0)->textContent);
        self::assertSame(3, $dom->getElementsByTagName('table')->length, 'summary + one table per file (A.php, B.php)');
        self::assertSame(1, $dom->getElementsByTagName('hr')->length);

        self::assertStringContainsString('<p>File: <strong>src/A.php</strong></p>', $out);
        self::assertStringContainsString('boom error', $out);
        self::assertStringContainsString('<td style="text-align: left;">10:5</td>', $out);
    }

    public function testEmojiOptionTogglesGlyph(): void
    {
        $withEmoji = (new InfoHtml(['emoji' => true]))->generate($this->standardReport());
        self::assertStringContainsString('❌', $withEmoji);
        self::assertStringContainsString('💡', $withEmoji);

        $withoutEmoji = (new InfoHtml(['emoji' => false]))->generate($this->standardReport());
        self::assertStringNotContainsString('❌', $withoutEmoji);
        self::assertStringNotContainsString('💡', $withoutEmoji);
    }

    public function testShowRefOptionTogglesRefColumn(): void
    {
        $withRef = (new InfoHtml(['show-ref' => true]))->generate($this->standardReport());
        self::assertStringContainsString('>Ref<', $withRef);
        self::assertStringContainsString('<a href="https://ex.com/e1">https://ex.com/e1</a>', $withRef);

        $withoutRef = (new InfoHtml(['show-ref' => false]))->generate($this->standardReport());
        self::assertStringNotContainsString('>Ref<', $withoutRef);
        self::assertStringNotContainsString('https://ex.com/e1', $withoutRef);
    }

    public function testShowHelpOptionTogglesHelpColumn(): void
    {
        $withHelp = (new InfoHtml(['show-help' => true]))->generate($this->standardReport());
        self::assertStringContainsString('>Help<', $withHelp);
        self::assertStringContainsString('fix it', $withHelp);

        $withoutHelp = (new InfoHtml(['show-help' => false]))->generate($this->standardReport());
        self::assertStringNotContainsString('>Help<', $withoutHelp);
        self::assertStringNotContainsString('fix it', $withoutHelp);
    }

    // ----------------------------------------------------------------------
    // Escaping (hostile XSS tests)
    // ----------------------------------------------------------------------

    public function testMessageHtmlIsEscapedAgainstXss(): void
    {
        $out = (new InfoHtml())->generate($this->reportWithIssue('<script>alert(1)</script>'));

        self::assertStringNotContainsString(
            '<script>',
            $out,
            'a message must never be emitted as a raw <script> tag'
        );
        self::assertStringContainsString('&lt;script&gt;', $out, 'the message must be HTML-escaped');
    }

    public function testHelpHtmlIsEscapedAgainstXss(): void
    {
        $out = (new InfoHtml())->generate($this->reportWithIssue('safe message', '<b>bold help</b>'));

        self::assertStringNotContainsString(
            '<b>bold help</b>',
            $out,
            'the help text must never be emitted as raw HTML tags'
        );
    }

    public function testCodeHtmlIsEscapedAgainstXss(): void
    {
        $out = (new InfoHtml())->generate($this->reportWithIssue('message', '', '<img src=x onerror=alert(1)>'));

        self::assertStringNotContainsString('<img', $out, 'the issue code must be HTML-escaped');
    }

    // ----------------------------------------------------------------------
    // Hostile / edge shapes
    // ----------------------------------------------------------------------

    public function testEmptyReportPrintsNoIssuesMessage(): void
    {
        $out = (new InfoHtml())->generate($this->emptyReport());

        self::assertStringContainsString('<p>No issues found. Everything looks good!</p>', $out);
        self::assertStringNotContainsString('<h2>Detailed Issues</h2>', $out);
    }

    public function testOnlySubReportsStillRenderDetails(): void
    {
        $out = (new InfoHtml())->generate($this->subReportOnlyReport());

        self::assertStringContainsString('<h2>Detailed Issues</h2>', $out);
        self::assertStringContainsString('src/C.php', $out);
        self::assertStringContainsString('child error', $out);
    }

    public function testDeeplyNestedSubReportsRenderDeepestIssue(): void
    {
        $out = (new InfoHtml())->generate($this->nestedReport());

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

        $out = (new InfoHtml(['emoji' => false]))->generate($report);

        self::assertStringContainsString('é', $out);
        self::assertStringContainsString('😀', $out);
        self::assertStringContainsString('café.php', $out);
    }

    public function testLineAndColumnRendering(): void
    {
        $withColumn = (new InfoHtml())->generate($this->reportWithIssue('m', 'h', 'X1', 10, 5));
        self::assertStringContainsString('10:5', $withColumn);

        $noColumn = (new InfoHtml())->generate($this->reportWithIssue('m', 'h', 'X1', 4, 0));
        self::assertStringContainsString('>4<', $noColumn);
        self::assertStringNotContainsString('4:0', $noColumn);

        $none = (new InfoHtml())->generate($this->reportWithIssue('m', 'h', 'X1', 0, 0));
        self::assertStringNotContainsString('0:0', $none);
    }

    // ----------------------------------------------------------------------
    // Provided reports (ReportProvider)
    // ----------------------------------------------------------------------

    public function testRendersProvidedReports(): void
    {
        $out = (new InfoHtml())->generate($this->createTestReport([]));
        self::assertStringContainsString('This is a standard test message.', $out);
        $this->parseHtml($out);

        $fixed = (new InfoHtml())->generate($this->getFixedTestReport());
        self::assertStringContainsString('critical error', $fixed);
        self::assertStringContainsString('File1.php', $fixed);
        $this->parseHtml($fixed);
    }

    // ----------------------------------------------------------------------
    // Suspected source bug: summary labels every row with the parent name.
    // ----------------------------------------------------------------------

    public function testSummaryLabelsEachRowWithItsOwnReportName(): void
    {
        $out = (new InfoHtml())->generate($this->subReportOnlyReport());

        self::assertStringContainsString(
            'Child Report',
            $out,
            'the summary must expose the sub-report name, not repeat the parent name'
        );
    }
}
