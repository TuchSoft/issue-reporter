<?php

namespace Tuchsoft\IssueReporter\Format;

use Tuchsoft\IssueReporter\Format\Base\AbstractFormat;
use Tuchsoft\IssueReporter\Format\Base\JsonFormatTrait;
use Tuchsoft\IssueReporter\Format\Base\ParsableFormatInterface;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Utils\Path;


/**
 * An implementation of a report format that serializes and deserializes
 * a Report object to and from a JSON structure conforming to the Language
 * Server Protocol (LSP) for diagnostics.
 */
class Lsp extends AbstractFormat implements ParsableFormatInterface
{

    use JsonFormatTrait;

    /**
     * LSP severity constants.
     * @see https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/#diagnosticSeverity
     */
    const LSP_SEVERITY_ERROR = 1;
    const LSP_SEVERITY_WARNING = 2;
    const LSP_SEVERITY_INFORMATION = 3;
    const LSP_SEVERITY_HINT = 4;

    /**
     * Generates a JSON string representing an LSP `textDocument/publishDiagnostics` notification.
     *
     * @param Report $report The report object to serialize.
     * @return string The JSON string.
     */
    public function generate(Report $report): string
    {
        $lspDiagnostics = [];

        // LSP groups all diagnostics for a single URI (file path)
        $issuesByPath = $report->getIssues();

        foreach ($issuesByPath as $path => $issues) {
            $diagnosticsForFile = [];

            /** @var Issue $issue */
            foreach ($issues as $issue) {
                // Map internal severity to LSP's numeric severity
                $lspSeverity = match ($issue->getSeverity()) {
                    Issue::SEVERITY_ERROR => self::LSP_SEVERITY_ERROR,
                    Issue::SEVERITY_WARNING => self::LSP_SEVERITY_WARNING,
                    Issue::SEVERITY_TIP => self::LSP_SEVERITY_INFORMATION,
                    default => self::LSP_SEVERITY_HINT,
                };

                // Base diagnostic array
                $diagnostic = [
                    'range' => [
                        'start' => [
                            'line' => $issue->getLine() - 1,
                            'character' => $issue->getColumn() - 1,
                        ],
                        'end' => [
                            // In a simple case, the end is the same as the start for a single point
                            'line' => $issue->getLine() - 1,
                            'character' => $issue->getColumn() - 1,
                        ],
                    ],
                    'severity' => $lspSeverity,
                    'code' => $issue->getCode(),
                    'source' => 'MoodleChecklist',
                    'message' => $issue->getMessage(),
                ];

                // Add the codeDescription for the reference link if the 'ref' field exists.
                // Assuming $issue->getRef() method exists.
                if (method_exists($issue, 'getRef') && $issue->getRef()) {
                    $diagnostic['codeDescription'] = [
                        'href' => $issue->getRef(),
                    ];
                }

                $diagnosticsForFile[] = $diagnostic;
            }

            // The LSP `publishDiagnostics` notification structure
            $lspDiagnostics[] = [
                'jsonrpc' => '2.0',
                'method' => 'textDocument/publishDiagnostics',
                'params' => [
                    'uri' => "file://{$path}", // URIs must be absolute
                    'diagnostics' => $diagnosticsForFile,
                ],
            ];
        }

        // Return a single JSON array with all notifications
        return $this->jsonEncode($lspDiagnostics);
    }

    /**
     * Parses a JSON string from an LSP diagnostics notification and returns a Report object.
     *
     * @param string $input The JSON string from the LSP server.
     * @param string $name The name for the new Report object.
     * @return Report The parsed Report object.
     * @throws \InvalidArgumentException If the JSON is invalid or the structure is incorrect.
     */
    public function parse(string $input, ?string $name = null): Report
    {
        if (!$name) {
            $name = static::getDefaultReportName();
        }
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Decoded JSON is not in the expected format (expected array of notifications).');
        }

        $flatIssues = [];
        $allPaths = [];
        foreach ($data as $notification) {
            if (!isset($notification['method']) || $notification['method'] !== 'textDocument/publishDiagnostics') {
                continue;
            }

            $params = $notification['params'] ?? null;
            if (!$params || !isset($params['uri']) || !isset($params['diagnostics']) || !is_array($params['diagnostics'])) {
                continue;
            }

            // Extract the file path from the URI
            $filePath = str_replace('file://', '', $params['uri']);
            $allPaths[] = $filePath;

            foreach ($params['diagnostics'] as $diagnostic) {
                // Map LSP numeric severity back to internal severity
                $severityMap = [
                    self::LSP_SEVERITY_ERROR => Issue::SEVERITY_ERROR,
                    self::LSP_SEVERITY_WARNING => Issue::SEVERITY_WARNING,
                    self::LSP_SEVERITY_INFORMATION => Issue::SEVERITY_TIP,
                    self::LSP_SEVERITY_HINT => Issue::SEVERITY_TIP, // Map hint to tip
                ];
                $issueSeverity = $severityMap[$diagnostic['severity'] ?? 2] ?? Issue::SEVERITY_WARNING;

                $range = $diagnostic['range'] ?? ['start' => ['line' => 0, 'character' => 0]];

                $issueData = [
                    'message' => $diagnostic['message'] ?? 'No message provided',
                    // LSP lines/characters are zero-based, so we add 1
                    'line' => ($range['start']['line'] ?? 0) + 1,
                    'column' => ($range['start']['character'] ?? 0) + 1,
                    'path' => $filePath,
                    'code' => $diagnostic['code'] ?? 'Unknown',
                    'severity' => $issueSeverity,
                ];

                // Check if the codeDescription with a reference link exists
                if (isset($diagnostic['codeDescription']['href'])) {
                    $issueData['ref'] = $diagnostic['codeDescription']['href'];
                }

                $flatIssues[] = $issueData;
            }
        }

        // Report::fromJson() requires a non-empty basePath. The LSP payload only
        // carries absolute URIs, so derive the base path from the parsed file paths.
        $basePath = Path::findCommonBasePath($allPaths);
        if ($basePath === '') {
            $basePath = DIRECTORY_SEPARATOR;
        }

        $reportData = [
            'name' => $name,
            'basePath' => $basePath,
            'issues' => $flatIssues,
            'subReports' => [],
            'timeStart' => 0,
            'timeEnd' => 0,
        ];

        return Report::fromJson($reportData);
    }

    /**
     * @return string The description of the format.
     */
    public static function getDesc(): string
    {
        return "Language Server Protocol JSON representation for diagnostics";
    }

    public static function supports(): array
    {
        return [
            ...self::FEATURE_ISSUE_STANDARD,
        ];
    }

    public static function supportsExtra(): array
    {
        return [];
    }
}
