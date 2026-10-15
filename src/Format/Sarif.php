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
 * a Report object to and from the SARIF (Static Analysis Results Interchange Format).
 * This class uses the SARIF 2.1.0 specification.
 *
 * @see https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html
 */
class Sarif extends AbstractFormat implements ParsableFormatInterface
{

    use JsonFormatTrait;

    /**
     * Maps internal severity constants to SARIF's result level.
     */
    const SEVERITY_TO_SARIF_LEVEL_MAP = [
        Issue::SEVERITY_ERROR => 'error',
        Issue::SEVERITY_WARNING => 'warning',
        Issue::SEVERITY_TIP => 'note',
    ];

    /**
     * Generates a SARIF JSON string from a Report object.
     *
     * @param Report $report The report object to serialize.
     * @return string The SARIF JSON string.
     */
    public function generate(Report $report): string
    {
        $issuesByCode = [];
        $issuesByPath = $report->getIssues();

        // Group issues by rule code to build the SARIF 'rules' array
        // getIssues(false) yields a FLAT Issue[] list; the default ($byFile = true)
        // returns Issue[][] and would make $issue->getCode() a fatal error.
        foreach ($report->getIssues(false) as $issue) {
            /** @var Issue $issue */
            $code = $issue->getCode();
            if (!isset($issuesByCode[$code])) {
                $issuesByCode[$code] = [
                    'message' => $issue->getMessage(),
                    'severity' => $issue->getSeverity(),
                    'ref' => method_exists($issue, 'getRef') ? $issue->getRef() : null,
                    'help' => method_exists($issue, 'getHelp') ? $issue->getHelp() : null,
                ];
            }
        }

        // Build the SARIF 'rules' array
        $sarifRules = [];
        foreach ($issuesByCode as $code => $issueData) {
            $rule = [
                'id' => $code,
                'name' => $code, // Use code as name for simplicity
                'fullDescription' => [
                    'text' => $issueData['message'],
                ],
                'defaultConfiguration' => [
                    'level' => self::SEVERITY_TO_SARIF_LEVEL_MAP[$issueData['severity']] ?? 'note',
                ],
            ];

            // Add 'helpUri' for 'ref' field
            if ($issueData['ref']) {
                $rule['helpUri'] = $issueData['ref'];
            }

            // Add 'help' for 'help' field
            if ($issueData['help']) {
                $rule['help'] = ['text' => $issueData['help']];
            }

            $sarifRules[] = $rule;
        }

        // Build the SARIF 'results' array
        $sarifResults = [];
        foreach ($issuesByPath as $path => $issues) {
            foreach ($issues as $issue) {
                /** @var Issue $issue */
                $sarifResults[] = [
                    'ruleId' => $issue->getCode(),
                    'message' => [
                        'text' => $issue->getMessage(),
                    ],
                    'level' => self::SEVERITY_TO_SARIF_LEVEL_MAP[$issue->getSeverity()] ?? 'note',
                    'locations' => [
                        [
                            'physicalLocation' => [
                                'artifactLocation' => [
                                    'uri' => $path,
                                ],
                                'region' => [
                                    'startLine' => $issue->getLine(),
                                    'startColumn' => $issue->getColumn(),
                                    'endLine' => $issue->getLine(),
                                    'endColumn' => $issue->getColumn()
                                ]
                            ]
                        ]
                    ],
                ];
            }
        }

        $sarifLog = [
            '$schema' => 'https://schemastore.azurewebsites.net/schemas/json/sarif-2.1.0-rtm.5.json',
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'MoodleChecklist',
                            'semanticVersion' => '1.0.0', // Assuming a version number for the tool
                            'rules' => $sarifRules,
                        ],
                    ],
                    'results' => $sarifResults,
                ]
            ]
        ];

        return $this->jsonEncode($sarifLog);
    }

    /**
     * Parses a SARIF JSON string and returns a Report object.
     *
     * @param string $input The SARIF JSON string to parse.
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

        if (!is_array($data) || !isset($data['runs']) || !is_array($data['runs'])) {
            throw new \InvalidArgumentException('Decoded JSON is not a valid SARIF format.');
        }

        $flatIssues = [];
        $allPaths = [];
        foreach ($data['runs'] as $run) {
            if (!isset($run['results']) || !is_array($run['results'])) {
                continue;
            }

            // Invert the severity map for parsing
            $sarifLevelToInternal = array_flip(self::SEVERITY_TO_SARIF_LEVEL_MAP);

            foreach ($run['results'] as $result) {
                // Extract rule information to get original message and ref/help
                $ruleId = $result['ruleId'] ?? 'Unknown';
                $rule = [];
                if (isset($run['tool']['driver']['rules'])) {
                    $rules = $run['tool']['driver']['rules'];
                    $ruleIndex = array_search($ruleId, array_column($rules, 'id'));
                    if ($ruleIndex !== false) {
                        $rule = $rules[$ruleIndex];
                    }
                }

                $message = $result['message']['text'] ?? 'No message provided';
                $level = $result['level'] ?? 'note';

                $location = $result['locations'][0]['physicalLocation'] ?? null;
                $path = $location['artifactLocation']['uri'] ?? 'unknown_file';

                $region = $location['region'] ?? ['startLine' => 1, 'startColumn' => 1];
                $line = $region['startLine'] ?? 1;
                $column = $region['startColumn'] ?? 1;

                $issueData = [
                    'message' => $message,
                    'line' => $line,
                    'column' => $column,
                    'path' => $path,
                    'code' => $ruleId,
                    'severity' => $sarifLevelToInternal[$level] ?? Issue::SEVERITY_WARNING,
                ];

                // Check for 'help' and 'ref' fields.
                if (isset($rule['helpUri'])) {
                    $issueData['ref'] = $rule['helpUri'];
                }
                if (isset($rule['help']['text'])) {
                    $issueData['help'] = $rule['help']['text'];
                }

                $allPaths[] = $path;
                $flatIssues[] = $issueData;
            }
        }

        $reportData = [
            'name' => $name,
            'issues' => $flatIssues,
            'subReports' => [],
            'timeStart' => 0,
            'timeEnd' => 0,
            'basePath' => Path::findCommonBasePath($allPaths),
        ];

        return Report::fromJson($reportData);
    }

    /**
     * @return string The description of the format.
     */
    public static function getDesc(): string
    {
        return "SARIF (Static Analysis Results Interchange Format) for static analysis";
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
