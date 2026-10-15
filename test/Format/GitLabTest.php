<?php

namespace Tuchsoft\IssueReporter\Test\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\GitLab;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Test\Base\AbstractJsonFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;

/**
 * Tests for the GitLab Code Quality format.
 *
 * This class contains unit tests for both generating and parsing GitLab Code Quality reports.
 */
#[CoversClass(GitLab::class)]
#[Group('GitLab')]
class GitLabTest extends AbstractJsonFormatTest
{
    use ReportProvider;

    /** @var GitLab $formatter */
    protected FormatInterface $formatter;

    /** @var class-string<GitLab> */
    protected static string $formatterClass = GitLab::class;

    /**
     * Sets up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new GitLab();
    }

    /**
     * Data provider for testing the generate method with various options.
     * The GitLab format uses ParsableMessageFormatTrait, so we test options affecting that.
     *
     * @return array<string, array{options: array<string, bool>, withHelp: bool, withRef: bool}>
     */
    public static function generateProvider(): array
    {
        return [
            'Default options (show-help=true, show-ref=false)' => [
                'options' => [],
                'withHelp' => true,
                'withRef' => false,
            ],
            'show-help enabled' => [
                'options' => ['show-help' => true, 'show-ref' => false],
                'withHelp' => true,
                'withRef' => false,
            ],
            'show-ref enabled' => [
                'options' => ['show-help' => false, 'show-ref' => true],
                'withHelp' => false,
                'withRef' => true,
            ],
            'all options enabled' => [
                'options' => ['show-help' => true, 'show-ref' => true],
                'withHelp' => true,
                'withRef' => true,
            ],
            'all options disabled' => [
                'options' => ['show-help' => false, 'show-ref' => false],
                'withHelp' => false,
                'withRef' => false,
            ],
        ];
    }

    /**
     * Verifies that the generate method produces a correct GitLab Code Quality JSON string.
     *
     * @param array<string, bool> $options The options to pass to the formatter.
     * @param bool $withHelp Whether the help text should be in the message.
     * @param bool $withRef Whether the ref link should be in the message.
     */
    #[DataProvider('generateProvider')]
    public function testGenerate(array $options, bool $withHelp, bool $withRef): void
    {
        $this->formatter->setOptions($options);
        $report = $this->getFixedTestReport();
        $jsonOutput = $this->formatter->generate($report);

        $data = json_decode($jsonOutput, true);
        $this->assertIsArray($data);

        $reportIssues = $report->getIssues(false);
        $this->assertCount(count($reportIssues), $data, 'The number of issues in JSON should match the report.');

        // Sort both arrays to ensure consistent order for comparison
        usort($reportIssues, fn(Issue $a, Issue $b) => strcmp($a->getPath() . $a->getLine(), $b->getPath() . $b->getLine()));
        usort($data, fn(array $a, array $b) => strcmp($a['location']['path'] . $a['location']['lines']['begin'], $b['location']['path'] . $b['location']['lines']['begin']));

        foreach ($reportIssues as $i => $issue) {
            $gitlabIssue = $data[$i];

            // Build expected description based on options
            $expectedDescription = "{$issue->getSeverityString()}";
            if ($issue->getLine()) {
                $expectedDescription .= " on line {$issue->getLine()}";
            }
            if ($issue->getColumn()) {
                $expectedDescription .= ":{$issue->getColumn()}";
            }
            $expectedDescription .= " - {$issue->getMessage()}";

            if ($withHelp && $issue->getHelp()) {
                $expectedDescription .= "  ({$issue->getHelp()})";
            }
            if ($withRef && $issue->getRef()) {
                $expectedDescription .= "  [{$issue->getRef()}]";
            }

            $this->assertEquals($expectedDescription, $gitlabIssue['description']);
            $this->assertEquals($issue->getCode(), $gitlabIssue['check_name']);
            $this->assertEquals($issue->getRelativePath(), $gitlabIssue['location']['path']);
            $this->assertEquals($issue->getLine(), $gitlabIssue['location']['lines']['begin']);

            // Test severity mapping
            $expectedSeverity = match ($issue->getSeverity()) {
                Issue::SEVERITY_ERROR => 'major',
                Issue::SEVERITY_WARNING => 'minor',
                Issue::SEVERITY_TIP => 'info',
                default => 'info', // This case shouldn't be hit with test data
            };
            $this->assertEquals($expectedSeverity, $gitlabIssue['severity']);

            // The fingerprint is non-deterministic due to `getTime()` in the Issue constructor.
            // We can only check if it's a valid MD5 hash. A better implementation would use
            // deterministic data for the fingerprint.
            $this->assertArrayHasKey('fingerprint', $gitlabIssue);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $gitlabIssue['fingerprint']);
        }
    }

    /**
     * Data provider for testing the parse method with different options.
     *
     * @return array<string, array{options: array<string, bool>, input: string, expectedIssues: array<int, array<string, mixed>>}>
     */
    public static function parseProvider(): array
    {
        $parsableDescription = 'WARNING on line 20 - This is a complex message.  (with help text)  [with a ref link]';
        $simpleDescription = 'This is a simple error message.';

        $jsonInput = <<<JSON
[
    {
        "description": "{$simpleDescription}",
        "check_name": "Some.Error.Rule",
        "fingerprint": "f1",
        "severity": "major",
        "location": {
            "path": "src/File1.php",
            "lines": { "begin": 10 }
        }
    },
    {
        "description": "{$parsableDescription}",
        "check_name": "Some.Warning.Rule",
        "fingerprint": "f2",
        "severity": "minor",
        "location": {
            "path": "src/File2.php",
            "lines": { "begin": 20 }
        }
    }
]
JSON;

        return [
            'Default options (parse-message enabled)' => [
                'options' => [], // 'parse-message' defaults to true
                'input' => $jsonInput,
                'expectedIssues' => [
                    [
                        'message' => 'This is a simple error message.',
                        'line' => 10,
                        'path' => 'src/File1.php',
                        'code' => 'Some.Error.Rule',
                        'severity' => Issue::SEVERITY_ERROR,
                        'help' => '',
                        'ref' => '',
                    ],
                    [
                        'message' => 'This is a complex message.',
                        'line' => 20,
                        'path' => 'src/File2.php',
                        'code' => 'Some.Warning.Rule',
                        'severity' => Issue::SEVERITY_WARNING,
                        'help' => 'with help text',
                        'ref' => 'with a ref link',
                    ],
                ],
            ],
            'parse-message disabled' => [
                'options' => ['parse-message' => false],
                'input' => $jsonInput,
                'expectedIssues' => [
                    [
                        'message' => $simpleDescription,
                        'line' => 10,
                        'path' => 'src/File1.php',
                        'code' => 'Some.Error.Rule',
                        'severity' => Issue::SEVERITY_ERROR,
                        'help' => '',
                        'ref' => '',
                    ],
                    [
                        'message' => $parsableDescription,
                        'line' => 20,
                        'path' => 'src/File2.php',
                        'code' => 'Some.Warning.Rule',
                        'severity' => Issue::SEVERITY_WARNING,
                        'help' => '',
                        'ref' => '',
                    ],
                ],
            ],
            'Location with begin as object (CodeClimate compatibility)' => [
                'options' => [],
                'input' => <<<JSON
[
    {
        "description": "test",
        "check_name": "Rule",
        "fingerprint": "f-obj",
        "severity": "minor",
        "location": {
            "path": "src/File.php",
            "lines": { "begin": { "line": 55, "column": 1 } }
        }
    }
]
JSON,
                'expectedIssues' => [
                    [
                        'message' => 'test',
                        'line' => 55,
                        'path' => 'src/File.php',
                        'code' => 'Rule',
                        'severity' => Issue::SEVERITY_WARNING,
                        'help' => '',
                        'ref' => '',
                    ],
                ],
            ],
            'Empty report' => [
                'options' => [],
                'input' => '[]',
                'expectedIssues' => [],
            ],
        ];
    }

    /**
     * Verifies that the parse method correctly constructs a Report object from a GitLab JSON string.
     *
     * @param array<string, bool> $options The options to pass to the formatter.
     * @param string $input The JSON string to parse.
     * @param array<int, array<string, mixed>> $expectedIssues The expected issue data.
     */
    #[DataProvider('parseProvider')]
    public function testParse(array $options, string $input, array $expectedIssues): void
    {
        $this->formatter->setOptions($options);
        $report = $this->formatter->parse($input, 'Parsed GitLab Report');
        $this->assertInstanceOf(Report::class, $report);
        $this->assertEquals('Parsed GitLab Report', $report->getName());

        $issues = $report->getIssues(false, false);
        $this->assertCount(count($expectedIssues), $issues, "The number of parsed issues should match the expected count.");

        if (empty($expectedIssues)) {
            return; // Nothing more to check
        }

        // Sort both for predictable comparison
        usort($issues, fn(Issue $a, Issue $b) => ($a->getPath() . $a->getLine()) <=> ($b->getPath() . $b->getLine()));
        usort($expectedIssues, fn(array $a, array $b) => ($a['path'] . $a['line']) <=> ($b['path'] . $b['line']));

        foreach ($expectedIssues as $index => $expected) {
            $actualIssue = $issues[$index];
            $this->assertEquals($expected['message'], $actualIssue->getMessage(), "Message mismatch for issue at index $index.");
            $this->assertEquals($expected['line'], $actualIssue->getLine(), "Line mismatch for issue at index $index.");
            $this->assertEquals($expected['path'], $actualIssue->getPath(), "Path mismatch for issue at index $index.");
            $this->assertEquals($expected['code'], $actualIssue->getCode(), "Code mismatch for issue at index $index.");
            $this->assertEquals($expected['severity'], $actualIssue->getSeverity(), "Severity mismatch for issue at index $index.");
            $this->assertEquals($expected['help'] ?? '', $actualIssue->getHelp(), "Help mismatch for issue at index $index.");
            $this->assertEquals($expected['ref'] ?? '', $actualIssue->getRef(), "Ref mismatch for issue at index $index.");
        }
    }

    /**
     * Data provider for testing parsing of invalid or incomplete GitLab issue structures.
     *
     * @return array<string, array{input: string, exceptionMessage: string}>
     */
    public static function invalidStructureProvider(): array
    {
        return [
            'missing location' => [
                'input' => '[{"description": "d", "severity": "s", "fingerprint": "f1"}]',
                'exceptionMessage' => 'Invalid JSON input: location array is empty for issue: f1',
            ],
            'missing location path' => [
                'input' => '[{"description": "d", "severity": "s", "location": {"lines": {"begin": 1}}, "fingerprint": "f2"}]',
                'exceptionMessage' => 'Invalid JSON input: location:path is empty for issue: f2',
            ],
            'missing severity' => [
                'input' => '[{"description": "d", "location": {"path": "p"}, "fingerprint": "f3"}]',
                'exceptionMessage' => 'Invalid JSON input: severity is empty for issue: f3',
            ],
            'missing description' => [
                'input' => '[{"severity": "s", "location": {"path": "p"}, "fingerprint": "f4"}]',
                'exceptionMessage' => 'Invalid JSON input: description is empty for issue: f4',
            ],
            'not an array of issues' => [
                'input' => '"value"',
                'exceptionMessage' => 'Invalid JSON input: Decoded JSON is not in the expected format (expected array of issues).',
            ],
        ];
    }

    /**
     * Verifies that the parse method throws an exception for structurally invalid GitLab JSON.
     *
     * @param string $input The invalid JSON string.
     * @param string $exceptionMessage The expected exception message.
     */
    #[DataProvider('invalidStructureProvider')]
    public function testParseThrowsExceptionForInvalidStructure(string $input, string $exceptionMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($exceptionMessage);
        $this->formatter->parse($input);
    }
}