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
 * a Report object to and from the SonarQube Generic Issue Report JSON format.
 */
class SonarQube extends AbstractFormat implements ParsableFormatInterface
{

    use JsonFormatTrait;
    /**
     * Maps internal severity constants to SonarQube's issue type.
     */
    const SEVERITY_TO_TYPE_MAP = [
        Issue::SEVERITY_ERROR => 'BUG',
        Issue::SEVERITY_WARNING => 'CODE_SMELL',
        Issue::SEVERITY_TIP => 'CODE_SMELL',
    ];

    /**
     * Maps internal severity constants to SonarQube's severity level.
     */
    const SEVERITY_TO_SONARQUBE_SEVERITY_MAP = [
        Issue::SEVERITY_ERROR => 'BLOCKER',
        Issue::SEVERITY_WARNING => 'MAJOR',
        Issue::SEVERITY_TIP => 'MINOR',
    ];

    /**
     * Generates a SonarQube Generic Issue Report JSON string from a Report object.
     *
     * @param Report $report The report object to serialize.
     * @return string The JSON string.
     */
    public function generate(Report $report): string
    {
        $issuesByPath = $report->getIssues();
        $sqIssues = [];

        foreach ($issuesByPath as $path => $issues) {
            /** @var Issue $issue */
            foreach ($issues as $issue) {
                // Determine SonarQube severity and type
                $sonarqubeSeverity = self::SEVERITY_TO_SONARQUBE_SEVERITY_MAP[$issue->getSeverity()] ?? 'MINOR';
                $sonarqubeType = self::SEVERITY_TO_TYPE_MAP[$issue->getSeverity()] ?? 'CODE_SMELL';

                $sqIssues[] = [
                    'engineId' => 'MoodleChecklist',
                    'ruleId' => $issue->getCode(),
                    'primaryLocation' => [
                        'message' => $issue->getMessage(),
                        'filePath' => $path,
                        'textRange' => [
                            'startLine' => $issue->getLine(),
                            'endLine' => $issue->getLine(),
                            'startColumn' => $issue->getColumn(),
                            'endColumn' => $issue->getColumn(),
                        ],
                    ],
                    'type' => $sonarqubeType,
                    'severity' => $sonarqubeSeverity,
                ];
            }
        }

        $output = ['issues' => $sqIssues];

        return  $this->jsonEncode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Parses a SonarQube Generic Issue Report JSON string and returns a Report object.
     *
     * @param string $input The JSON string to parse.
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

        if (!is_array($data) || !isset($data['issues']) || !is_array($data['issues'])) {
            throw new \InvalidArgumentException('Decoded JSON is not in the expected format (expected "issues" array).');
        }

        $flatIssues = [];
        $allPath = [];

        // Invert the severity map for parsing
        $sonarqubeSeverityToInternal = array_flip(self::SEVERITY_TO_SONARQUBE_SEVERITY_MAP);

        foreach ($data['issues'] as $sqIssue) {
            $location = $sqIssue['primaryLocation'] ?? null;
            if (!$location) {
                continue;
            }

            // A malformed location (e.g. a string) is treated as empty, so the
            // issue is still reported with the format's default values.
            if (!is_array($location)) {
                $location = [];
            }

            $path = $location['filePath'] ?? 'unknown_file';
            $allPath[] = $path;

            $severityString = $sqIssue['severity'] ?? 'MINOR';
            $severity = $sonarqubeSeverityToInternal[$severityString] ?? Issue::SEVERITY_WARNING;

            $flatIssues[] = [
                'message' => $location['message'] ?? 'No message provided',
                'line' => $location['textRange']['startLine'] ?? 1,
                'column' => $location['textRange']['startColumn'] ?? 1,
                'path' => $path,
                'code' => $sqIssue['ruleId'] ?? 'Unknown',
                'severity' => $severity,
            ];
        }

        $reportData = [
            'name' => $name,
            'basePath' => Path::findCommonBasePath($allPath),
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
        return "SonarQube Generic Issue Report JSON representation for static analysis";
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
