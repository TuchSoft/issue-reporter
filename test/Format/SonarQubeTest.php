<?php

namespace Tuchsoft\IssueReporter\Test\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\SonarQube;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Test\Base\AbstractJsonFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\JsonOptionsProvider;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;

/**
 * Adversarial tests for the SonarQube Generic Issue Report format.
 *
 * Extending {@see AbstractJsonFormatTest} brings in the large round-trip matrix
 * (testRoundtrip), the support test, the invalid-JSON test and the JSON
 * formatting-option test. Everything below adds SonarQube-specific structural
 * assertions and hostile input coverage.
 */
#[CoversClass(SonarQube::class)]
#[Group('SonarQube')]
class SonarQubeTest extends AbstractJsonFormatTest
{
    use ReportProvider;
    use JsonOptionsProvider;

    /** @var SonarQube $formatter */
    protected FormatInterface $formatter;

    /** @var class-string<SonarQube> */
    protected static string $formatterClass = SonarQube::class;

    /**
     * Sets up the test environment with default SonarQube options.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new SonarQube();
    }

    /**
     * Builds a deterministic report with one issue per severity and distinct
     * line/column values, so that generate() output can be asserted exactly.
     */
    private function getSonarQubeReport(): Report
    {
        $report = new Report('SonarQube Report', '/project/base');
        $report->start();
        $report->addIssues(
            new Issue('php:S1001', Issue::SEVERITY_ERROR, 'This is a blocker bug.', 'src/File1.php', 10, 5),
            new Issue('php:S1002', Issue::SEVERITY_WARNING, 'This is a code smell.', 'src/File1.php', 20, 1),
            new Issue('php:S1003', Issue::SEVERITY_TIP, 'This is a minor smell.', 'src/File2.php', 0, 0),
        );
        $report->complete();

        return $report;
    }

    /**
     * The exact decoded structure that generate() is expected to produce for
     * {@see getSonarQubeReport()}. Paths are resolved against the report base path.
     *
     * @return array{issues: array<int, array<string, mixed>>}
     */
    private function getExpectedDecodedStructure(): array
    {
        return [
            'issues' => [
                [
                    'engineId' => 'MoodleChecklist',
                    'ruleId' => 'php:S1001',
                    'primaryLocation' => [
                        'message' => 'This is a blocker bug.',
                        'filePath' => '/project/base/src/File1.php',
                        'textRange' => [
                            'startLine' => 10,
                            'endLine' => 10,
                            'startColumn' => 5,
                            'endColumn' => 5,
                        ],
                    ],
                    'type' => 'BUG',
                    'severity' => 'BLOCKER',
                ],
                [
                    'engineId' => 'MoodleChecklist',
                    'ruleId' => 'php:S1002',
                    'primaryLocation' => [
                        'message' => 'This is a code smell.',
                        'filePath' => '/project/base/src/File1.php',
                        'textRange' => [
                            'startLine' => 20,
                            'endLine' => 20,
                            'startColumn' => 1,
                            'endColumn' => 1,
                        ],
                    ],
                    'type' => 'CODE_SMELL',
                    'severity' => 'MAJOR',
                ],
                [
                    'engineId' => 'MoodleChecklist',
                    'ruleId' => 'php:S1003',
                    'primaryLocation' => [
                        'message' => 'This is a minor smell.',
                        'filePath' => '/project/base/src/File2.php',
                        'textRange' => [
                            'startLine' => 0,
                            'endLine' => 0,
                            'startColumn' => 0,
                            'endColumn' => 0,
                        ],
                    ],
                    'type' => 'CODE_SMELL',
                    'severity' => 'MINOR',
                ],
            ],
        ];
    }

    /**
     * Option combinations for generate(); pretty/slash expectations are decoded
     * from the JsonFormatTrait semantics (options are read from $this->options,
     * the flags passed inline to jsonEncode() are ignored).
     *
     * @return array<string, array{options: array<string, bool>, pretty: bool, unescapedSlash: bool}>
     */
    public static function generateProvider(): array
    {
        return [
            'defaults' => [
                'options' => [],
                'pretty' => false,
                'unescapedSlash' => false,
            ],
            'pretty enabled' => [
                'options' => ['pretty' => true],
                'pretty' => true,
                'unescapedSlash' => false,
            ],
            'pretty explicitly disabled' => [
                'options' => ['pretty' => false],
                'pretty' => false,
                'unescapedSlash' => false,
            ],
            'slashes unescaped' => [
                'options' => ['escape-slash' => false],
                'pretty' => false,
                'unescapedSlash' => true,
            ],
            'pretty + slashes unescaped' => [
                'options' => ['pretty' => true, 'escape-slash' => false],
                'pretty' => true,
                'unescapedSlash' => true,
            ],
            'help/ref options must not affect output' => [
                'options' => ['show-help' => true, 'show-ref' => true],
                'pretty' => false,
                'unescapedSlash' => false,
            ],
        ];
    }

    /**
     * Verifies the exact generated JSON structure, including the severity/type
     * mappings and the textRange fields, for every option combination.
     */
    #[DataProvider('generateProvider')]
    public function testGenerateStructure(array $options, bool $pretty, bool $unescapedSlash): void
    {
        $this->formatter->setOptions($options);
        $jsonOutput = $this->formatter->generate($this->getSonarQubeReport());

        $this->assertJson($jsonOutput);

        $data = json_decode($jsonOutput, true);
        $this->assertIsArray($data);

        // Top level must be exactly { "issues": [...] } - no "components", no extras.
        $this->assertSame(['issues'], array_keys($data));

        $expected = $this->getExpectedDecodedStructure();
        $this->assertEquals($expected, $data, 'The generated SonarQube structure must match exactly.');

        // Every issue must carry exactly these keys, in this order.
        foreach ($data['issues'] as $issue) {
            $this->assertSame(
                ['engineId', 'ruleId', 'primaryLocation', 'type', 'severity'],
                array_keys($issue),
                'Issue keys/order must match the SonarQube contract.'
            );
            $this->assertSame(
                ['message', 'filePath', 'textRange'],
                array_keys($issue['primaryLocation']),
                'primaryLocation keys/order must match the SonarQube contract.'
            );
            $this->assertSame(
                ['startLine', 'endLine', 'startColumn', 'endColumn'],
                array_keys($issue['primaryLocation']['textRange']),
                'textRange keys/order must match the SonarQube contract.'
            );
            // endLine must mirror startLine when only one line is known.
            $this->assertSame(
                $issue['primaryLocation']['textRange']['startLine'],
                $issue['primaryLocation']['textRange']['endLine']
            );
            $this->assertSame(
                $issue['primaryLocation']['textRange']['startColumn'],
                $issue['primaryLocation']['textRange']['endColumn']
            );
        }

        // Pretty printing behavior.
        if ($pretty) {
            $this->assertMatchesRegularExpression('/^\{[\r\n]/', $jsonOutput);
        } else {
            $this->assertDoesNotMatchRegularExpression('/^\{[\r\n]/', $jsonOutput);
        }

        // Slash escaping behavior (the file path is present in every issue).
        if ($unescapedSlash) {
            $this->assertStringContainsString('src/File1.php', $jsonOutput);
        } else {
            $this->assertStringNotContainsString('src/File1.php', $jsonOutput);
            $this->assertStringContainsString('src\/File1.php', $jsonOutput);
        }
    }

    /**
     * An empty report must still produce a syntactically valid SonarQube document.
     */
    public function testGenerateEmptyReport(): void
    {
        $report = new Report('Empty', '/project/base');
        $report->start();
        $report->complete();

        $this->formatter->setOptions(['pretty' => true]);
        $data = json_decode($this->formatter->generate($report), true);

        $this->assertSame(['issues' => []], $data);
    }

    /**
     * JSON-escaping of hostile message content (quotes, backslashes, newlines,
     * control characters) must survive a round-trip through json_decode().
     */
    public function testGenerateEscapesSpecialCharactersInMessages(): void
    {
        $message = "He said \"hi\" and used \\ a backslash\nand a tab\there.";

        $report = new Report('Escaping', '/project/base');
        $report->start();
        $report->addIssue(new Issue('php:S1', Issue::SEVERITY_ERROR, $message, 'src/A.php', 1, 1));
        $report->complete();

        $this->formatter->setOptions(['pretty' => false, 'escape-slash' => false, 'escape-unicode' => true]);
        $jsonOutput = $this->formatter->generate($report);

        // Raw output must use the JSON escape sequences.
        $this->assertStringContainsString('\"hi\"', $jsonOutput);
        $this->assertStringContainsString('\\\\', $jsonOutput);
        $this->assertStringContainsString('\n', $jsonOutput);
        $this->assertStringContainsString('\t', $jsonOutput);

        // Decoding must return the exact original (trimmed) message.
        $data = json_decode($jsonOutput, true);
        $this->assertSame(trim($message), $data['issues'][0]['primaryLocation']['message']);
    }

    /**
     * Unicode escaping is controlled by the "escape-unicode" option.
     *
     * @return array<string, array{escapeUnicode: bool, expected: string}>
     */
    public static function unicodeProvider(): array
    {
        return [
            'escaped by default' => [
                'escapeUnicode' => true,
                'expected' => 'u00e9',
            ],
            'raw unicode' => [
                'escapeUnicode' => false,
                'expected' => 'é',
            ],
        ];
    }

    #[DataProvider('unicodeProvider')]
    public function testGenerateUnicodeEscaping(bool $escapeUnicode, string $expected): void
    {
        $report = new Report('Unicode', '/project/base');
        $report->start();
        $report->addIssue(new Issue('php:S1', Issue::SEVERITY_ERROR, 'Café déjà vu', 'src/A.php', 1, 1));
        $report->complete();

        $this->formatter->setOptions(['pretty' => false, 'escape-unicode' => $escapeUnicode]);
        $jsonOutput = $this->formatter->generate($report);

        $this->assertStringContainsString($expected, $jsonOutput);

        // Decoding always yields the real character.
        $data = json_decode($jsonOutput, true);
        $this->assertSame('Café déjà vu', $data['issues'][0]['primaryLocation']['message']);
    }

    /**
     * SonarQube does not carry help/ref fields, so the show-help / show-ref
     * options (inherited from AbstractFormat) must have no effect whatsoever.
     *
     * @return array<string, array{options: array<string, bool>}>
     */
    public static function helpRefOptionsProvider(): array
    {
        return [
            'both shown' => ['options' => ['show-help' => true, 'show-ref' => true]],
            'both hidden' => ['options' => ['show-help' => false, 'show-ref' => false]],
        ];
    }

    #[DataProvider('helpRefOptionsProvider')]
    public function testGenerateIgnoresHelpAndRefOptions(array $options): void
    {
        $report = new Report('HelpRef', '/project/base');
        $report->start();
        $issue = new Issue('php:S1', Issue::SEVERITY_WARNING, 'Base message.', 'src/A.php', 3, 2);
        $issue->setHelp('Some help that must not leak.');
        $issue->setRef('https://example.com/ref');
        $report->addIssue($issue);
        $report->complete();

        $this->formatter->setOptions($options);
        $data = json_decode($this->formatter->generate($report), true);

        $this->assertSame('Base message.', $data['issues'][0]['primaryLocation']['message']);
        $this->assertStringNotContainsString('Some help', json_encode($data));
        $this->assertStringNotContainsString('https://example.com/ref', json_encode($data));
    }

    /**
     * Valid SonarQube inputs. All of these are expected to parse into a Report
     * whose issues carry the mapped values.
     *
     * @return array<string, array{options: array<string, bool>, input: string, expected: array<int, array<string, mixed>>}>
     */
    public static function parseProvider(): array
    {
        $fullInput = <<<JSON
{
    "issues": [
        {
            "engineId": "MoodleChecklist",
            "ruleId": "php:S1001",
            "severity": "BLOCKER",
            "type": "BUG",
            "primaryLocation": {
                "message": "Blocker message",
                "filePath": "src/File1.php",
                "textRange": { "startLine": 10, "endLine": 10, "startColumn": 5, "endColumn": 5 }
            }
        },
        {
            "engineId": "MoodleChecklist",
            "ruleId": "php:S1002",
            "severity": "MAJOR",
            "type": "CODE_SMELL",
            "primaryLocation": {
                "message": "Major message",
                "filePath": "src/File1.php",
                "textRange": { "startLine": 20, "endLine": 20, "startColumn": 1, "endColumn": 1 }
            }
        },
        {
            "engineId": "MoodleChecklist",
            "ruleId": "php:S1003",
            "severity": "MINOR",
            "type": "CODE_SMELL",
            "primaryLocation": {
                "message": "Minor message",
                "filePath": "src/File2.php",
                "textRange": { "startLine": 30, "endLine": 30, "startColumn": 2, "endColumn": 2 }
            }
        }
    ]
}
JSON;

        return [
            'explicit severities, ranges and files' => [
                'options' => [],
                'input' => $fullInput,
                'expected' => [
                    [
                        'message' => 'Blocker message',
                        'line' => 10,
                        'column' => 5,
                        'path' => 'src/File1.php',
                        'code' => 'php:S1001',
                        'severity' => Issue::SEVERITY_ERROR,
                    ],
                    [
                        'message' => 'Major message',
                        'line' => 20,
                        'column' => 1,
                        'path' => 'src/File1.php',
                        'code' => 'php:S1002',
                        'severity' => Issue::SEVERITY_WARNING,
                    ],
                    [
                        'message' => 'Minor message',
                        'line' => 30,
                        'column' => 2,
                        'path' => 'src/File2.php',
                        'code' => 'php:S1003',
                        'severity' => Issue::SEVERITY_TIP,
                    ],
                ],
            ],
            'empty issues array' => [
                'options' => [],
                'input' => '{"issues":[]}',
                'expected' => [],
            ],
            'no textRange defaults to line/column 1' => [
                'options' => [],
                'input' => '{"issues":[{"ruleId":"php:S9","severity":"MAJOR","primaryLocation":{"message":"No range","filePath":"src/C.php"}}]}',
                'expected' => [
                    ['message' => 'No range', 'line' => 1, 'column' => 1, 'path' => 'src/C.php', 'code' => 'php:S9', 'severity' => Issue::SEVERITY_WARNING],
                ],
            ],
            'top-level components and unknown fields are ignored' => [
                'options' => [],
                'input' => <<<JSON
                {
                    "components": [{"key": "src/File1.php"}],
                    "rules": [],
                    "issues": [{
                        "engineId": "e",
                        "ruleId": "php:S1",
                        "severity": "BLOCKER",
                        "type": "BUG",
                        "unknownField": {"nested": true},
                        "primaryLocation": {
                            "message": "With components",
                            "filePath": "src/File1.php",
                            "textRange": {"startLine": 3, "endLine": 3, "startColumn": 4, "endColumn": 4},
                            "unknownLocationField": 1
                        }
                    }]
                }
JSON,
                'expected' => [
                    ['message' => 'With components', 'line' => 3, 'column' => 4, 'path' => 'src/File1.php', 'code' => 'php:S1', 'severity' => Issue::SEVERITY_ERROR],
                ],
            ],
            'missing severity defaults to tip (MINOR)' => [
                'options' => [],
                'input' => '{"issues":[{"ruleId":"php:S1","primaryLocation":{"message":"m","filePath":"f.php","textRange":{"startLine":1,"startColumn":1}}}]}',
                'expected' => [
                    ['message' => 'm', 'line' => 1, 'column' => 1, 'path' => 'f.php', 'code' => 'php:S1', 'severity' => Issue::SEVERITY_TIP],
                ],
            ],
            'unknown severity falls back to warning' => [
                'options' => [],
                'input' => '{"issues":[{"ruleId":"php:S1","severity":"NOT_A_SEVERITY","primaryLocation":{"message":"m","filePath":"f.php","textRange":{"startLine":1,"startColumn":1}}}]}',
                'expected' => [
                    ['message' => 'm', 'line' => 1, 'column' => 1, 'path' => 'f.php', 'code' => 'php:S1', 'severity' => Issue::SEVERITY_WARNING],
                ],
            ],
            'unknown type is accepted and ignored' => [
                'options' => [],
                'input' => '{"issues":[{"ruleId":"php:S1","severity":"MINOR","type":"SOMETHING_ELSE","primaryLocation":{"message":"m","filePath":"f.php","textRange":{"startLine":1,"startColumn":1}}}]}',
                'expected' => [
                    ['message' => 'm', 'line' => 1, 'column' => 1, 'path' => 'f.php', 'code' => 'php:S1', 'severity' => Issue::SEVERITY_TIP],
                ],
            ],
            'missing ruleId/message/filePath use defaults' => [
                'options' => [],
                'input' => '{"issues":[{"severity":"MAJOR","primaryLocation":{"textRange":{"startLine":7,"startColumn":8}}}]}',
                'expected' => [
                    ['message' => 'No message provided', 'line' => 7, 'column' => 8, 'path' => 'unknown_file', 'code' => 'Unknown', 'severity' => Issue::SEVERITY_WARNING],
                ],
            ],
            'unicode message preserved' => [
                'options' => [],
                'input' => '{"issues":[{"ruleId":"php:S1","severity":"MAJOR","primaryLocation":{"message":"Café 😀","filePath":"src/Ü.php","textRange":{"startLine":5,"startColumn":6}}}]}',
                'expected' => [
                    ['message' => 'Café 😀', 'line' => 5, 'column' => 6, 'path' => 'src/Ü.php', 'code' => 'php:S1', 'severity' => Issue::SEVERITY_WARNING],
                ],
            ],
            'zero/negative/out-of-range line and column are kept as-is' => [
                'options' => [],
                'input' => '{"issues":[{"ruleId":"php:S1","severity":"MAJOR","primaryLocation":{"message":"neg","filePath":"f.php","textRange":{"startLine":-5,"startColumn":-2}}}]}',
                'expected' => [
                    ['message' => 'neg', 'line' => -5, 'column' => -2, 'path' => 'f.php', 'code' => 'php:S1', 'severity' => Issue::SEVERITY_WARNING],
                ],
            ],
            'null issue entries are skipped without crashing' => [
                'options' => [],
                'input' => '{"issues":[null]}',
                'expected' => [],
            ],
            'primaryLocation as a string falls back to defaults' => [
                'options' => [],
                'input' => '{"issues":[{"ruleId":"php:S1","severity":"MAJOR","primaryLocation":"not-an-object"}]}',
                'expected' => [
                    ['message' => 'No message provided', 'line' => 1, 'column' => 1, 'path' => 'unknown_file', 'code' => 'php:S1', 'severity' => Issue::SEVERITY_WARNING],
                ],
            ],
        ];
    }

    /**
     * Verifies that parse() builds a Report with the expected issues.
     *
     * @param array<string, bool>                    $options  Formatter options.
     * @param string                                 $input    The SonarQube JSON.
     * @param array<int, array<string, mixed>>       $expected Expected issue data.
     */
    #[DataProvider('parseProvider')]
    public function testParse(array $options, string $input, array $expected): void
    {
        $this->formatter->setOptions($options);
        $report = $this->formatter->parse($input, 'Parsed SonarQube Report');

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('Parsed SonarQube Report', $report->getName());

        // A parsed report must have a usable, non-empty base path (SonarQube
        // reports carry plain file paths - the format is responsible for it).
        $this->assertNotEmpty($report->getBasePath(), 'parse() must produce a report with a base path.');

        $issues = $report->getIssues(false, false);
        $this->assertCount(count($expected), $issues);

        foreach ($expected as $index => $exp) {
            $issue = $issues[$index];
            $this->assertSame($exp['message'], $issue->getMessage(), "Message mismatch at index $index.");
            $this->assertSame($exp['line'], $issue->getLine(), "Line mismatch at index $index.");
            $this->assertSame($exp['column'], $issue->getColumn(), "Column mismatch at index $index.");
            $this->assertSame($exp['path'], $issue->getPath(), "Path mismatch at index $index.");
            $this->assertSame($exp['code'], $issue->getCode(), "Code mismatch at index $index.");
            $this->assertSame($exp['severity'], $issue->getSeverity(), "Severity mismatch at index $index.");
        }
    }

    /**
     * Verifies that parse() falls back to the format's default report name.
     */
    public function testParseUsesDefaultReportNameWhenNoneGiven(): void
    {
        $report = $this->formatter->parse('{"issues":[]}');
        $this->assertSame(SonarQube::getDefaultReportName(), $report->getName());
    }

    /**
     * Inputs that must be rejected with an InvalidArgumentException before any
     * Report is built.
     *
     * @return array<string, array{input: string, exceptionMessage: string}>
     */
    public static function invalidStructureProvider(): array
    {
        $formatError = 'Decoded JSON is not in the expected format (expected "issues" array).';

        return [
            'empty string' => ['', 'Invalid JSON input: Syntax error'],
            'whitespace only' => ["  \n\t ", 'Invalid JSON input: Syntax error'],
            'malformed json' => ['{', 'Invalid JSON input: Syntax error'],
            'truncated json' => ['{"issues":', 'Invalid JSON input: Syntax error'],
            'trailing garbage' => ['{"issues":[]} extra', 'Invalid JSON input: Syntax error'],
            'top-level string' => ['"string"', $formatError],
            'top-level number' => ['42', $formatError],
            'top-level boolean' => ['true', $formatError],
            'top-level null' => ['null', $formatError],
            'empty object' => ['{}', $formatError],
            'empty array' => ['[]', $formatError],
            'issues is null' => ['{"issues":null}', $formatError],
            'issues is a string' => ['{"issues":"nope"}', $formatError],
            'issues is a number' => ['{"issues":42}', $formatError],
            'issues is a boolean' => ['{"issues":true}', $formatError],
        ];
    }

    #[DataProvider('invalidStructureProvider')]
    public function testParseThrowsForInvalidStructure(string $input, string $exceptionMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($exceptionMessage);
        $this->formatter->parse($input);
    }

    /**
     * A very long message (and code/path) must be preserved verbatim.
     */
    public function testParseVeryLongStrings(): void
    {
        $longMessage = str_repeat('segmentation-fault-', 5000); // 90 000 chars
        $payload = json_encode([
            'issues' => [
                [
                    'ruleId' => str_repeat('R', 1000),
                    'severity' => 'MAJOR',
                    'primaryLocation' => [
                        'message' => $longMessage,
                        'filePath' => str_repeat('p/', 1000) . 'file.php',
                        'textRange' => ['startLine' => 1, 'startColumn' => 1],
                    ],
                ],
            ],
        ]);

        $report = $this->formatter->parse($payload);
        $issues = $report->getIssues(false, false);

        $this->assertCount(1, $issues);
        $this->assertSame($longMessage, $issues[0]->getMessage());
        $this->assertSame(str_repeat('R', 1000), $issues[0]->getCode());
        $this->assertSame(str_repeat('p/', 1000) . 'file.php', $issues[0]->getPath());
    }

    /**
     * The format declares itself as parsable, so it must advertise at least one
     * supported feature (this is what AbstractParsableFormatTest::testSupport
     * checks - asserted here explicitly with a descriptive message).
     */
    public function testSupportsDeclaresFeatures(): void
    {
        $this->assertNotEmpty(
            SonarQube::supports(),
            'A ParsableFormatInterface implementation must declare its supported features.'
        );
    }
}
