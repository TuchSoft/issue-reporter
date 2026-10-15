<?php

namespace Tuchsoft\IssueReporter\Test\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\Sarif;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Test\Base\AbstractJsonFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\JsonOptionsProvider;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;

/**
 * Adversarial tests for the SARIF 2.1.0 format.
 *
 * Extends {@see AbstractJsonFormatTest}, which brings in the large round-trip
 * matrix (`testRoundtrip`), `testSupport`, the invalid-JSON test and the JSON
 * formatting-option test. On top of that this class adds format-specific and
 * hostile input coverage.
 */
#[CoversClass(\Tuchsoft\IssueReporter\Format\Sarif::class)]
#[Group('Sarif')]
class SarifTest extends AbstractJsonFormatTest
{
    use ReportProvider;
    use JsonOptionsProvider;

    /** @var Sarif $formatter */
    protected FormatInterface $formatter;

    /** @var class-string<Sarif> */
    protected static string $formatterClass = Sarif::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new Sarif();
    }

    /**
     * Expected internal-severity => SARIF level mapping, hardcoded on purpose so
     * the assertion is independent from the production constant.
     */
    private const EXPECTED_LEVEL_MAP = [
        Issue::SEVERITY_ERROR => 'error',
        Issue::SEVERITY_WARNING => 'warning',
        Issue::SEVERITY_TIP => 'note',
    ];

    // ---------------------------------------------------------------------
    // generate()
    // ---------------------------------------------------------------------

    /**
     * Option combinations for the generator. The SARIF generator serializes the
     * native help/ref fields unconditionally, so the whole structure must stay
     * identical regardless of the negotiated options.
     *
     * @return array<string, array{options: array<string, mixed>}>
     */
    public static function generateProvider(): array
    {
        return [
            'default options' => ['options' => []],
            'show-help + show-ref' => ['options' => ['show-help' => true, 'show-ref' => true]],
            'no-show-help + no-show-ref' => ['options' => ['show-help' => false, 'show-ref' => false]],
            'pretty enabled' => ['options' => ['pretty' => true]],
            'pretty disabled' => ['options' => ['pretty' => false]],
            'no escape slash/unicode' => ['options' => ['escape-slash' => false, 'escape-unicode' => false]],
        ];
    }

    /**
     * Decodes the generated document and asserts the exact SARIF 2.1.0 structure.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('generateProvider')]
    public function testGenerateProducesExactSarifStructure(array $options): void
    {
        $this->formatter->setOptions($options);
        $report = $this->getFixedTestReport();

        $json = $this->formatter->generate($report);
        $this->assertJson($json);

        $data = json_decode($json, true);
        $this->assertIsArray($data);

        // --- Top level ---
        $this->assertSame(
            'https://schemastore.azurewebsites.net/schemas/json/sarif-2.1.0-rtm.5.json',
            $data['$schema']
        );
        $this->assertSame('2.1.0', $data['version']);
        $this->assertArrayHasKey('runs', $data);
        $this->assertCount(1, $data['runs']);

        $run = $data['runs'][0];

        // --- tool.driver ---
        $driver = $run['tool']['driver'];
        $this->assertSame('MoodleChecklist', $driver['name']);
        $this->assertSame('1.0.0', $driver['semanticVersion']);
        $this->assertArrayHasKey('rules', $driver);
        $this->assertArrayHasKey('results', $run);

        // --- rules (one per unique code, in order of first appearance) ---
        $flatIssues = $report->getIssues(false);

        $expectedRules = [];
        foreach ($flatIssues as $issue) {
            if (!isset($expectedRules[$issue->getCode()])) {
                $expectedRules[$issue->getCode()] = $issue;
            }
        }

        $this->assertCount(count($expectedRules), $driver['rules']);

        $ruleIndex = 0;
        foreach ($expectedRules as $code => $issue) {
            $rule = $driver['rules'][$ruleIndex++];

            $this->assertSame($code, $rule['id']);
            $this->assertSame($code, $rule['name']);
            $this->assertSame($issue->getMessage(), $rule['fullDescription']['text']);
            $this->assertSame(
                self::EXPECTED_LEVEL_MAP[$issue->getSeverity()],
                $rule['defaultConfiguration']['level']
            );

            if ($issue->getRef() !== '') {
                $this->assertSame($issue->getRef(), $rule['helpUri']);
            } else {
                $this->assertArrayNotHasKey('helpUri', $rule);
            }

            if ($issue->getHelp() !== '') {
                $this->assertSame($issue->getHelp(), $rule['help']['text']);
            } else {
                $this->assertArrayNotHasKey('help', $rule);
            }
        }

        // --- results (one per issue, grouped by file) ---
        $expectedResults = [];
        foreach ($report->getIssues() as $path => $issues) {
            foreach ($issues as $issue) {
                $expectedResults[] = [$path, $issue];
            }
        }

        $this->assertCount(count($expectedResults), $run['results']);

        foreach ($expectedResults as $i => [$path, $issue]) {
            $result = $run['results'][$i];

            $this->assertSame($issue->getCode(), $result['ruleId']);
            $this->assertSame($issue->getMessage(), $result['message']['text']);
            $this->assertSame(self::EXPECTED_LEVEL_MAP[$issue->getSeverity()], $result['level']);

            $location = $result['locations'][0]['physicalLocation'];
            $this->assertSame($path, $location['artifactLocation']['uri']);

            $region = $location['region'];
            $this->assertSame($issue->getLine(), $region['startLine']);
            $this->assertSame($issue->getColumn(), $region['startColumn']);
            $this->assertSame($issue->getLine(), $region['endLine']);
            $this->assertSame($issue->getColumn(), $region['endColumn']);
        }
    }

    /**
     * Generating an empty (but started) report must still emit a valid document
     * with empty rules/results arrays.
     */
    public function testGenerateEmptyReportProducesValidEmptySarif(): void
    {
        $report = (new Report('Empty Report', '/project/base'))->start();
        $report->complete();

        $json = $this->formatter->generate($report);
        $this->assertJson($json);

        $data = json_decode($json, true);
        $this->assertSame('2.1.0', $data['version']);
        $this->assertCount(1, $data['runs']);
        $this->assertSame([], $data['runs'][0]['tool']['driver']['rules']);
        $this->assertSame([], $data['runs'][0]['results']);
    }

    // ---------------------------------------------------------------------
    // parse()
    // ---------------------------------------------------------------------

    /**
     * Minimal well-formed SARIF input. Parsing MUST succeed.
     *
     * Regression guard for the missing `basePath` in the report data handed to
     * {@see Report::fromJson()}.
     */
    public function testParseMinimalDocumentDoesNotThrow(): void
    {
        $report = $this->formatter->parse('{"version":"2.1.0","runs":[]}', 'Minimal');

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('Minimal', $report->getName());
        $this->assertSame([], $report->getIssues(false, false));
    }

    /**
     * When no name is supplied the default report name is used.
     */
    public function testParseUsesDefaultReportName(): void
    {
        $report = $this->formatter->parse('{"version":"2.1.0","runs":[]}');
        $this->assertSame('Parsed sarif report', $report->getName());
    }

    /**
     * Valid and hostile-but-parseable SARIF documents.
     *
     * Every entry MUST result in a successful {@see Report} whose issues exactly
     * match the `expectedIssues` list (in encounter order).
     *
     * @return array<string, array{input: string, expectedIssues: array<int, array<string, mixed>>, name: string}>
     */
    public static function parseProvider(): array
    {
        $basic = <<<'JSON'
{
    "version": "2.1.0",
    "runs": [
        {
            "tool": {
                "driver": {
                    "name": "tool",
                    "rules": [
                        {
                            "id": "R1",
                            "fullDescription": { "text": "Rule message" },
                            "defaultConfiguration": { "level": "error" },
                            "helpUri": "https://example.com/R1",
                            "help": { "text": "Do this" }
                        }
                    ]
                }
            },
            "results": [
                {
                    "ruleId": "R1",
                    "message": { "text": "Rule message" },
                    "level": "error",
                    "locations": [
                        {
                            "physicalLocation": {
                                "artifactLocation": { "uri": "src/a.php" },
                                "region": { "startLine": 3, "startColumn": 7 }
                            }
                        }
                    ]
                }
            ]
        }
    ]
}
JSON;

        $multipleRuns = <<<'JSON'
{
    "version": "2.1.0",
    "runs": [
        {
            "tool": { "driver": { "rules": [] } },
            "results": [
                { "ruleId": "A", "message": { "text": "first" }, "level": "warning",
                  "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/one.php" }, "region": { "startLine": 1, "startColumn": 2 } } } ] }
            ]
        },
        {
            "tool": { "driver": { "rules": [] } },
            "results": [
                { "ruleId": "B", "message": { "text": "second" }, "level": "note",
                  "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/two.php" }, "region": { "startLine": 9, "startColumn": 4 } } } ] }
            ]
        }
    ]
}
JSON;

        $noRegion = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "N", "message": { "text": "no region" }, "level": "warning",
              "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/noregion.php" } } } ] }
          ] }
    ]
}
JSON;

        $noPhysicalLocation = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "P", "message": { "text": "no location" }, "level": "error",
              "locations": [ {} ] }
          ] }
    ]
}
JSON;

        $missingOptional = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/x.php" } } } ] }
          ] }
    ]
}
JSON;

        $unknownLevel = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "U", "message": { "text": "unknown level" }, "level": "none",
              "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/u.php" }, "region": { "startLine": 1, "startColumn": 1 } } } ] }
          ] }
    ]
}
JSON;

        $unicode = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "UNI", "message": { "text": "Un café — 日本語" }, "level": "warning",
              "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/ünïcode/файл.php" }, "region": { "startLine": 2, "startColumn": 1 } } } ] }
          ] }
    ]
}
JSON;

        $escaped = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "ESC", "message": { "text": "He said \"hi\"\nand \\ backslash" }, "level": "warning",
              "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/esc.php" }, "region": { "startLine": 1, "startColumn": 1 } } } ] }
          ] }
    ]
}
JSON;

        $unknownFields = <<<'JSON'
{
    "version": "2.1.0",
    "unexpectedRoot": { "foo": "bar" },
    "runs": [
        {
            "unexpectedRun": 123,
            "tool": { "driver": { "rules": [ { "id": "K", "unexpectedRule": true } ] } },
            "results": [
                { "ruleId": "K", "message": { "text": "known" }, "level": "warning", "unexpectedResult": [1, 2, 3],
                  "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/k.php" }, "region": { "startLine": 5, "startColumn": 5 } } } ] }
            ]
        }
    ]
}
JSON;

        $zero = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "Z", "message": { "text": "zero" }, "level": "warning",
              "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/z.php" }, "region": { "startLine": 0, "startColumn": 0 } } } ] }
          ] }
    ]
}
JSON;

        $negative = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "NEG", "message": { "text": "negative" }, "level": "warning",
              "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/neg.php" }, "region": { "startLine": -5, "startColumn": -2 } } } ] }
          ] }
    ]
}
JSON;

        $hugeLine = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "HUGE", "message": { "text": "huge" }, "level": "warning",
              "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/huge.php" }, "region": { "startLine": 2147483647, "startColumn": 1 } } } ] }
          ] }
    ]
}
JSON;

        $onlyStartLine = <<<'JSON'
{
    "runs": [
        { "tool": { "driver": { "rules": [] } },
          "results": [
            { "ruleId": "PART", "message": { "text": "partial" }, "level": "warning",
              "locations": [ { "physicalLocation": { "artifactLocation": { "uri": "src/p.php" }, "region": { "startLine": 42 } } } ] }
          ] }
    ]
}
JSON;

        $longMessage = str_repeat('x', 5000);
        $veryLong = '{"runs":[{"tool":{"driver":{"rules":[]}},"results":[{"ruleId":"L","message":{"text":"' . $longMessage . '"},"level":"warning","locations":[{"physicalLocation":{"artifactLocation":{"uri":"src/l.php"},"region":{"startLine":1,"startColumn":1}}}]}]}]}';

        return [
            'valid: single result with full rule info' => [
                'input' => $basic,
                'expectedIssues' => [
                    ['message' => 'Rule message', 'path' => 'src/a.php', 'line' => 3, 'column' => 7,
                     'code' => 'R1', 'severity' => Issue::SEVERITY_ERROR, 'help' => 'Do this', 'ref' => 'https://example.com/R1'],
                ],
                'name' => 'Basic',
            ],
            'valid: no results' => [
                'input' => '{"version":"2.1.0","runs":[{"tool":{"driver":{"rules":[]}},"results":[]}]}',
                'expectedIssues' => [],
                'name' => 'Empty',
            ],
            'valid: multiple runs' => [
                'input' => $multipleRuns,
                'expectedIssues' => [
                    ['message' => 'first', 'path' => 'src/one.php', 'line' => 1, 'column' => 2,
                     'code' => 'A', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                    ['message' => 'second', 'path' => 'src/two.php', 'line' => 9, 'column' => 4,
                     'code' => 'B', 'severity' => Issue::SEVERITY_TIP, 'help' => '', 'ref' => ''],
                ],
                'name' => 'Multi',
            ],
            'edge: result without region defaults to line/col 1' => [
                'input' => $noRegion,
                'expectedIssues' => [
                    ['message' => 'no region', 'path' => 'src/noregion.php', 'line' => 1, 'column' => 1,
                     'code' => 'N', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'NoRegion',
            ],
            'edge: missing physicalLocation falls back to unknown_file' => [
                'input' => $noPhysicalLocation,
                'expectedIssues' => [
                    ['message' => 'no location', 'path' => 'unknown_file', 'line' => 1, 'column' => 1,
                     'code' => 'P', 'severity' => Issue::SEVERITY_ERROR, 'help' => '', 'ref' => ''],
                ],
                'name' => 'NoLocation',
            ],
            'edge: missing ruleId/message/level uses defaults' => [
                'input' => $missingOptional,
                'expectedIssues' => [
                    ['message' => 'No message provided', 'path' => 'src/x.php', 'line' => 1, 'column' => 1,
                     'code' => 'Unknown', 'severity' => Issue::SEVERITY_TIP, 'help' => '', 'ref' => ''],
                ],
                'name' => 'MissingOptional',
            ],
            'edge: unknown level falls back to warning' => [
                'input' => $unknownLevel,
                'expectedIssues' => [
                    ['message' => 'unknown level', 'path' => 'src/u.php', 'line' => 1, 'column' => 1,
                     'code' => 'U', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'UnknownLevel',
            ],
            'hostile: unicode content' => [
                'input' => $unicode,
                'expectedIssues' => [
                    ['message' => 'Un café — 日本語', 'path' => 'src/ünïcode/файл.php', 'line' => 2, 'column' => 1,
                     'code' => 'UNI', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'Unicode',
            ],
            'hostile: escaped quotes/backslash/newline' => [
                'input' => $escaped,
                'expectedIssues' => [
                    ['message' => "He said \"hi\"\nand \\ backslash", 'path' => 'src/esc.php', 'line' => 1, 'column' => 1,
                     'code' => 'ESC', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'Escaped',
            ],
            'hostile: unknown/additional fields are ignored' => [
                'input' => $unknownFields,
                'expectedIssues' => [
                    ['message' => 'known', 'path' => 'src/k.php', 'line' => 5, 'column' => 5,
                     'code' => 'K', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'Extra',
            ],
            'hostile: zero line and column are preserved' => [
                'input' => $zero,
                'expectedIssues' => [
                    ['message' => 'zero', 'path' => 'src/z.php', 'line' => 0, 'column' => 0,
                     'code' => 'Z', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'Zero',
            ],
            'hostile: negative line and column are preserved' => [
                'input' => $negative,
                'expectedIssues' => [
                    ['message' => 'negative', 'path' => 'src/neg.php', 'line' => -5, 'column' => -2,
                     'code' => 'NEG', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'Negative',
            ],
            'hostile: huge line number' => [
                'input' => $hugeLine,
                'expectedIssues' => [
                    ['message' => 'huge', 'path' => 'src/huge.php', 'line' => 2147483647, 'column' => 1,
                     'code' => 'HUGE', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'Huge',
            ],
            'hostile: region with only startLine defaults column to 1' => [
                'input' => $onlyStartLine,
                'expectedIssues' => [
                    ['message' => 'partial', 'path' => 'src/p.php', 'line' => 42, 'column' => 1,
                     'code' => 'PART', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'PartialRegion',
            ],
            'hostile: very long message' => [
                'input' => $veryLong,
                'expectedIssues' => [
                    ['message' => $longMessage, 'path' => 'src/l.php', 'line' => 1, 'column' => 1,
                     'code' => 'L', 'severity' => Issue::SEVERITY_WARNING, 'help' => '', 'ref' => ''],
                ],
                'name' => 'Long',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $expectedIssues
     */
    #[DataProvider('parseProvider')]
    public function testParse(string $input, array $expectedIssues, string $name): void
    {
        $report = $this->formatter->parse($input, $name);

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame($name, $report->getName());

        $issues = $report->getIssues(false, false);
        $this->assertCount(count($expectedIssues), $issues, 'Parsed issue count mismatch.');

        foreach (array_values($expectedIssues) as $i => $expected) {
            $issue = $issues[$i];
            $this->assertSame($expected['message'], $issue->getMessage(), "message mismatch @$i");
            $this->assertSame($expected['path'], $issue->getPath(), "path mismatch @$i");
            $this->assertSame($expected['line'], $issue->getLine(), "line mismatch @$i");
            $this->assertSame($expected['column'], $issue->getColumn(), "column mismatch @$i");
            $this->assertSame($expected['code'], $issue->getCode(), "code mismatch @$i");
            $this->assertSame($expected['severity'], $issue->getSeverity(), "severity mismatch @$i");
            $this->assertSame($expected['help'], $issue->getHelp(), "help mismatch @$i");
            $this->assertSame($expected['ref'], $issue->getRef(), "ref mismatch @$i");
        }
    }

    // ---------------------------------------------------------------------
    // parse() - hostile / malformed input
    // ---------------------------------------------------------------------

    /**
     * Inputs that must be rejected with an {@see \InvalidArgumentException}.
     *
     * @return array<string, array{input: string, exceptionMessage: string}>
     */
    public static function invalidSarifProvider(): array
    {
        return [
            'empty string' => [
                'input' => '',
                'exceptionMessage' => 'Invalid JSON input: Syntax error',
            ],
            'whitespace only' => [
                'input' => "   \n\t ",
                'exceptionMessage' => 'Invalid JSON input: Syntax error',
            ],
            'malformed json' => [
                'input' => '{"runs": [',
                'exceptionMessage' => 'Invalid JSON input: Syntax error',
            ],
            'plain text' => [
                'input' => 'this is not json',
                'exceptionMessage' => 'Invalid JSON input: Syntax error',
            ],
            'json null' => [
                'input' => 'null',
                'exceptionMessage' => 'Decoded JSON is not a valid SARIF format.',
            ],
            'json scalar string' => [
                'input' => '"value"',
                'exceptionMessage' => 'Decoded JSON is not a valid SARIF format.',
            ],
            'json scalar int' => [
                'input' => '42',
                'exceptionMessage' => 'Decoded JSON is not a valid SARIF format.',
            ],
            'json empty array' => [
                'input' => '[]',
                'exceptionMessage' => 'Decoded JSON is not a valid SARIF format.',
            ],
            'missing runs key' => [
                'input' => '{"version":"2.1.0"}',
                'exceptionMessage' => 'Decoded JSON is not a valid SARIF format.',
            ],
            'runs is not an array' => [
                'input' => '{"runs":"nope"}',
                'exceptionMessage' => 'Decoded JSON is not a valid SARIF format.',
            ],
        ];
    }

    #[DataProvider('invalidSarifProvider')]
    public function testParseThrowsExceptionForInvalidSarif(string $input, string $exceptionMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($exceptionMessage);
        $this->formatter->parse($input);
    }
}
