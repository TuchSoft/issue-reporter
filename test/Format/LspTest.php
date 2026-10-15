<?php

namespace Tuchsoft\IssueReporter\Test\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\Lsp;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Test\Base\AbstractJsonFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\JsonOptionsProvider;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;

/**
 * Adversarial test suite for the Language Server Protocol (LSP) diagnostics format.
 *
 * The inherited {@see AbstractJsonFormatTest} provides the round-trip matrix,
 * the invalid-JSON test, testSupport and the JSON formatting-option test.
 * The tests below add format-specific structure checks and hostile inputs.
 */
#[CoversClass(\Tuchsoft\IssueReporter\Format\Lsp::class)]
#[Group('Lsp')]
class LspTest extends AbstractJsonFormatTest
{
    use ReportProvider;
    use JsonOptionsProvider;

    /** @var Lsp $formatter */
    protected FormatInterface $formatter;
    protected static string $formatterClass = Lsp::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new Lsp();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Builds a deterministic report rooted at /project.
     *
     * @param array<int, array<string, mixed>> $specs
     */
    private function buildReport(array $specs): Report
    {
        $report = new Report('Lsp Test', '/project');
        $report->start();
        foreach ($specs as $i => $spec) {
            $report->addIssue(new Issue(
                $spec['code'] ?? 'C'.$i,
                $spec['severity'] ?? Issue::SEVERITY_WARNING,
                $spec['message'] ?? 'msg',
                $spec['path'] ?? 'src/A.php',
                $spec['line'] ?? null,
                $spec['column'] ?? null,
                $spec['ref'] ?? '',
                $spec['help'] ?? ''
            ));
        }
        $report->complete();

        return $report;
    }

    /** The controlled report used by the generate tests. */
    private function controlledReport(): Report
    {
        return $this->buildReport([
            ['code' => 'E1', 'severity' => Issue::SEVERITY_ERROR,   'message' => 'critical error', 'path' => 'src/A.php', 'line' => 10, 'column' => 5, 'ref' => 'https://example.com/e1'],
            ['code' => 'W1', 'severity' => Issue::SEVERITY_WARNING, 'message' => 'a warning',      'path' => 'src/A.php', 'line' => 1,  'column' => 1],
            ['code' => 'T1', 'severity' => Issue::SEVERITY_TIP,     'message' => 'a tip',          'path' => 'src/B.php', 'line' => 3,  'column' => 2],
            ['code' => 'H1', 'severity' => 7,                       'message' => 'a hint',         'path' => 'src/B.php'],
        ]);
    }

    /**
     * Expected LSP diagnostic made by generate(): zero-based range offset by -1,
     * the hardcoded source and an optional codeDescription.
     */
    private function diagnostic(int $line, int $character, int $severity, string $code, string $message, ?string $href = null): array
    {
        $range = [
            'start' => ['line' => $line, 'character' => $character],
            'end' => ['line' => $line, 'character' => $character],
        ];
        $diagnostic = [
            'range' => $range,
            'severity' => $severity,
            'code' => $code,
            'source' => 'MoodleChecklist',
            'message' => $message,
        ];
        if ($href !== null) {
            $diagnostic['codeDescription'] = ['href' => $href];
        }

        return $diagnostic;
    }

    private function notification(string $uri, array $diagnostics): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => 'textDocument/publishDiagnostics',
            'params' => [
                'uri' => $uri,
                'diagnostics' => $diagnostics,
            ],
        ];
    }

    /** The exact structure expected from {@see controlledReport()}. */
    private function expectedControlledStructure(): array
    {
        return [
            $this->notification('file:///project/src/A.php', [
                $this->diagnostic(9, 4, Lsp::LSP_SEVERITY_ERROR, 'E1', 'critical error', 'https://example.com/e1'),
                $this->diagnostic(0, 0, Lsp::LSP_SEVERITY_WARNING, 'W1', 'a warning'),
            ]),
            $this->notification('file:///project/src/B.php', [
                $this->diagnostic(2, 1, Lsp::LSP_SEVERITY_INFORMATION, 'T1', 'a tip'),
                $this->diagnostic(-1, -1, Lsp::LSP_SEVERITY_HINT, 'H1', 'a hint'),
            ]),
        ];
    }

    // ---------------------------------------------------------------------
    // generate()
    // ---------------------------------------------------------------------

    /**
     * The decoded output must be structurally identical no matter which options
     * are set: options only affect whitespace/escaping, never the semantics.
     */
    public static function generateProvider(): array
    {
        return [
            'default options' => ['options' => []],
            'pretty' => ['options' => ['pretty' => true]],
            'no escape slash' => ['options' => ['escape-slash' => false]],
            'no escape unicode' => ['options' => ['escape-unicode' => false]],
            'show-ref enabled' => ['options' => ['show-ref' => true]],
            'show-help disabled' => ['options' => ['show-help' => false]],
            'all toggled' => ['options' => ['pretty' => true, 'escape-slash' => false, 'escape-unicode' => false, 'show-ref' => true, 'show-help' => true]],
        ];
    }

    #[DataProvider('generateProvider')]
    public function testGenerateProducesExactLspStructure(array $options): void
    {
        $this->formatter->setOptions($options);
        $output = $this->formatter->generate($this->controlledReport());

        $this->assertJson($output);

        $decoded = json_decode($output, true);
        $this->assertSame($this->expectedControlledStructure(), $decoded);
    }

    /**
     * Every internal severity must map onto the LSP 1..4 scale, and any unknown
     * severity must fall back to the HINT value (4).
     */
    public function testGenerateMapsEverySeverityBoundary(): void
    {
        $report = $this->buildReport([
            ['code' => 'E', 'severity' => Issue::SEVERITY_ERROR,   'message' => 'e', 'path' => 'f.php', 'line' => 1, 'column' => 1],
            ['code' => 'W', 'severity' => Issue::SEVERITY_WARNING, 'message' => 'w', 'path' => 'f.php', 'line' => 1, 'column' => 1],
            ['code' => 'T', 'severity' => Issue::SEVERITY_TIP,     'message' => 't', 'path' => 'f.php', 'line' => 1, 'column' => 1],
            ['code' => 'O', 'severity' => 4,                       'message' => 'o', 'path' => 'f.php', 'line' => 1, 'column' => 1],
            ['code' => 'X', 'severity' => 999,                     'message' => 'x', 'path' => 'f.php', 'line' => 1, 'column' => 1],
            ['code' => 'Z', 'severity' => -5,                      'message' => 'z', 'path' => 'f.php', 'line' => 1, 'column' => 1],
        ]);

        $decoded = json_decode($this->formatter->generate($report), true);
        $severities = array_column($decoded[0]['params']['diagnostics'], 'severity');

        $this->assertSame([1, 2, 3, 4, 4, 4], $severities);
    }

    /**
     * codeDescription must only be emitted when a non-empty ref exists.
     */
    public function testGenerateOnlyEmitsCodeDescriptionForNonEmptyRef(): void
    {
        $report = $this->buildReport([
            ['code' => 'WithRef', 'message' => 'a', 'path' => 'f.php', 'ref' => 'https://x/y'],
            ['code' => 'NoRef',   'message' => 'b', 'path' => 'f.php', 'ref' => ''],
        ]);

        $diagnostics = json_decode($this->formatter->generate($report), true)[0]['params']['diagnostics'];

        $this->assertArrayHasKey('codeDescription', $diagnostics[0]);
        $this->assertSame(['href' => 'https://x/y'], $diagnostics[0]['codeDescription']);
        $this->assertArrayNotHasKey('codeDescription', $diagnostics[1]);
    }

    /**
     * A report with no issues renders as an empty JSON array.
     */
    public function testGenerateEmptyReport(): void
    {
        $report = new Report('Empty', '/project');
        $report->start();
        $report->complete();

        $output = $this->formatter->generate($report);

        $this->assertJson($output);
        $this->assertSame('[]', $output);
        $this->assertSame([], json_decode($output, true));
    }

    /**
     * Special characters and unicode must survive a JSON round-trip untouched,
     * and the file URI must carry the raw (unescaped-on-decode) path.
     */
    public function testGenerateRoundTripsHostileStrings(): void
    {
        $message = "Quote \" backslash \\ newline\n tab\t unicode é 😀 <html>&amp;</html>";
        $report = $this->buildReport([
            ['code' => 'X1', 'severity' => Issue::SEVERITY_ERROR, 'message' => $message, 'path' => 'src/weird"name.php', 'line' => 1, 'column' => 1],
        ]);

        $output = $this->formatter->generate($report);
        $this->assertJson($output);

        $decoded = json_decode($output, true);
        $this->assertSame($message, $decoded[0]['params']['diagnostics'][0]['message']);
        $this->assertSame('file:///project/src/weird"name.php', $decoded[0]['params']['uri']);
    }

    // ---------------------------------------------------------------------
    // parse()
    // ---------------------------------------------------------------------

    private static function lspDiagnostic(int $line, int $character, int $severity, string $message, ?string $code = null, ?string $href = null, ?int $endLine = null, ?int $endChar = null): array
    {
        $d = [
            'range' => [
                'start' => ['line' => $line, 'character' => $character],
                'end' => ['line' => $endLine ?? $line, 'character' => $endChar ?? $character],
            ],
            'severity' => $severity,
            'message' => $message,
        ];
        if ($code !== null) {
            $d['code'] = $code;
        }
        if ($href !== null) {
            $d['codeDescription'] = ['href' => $href];
        }

        return $d;
    }

    private static function lspNotification(string $uri, array $diagnostics): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => 'textDocument/publishDiagnostics',
            'params' => ['uri' => $uri, 'diagnostics' => $diagnostics],
        ];
    }

    private static function expectedIssue(string $message, int $line, int $column, string $path, string $code, int $severity, string $ref = ''): array
    {
        return compact('message', 'line', 'column', 'path', 'code', 'severity', 'ref');
    }

    /**
     * Well-formed (but often hostile/edge-case) LSP payloads and the issues they
     * MUST produce.
     *
     * @return array<string, array{input: string, expectedIssues: array<int, array<string, mixed>>}>
     */
    public static function parseProvider(): array
    {
        return [
            'basic single diagnostic' => [
                'input' => json_encode([
                    self::lspNotification('file:///project/src/A.php', [
                        self::lspDiagnostic(9, 4, 1, 'boom', 'E1'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('boom', 10, 5, '/project/src/A.php', 'E1', Issue::SEVERITY_ERROR),
                ],
            ],
            'multiple files and diagnostics' => [
                'input' => json_encode([
                    self::lspNotification('file:///project/src/A.php', [
                        self::lspDiagnostic(0, 0, 2, 'w1', 'W1'),
                        self::lspDiagnostic(4, 2, 1, 'e1', 'E1'),
                    ]),
                    self::lspNotification('file:///project/src/B.php', [
                        self::lspDiagnostic(19, 9, 3, 't1', 'T1'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('w1', 1, 1, '/project/src/A.php', 'W1', Issue::SEVERITY_WARNING),
                    self::expectedIssue('e1', 5, 3, '/project/src/A.php', 'E1', Issue::SEVERITY_ERROR),
                    self::expectedIssue('t1', 20, 10, '/project/src/B.php', 'T1', Issue::SEVERITY_TIP),
                ],
            ],
            'severity boundaries' => [
                'input' => json_encode([
                    self::lspNotification('file:///f.php', [
                        self::lspDiagnostic(0, 0, 1, 'error'),
                        self::lspDiagnostic(0, 0, 2, 'warning'),
                        self::lspDiagnostic(0, 0, 3, 'info'),
                        self::lspDiagnostic(0, 0, 4, 'hint'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('error', 1, 1, '/f.php', 'Unknown', Issue::SEVERITY_ERROR),
                    self::expectedIssue('warning', 1, 1, '/f.php', 'Unknown', Issue::SEVERITY_WARNING),
                    self::expectedIssue('info', 1, 1, '/f.php', 'Unknown', Issue::SEVERITY_TIP),
                    self::expectedIssue('hint', 1, 1, '/f.php', 'Unknown', Issue::SEVERITY_TIP),
                ],
            ],
            'out of range severities fall back to warning' => [
                'input' => json_encode([
                    self::lspNotification('file:///f.php', [
                        self::lspDiagnostic(0, 0, 0, 'zero'),
                        self::lspDiagnostic(0, 0, 5, 'five'),
                        self::lspDiagnostic(0, 0, 99, 'many'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('zero', 1, 1, '/f.php', 'Unknown', Issue::SEVERITY_WARNING),
                    self::expectedIssue('five', 1, 1, '/f.php', 'Unknown', Issue::SEVERITY_WARNING),
                    self::expectedIssue('many', 1, 1, '/f.php', 'Unknown', Issue::SEVERITY_WARNING),
                ],
            ],
            'missing optional fields' => [
                'input' => json_encode([
                    self::lspNotification('file:///p/f.php', [
                        ['range' => ['start' => ['line' => 2, 'character' => 3]]],
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('No message provided', 3, 4, '/p/f.php', 'Unknown', Issue::SEVERITY_WARNING),
                ],
            ],
            'missing entire range' => [
                'input' => json_encode([
                    self::lspNotification('file:///p/f.php', [
                        ['severity' => 1, 'code' => 'E', 'message' => 'm'],
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('m', 1, 1, '/p/f.php', 'E', Issue::SEVERITY_ERROR),
                ],
            ],
            'end before start uses start' => [
                'input' => json_encode([
                    self::lspNotification('file:///f.php', [
                        self::lspDiagnostic(10, 5, 1, 'm', 'E', null, 2, 1),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('m', 11, 6, '/f.php', 'E', Issue::SEVERITY_ERROR),
                ],
            ],
            'zero and negative coordinates' => [
                'input' => json_encode([
                    self::lspNotification('file:///f.php', [
                        self::lspDiagnostic(0, 0, 1, 'zero'),
                        self::lspDiagnostic(-1, -1, 1, 'neg'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('zero', 1, 1, '/f.php', 'Unknown', Issue::SEVERITY_ERROR),
                    self::expectedIssue('neg', 0, 0, '/f.php', 'Unknown', Issue::SEVERITY_ERROR),
                ],
            ],
            'codeDescription href becomes ref' => [
                'input' => json_encode([
                    self::lspNotification('file:///f.php', [
                        self::lspDiagnostic(0, 0, 1, 'm', 'E', 'https://example.com/rule'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('m', 1, 1, '/f.php', 'E', Issue::SEVERITY_ERROR, 'https://example.com/rule'),
                ],
            ],
            'unicode and special characters message' => [
                'input' => json_encode([
                    self::lspNotification('file:///f.php', [
                        self::lspDiagnostic(0, 0, 1, "Unicode é 😀 \"q\" \\b\nnl", 'E'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue("Unicode é 😀 \"q\" \\b\nnl", 1, 1, '/f.php', 'E', Issue::SEVERITY_ERROR),
                ],
            ],
            'uri without file scheme' => [
                'input' => json_encode([
                    self::lspNotification('/abs/path/f.php', [
                        self::lspDiagnostic(0, 0, 1, 'm'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('m', 1, 1, '/abs/path/f.php', 'Unknown', Issue::SEVERITY_ERROR),
                ],
            ],
            'extra unknown fields are ignored' => [
                'input' => json_encode([
                    array_merge(self::lspNotification('file:///f.php', [
                        array_merge(
                            self::lspDiagnostic(0, 0, 1, 'm', 'E'),
                            ['data' => ['x' => 1], 'tags' => [1, 2], 'relatedInformation' => ['a']]
                        ),
                    ]), ['id' => 42, 'extra' => true]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('m', 1, 1, '/f.php', 'E', Issue::SEVERITY_ERROR),
                ],
            ],
            'non publishDiagnostics notifications ignored' => [
                'input' => json_encode([
                    ['jsonrpc' => '2.0', 'method' => 'textDocument/other', 'params' => ['uri' => 'file:///ignored.php']],
                    ['jsonrpc' => '2.0'],
                    self::lspNotification('file:///kept.php', [
                        self::lspDiagnostic(0, 0, 1, 'kept', 'E'),
                    ]),
                ]),
                'expectedIssues' => [
                    self::expectedIssue('kept', 1, 1, '/kept.php', 'E', Issue::SEVERITY_ERROR),
                ],
            ],
            'empty notification array' => [
                'input' => json_encode([]),
                'expectedIssues' => [],
            ],
        ];
    }

    #[DataProvider('parseProvider')]
    public function testParse(string $input, array $expectedIssues): void
    {
        $report = $this->formatter->parse($input, 'Lsp Report');

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('Lsp Report', $report->getName());

        $issues = $report->getIssues(false, false);
        $this->assertCount(count($expectedIssues), $issues, 'Parsed issue count mismatch.');

        foreach ($expectedIssues as $i => $expected) {
            $issue = $issues[$i];
            $this->assertSame($expected['message'], $issue->getMessage(), "Message mismatch at index $i.");
            $this->assertSame($expected['line'], $issue->getLine(), "Line mismatch at index $i.");
            $this->assertSame($expected['column'], $issue->getColumn(), "Column mismatch at index $i.");
            $this->assertSame($expected['path'], $issue->getPath(), "Path mismatch at index $i.");
            $this->assertSame($expected['code'], $issue->getCode(), "Code mismatch at index $i.");
            $this->assertSame($expected['severity'], $issue->getSeverity(), "Severity mismatch at index $i.");
            $this->assertSame($expected['ref'], $issue->getRef(), "Ref mismatch at index $i.");
        }
    }

    /**
     * Every occurrence of the "file://" scheme is stripped from the URI.
     */
    public function testParseStripsAllFileSchemeOccurrences(): void
    {
        $input = json_encode([
            self::lspNotification('file:///a/file://b.php', [
                self::lspDiagnostic(0, 0, 1, 'm'),
            ]),
        ]);

        $report = $this->formatter->parse($input, 'Lsp Report');
        $issues = $report->getIssues(false, false);

        $this->assertCount(1, $issues);
        $this->assertSame('/a/b.php', $issues[0]->getPath());
    }

    // ---------------------------------------------------------------------
    // parse() — malformed input
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{input: string}>
     */
    public static function malformedJsonProvider(): array
    {
        return [
            'empty string' => ['input' => ''],
            'whitespace only' => ['input' => "   \n\t "],
            'plain text' => ['input' => 'this is not json'],
            'truncated object' => ['input' => '{"jsonrpc":'],
            'truncated array' => ['input' => '[{"a":1}'],
            'garbage' => ['input' => '%^&*()'],
        ];
    }

    #[DataProvider('malformedJsonProvider')]
    public function testParseThrowsForMalformedJson(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON input:');
        $this->formatter->parse($input);
    }

    /**
     * @return array<string, array{input: string}>
     */
    public static function nonArrayJsonProvider(): array
    {
        return [
            'null' => ['input' => 'null'],
            'string' => ['input' => '"value"'],
            'integer' => ['input' => '123'],
            'float' => ['input' => '1.5'],
            'boolean' => ['input' => 'true'],
        ];
    }

    #[DataProvider('nonArrayJsonProvider')]
    public function testParseThrowsForNonArrayJson(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Decoded JSON is not in the expected format (expected array of notifications).');
        $this->formatter->parse($input);
    }
}
