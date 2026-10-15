<?php

namespace Tuchsoft\IssueReporter;


use Tuchsoft\IssueReporter\Utils\Path;

//TODO: add totalCheck, excluded

/**
 * Class Report
 * A hierarchical report system that can contain issues and child reports.
 */
class Report implements \JsonSerializable
{
    
    /**
     * @var Issue[] Issues for the current report only.
     */
    private array $issues = [];

    /**
     * @var Report[] Child reports stored as an array of Report objects.
     */
    private array $subReports = [];

    private ?float $timeStart = 0;
    private ?float $timeEnd = 0;
    private ?float $totalTime = 0;
    private string $basePath;

    public function __construct(
        protected string $name,
        string $basePath = '/',
        protected bool $useHierarchyCode = true
    ) {
        $this->basePath =  rtrim(Path::normalize($basePath), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    }

    public function start(): static {
        $this->timeStart = microtime(true);
        return $this;
    }

    public function complete(): static {
        $this->timeEnd = microtime(true);
        return $this;
    }

    public function getTotalTime(): ?float {
        if ($this->totalTime) return $this->totalTime;
        if (!$this->timeStart) return 0;
        return round(($this->getTimeEnd() - $this->getTimeStart()) * 1000, 0);
    }

    /**
     * Adds a tip issue to the report.
     *
     * @param string $code The issue code.
     * @param string $message The issue message.
     * @param string $path The file path where the issue was found (relative to plugin root).
     * @param int    $line The line number where the issue was found.
     */
    public function tip(string $code, string $message, ?string $path = null, ?int  $line = null, ?int $column = null, ?string $help = null, ?string $ref = null): void
    {
        $this->issue($code, Issue::SEVERITY_TIP, $message, $path, $line, $column, $help, $ref);
    }

    /**
     * Adds a warning issue to the report.
     *
     * @param string $code The issue code.
     * @param string $message The issue message.
     * @param string $path The file path where the issue was found (relative to plugin root).
     * @param int    $line The line number where the issue was found.
     */
    public function warning(string $code, string $message, ?string $path = null, ?int  $line = null, ?int $column = null, ?string $help = null, ?string $ref = null): void
    {
        $this->issue($code, Issue::SEVERITY_WARNING, $message, $path, $line, $column, $help, $ref);
    }

    /**
     * Adds an error issue to the report.
     *
     * @param string $code The issue code.
     * @param string $message The issue message.
     * @param string $path The file path where the issue was found (relative to plugin root).
     * @param int    $line The line number where the issue was found.
     */
    public function error(string $code, string $message, ?string $path = null, ?int  $line = null, ?int $column = null, ?string $help = null, ?string $ref = null): void
    {
        $this->issue($code, Issue::SEVERITY_ERROR, $message, $path, $line, $column, $help, $ref);
    }

    /**
     * Adds an issue to the report.
     * The issue is stored only in the current report, not in subreports.
     *
     * @param string $code The issue code.
     * @param int    $severity One of Issue::SEVERITY_* constants.
     * @param string $message The issue message.
     * @param string $path The file path where the issue was found (relative to plugin root).
     * @param int    $line The line number where the issue was found.
     */
    public function issue(string $code, int $severity, string $message, ?string $path = null, ?int $line = null, ?int $column = null, ?string $help = null, ?string $ref = null): void
    {
        $this->addIssue(new Issue($code, $severity, $message, $path, $line, $column, $help, $ref));
    }


    public function addIssues(Issue ...$issues): void {
        foreach ($issues as $issue) {
            $this->addIssue($issue);
        }
    }


    public function addIssue(Issue $issue): void
    {
        $now =  floor(microtime(true) * 1000);

        if (!$this->timeStart) {
            throw new \Exception('Report has not been started yet, use Report::start() before adding issues.');
        }

        if (!$issue->getCode()) {
            throw new \Exception('Issue must have a code.');
        }

        //Normalize the path.
        $path = Path::normalize($issue->getPath());
        if ($path == '.') {
            $path = $this->basePath;
        } else if (!str_starts_with($path, DIRECTORY_SEPARATOR) &&
            !preg_match("/^[A-Z]:\/.+$/", $path)) {
            $path = $this->basePath . (str_ends_with($this->basePath, DIRECTORY_SEPARATOR) ? ''  : DIRECTORY_SEPARATOR) . ltrim($path, '/');
        }
        $issue->setPath($path);
        $issue->setRelativePath(Path::stripBasepath($path, $this->basePath));

        //Set the time, if not already.
        if (!$issue->getTime()) {
            $issue->setTime($now);
        }

        //Clean all strings
        foreach(['Message', 'Help', 'Ref', 'Path', 'Code', 'RelativePath'] as $prop) {
            $get = "get$prop";
            $set = "set$prop";
            $issue->$set(trim($issue->$get()));
        }

        $this->issues[] = $issue;
    }


    /**
     * Recursively checks if the current report or any of its subreports contain issues.
     *
     * @return bool
     */
    public function hasIssues(): bool
    {
        // Check current report's issues.
        if (!empty($this->issues)) {
            return true;
        }

        // Recursively check subreports.
        foreach ($this->subReports as $subReport) {
            if ($subReport->hasIssues()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively gets all issues from the current report and all its subreports.
     *
     * @param bool $byFile If true, groups issues by file path.
     * @return Issue[]|Issue[][]
     */
    public function getIssues(bool $byFile = true, bool $recursive = true): array
    {
        $allIssues = $this->issues;

        if ($recursive) {
            foreach ($this->subReports as $subReport) {
                array_push($allIssues, ...$subReport->getIssues(false));
            }
        }


        if ($byFile) {
            $allIssues = $this->organizeByFile($allIssues);
        }

        return $allIssues;
    }

    /**
     * Returns an array of all child Report objects.
     *
     * @return Report[]
     */
    public function getSubReports(): array
    {
        return $this->subReports;
    }


    /**
     * Gets a list of subreports that have at least one issue (recursively).
     *
     * @return Report[]
     */
    public function getReportWithIssue(): array
    {
        $reportsWithIssues = [];
        foreach ($this->subReports as $subReport) {
            if ($subReport->hasIssues()) {
                $reportsWithIssues[$subReport->name] = $subReport;
            }
        }
        return $reportsWithIssues;
    }

    /**
     * Gets a list of subreports that do not have any issues (recursively).
     *
     * @return Report[]
     */
    public function getReportWithoutIssue(): array
    {
        $reportsWithoutIssues = [];
        foreach ($this->subReports as $subReport) {
            if (!$subReport->hasIssues()) {
                $reportsWithoutIssues[$subReport->name] = $subReport;
            }
        }
        return $reportsWithoutIssues;
    }

    /**
     * Merges multiple Report objects into the current report as children.
     *
     * @param Report ...$reports
     * @return static
     */
    public function mergeIn(Report ...$reports): static
    {
        foreach ($reports as $report) {
            $this->subReports[$report->name] = $report;
        }
        return $this;
    }

    /**
     * Creates a hierarchical Report object from JSON data.
     *
     * @param array $json
     * @return static
     * @throws \Exception
     */
    public static function fromJson(array $json): static {
        if (!isset($json['issues'])) {
            throw new \Exception('Missing required field: issues');
        }

        if (empty($json['name'])) {
            throw new \Exception('Missing required field: name');
        }

        if (empty($json['basePath'])) {
            throw new \Exception('Missing required field: basePath');
        }

        $report = new Report($json['name'], $json['basePath']);

        // Populate issues for the current report
        foreach ($json['issues'] as $path => $issueArray) {
            if (isset($issueArray['severity'])) {
                //Flat structure
                $issue = Issue::fromJson($issueArray);
                self::initRelativePath($report, $issue, $issueArray);
                $report->issues[] = $issue;
            } else {
                //File based structure
                foreach ($issueArray as $issueData) {
                    $issue = Issue::fromJson($issueData);
                    self::initRelativePath($report, $issue, $issueData);
                    $report->issues[] = $issue;
                }
            }
        }

        // Recursively create subreports
        if (isset($json['subReports'])) {
            foreach ($json['subReports'] as $subReportData) {
                if (!isset($subReportData['basePath'])) {
                    $subReportData['basePath'] = Path::normalize($json['basePath']);
                }
                $subReport = self::fromJson($subReportData);
                $report->subReports[$subReport->name] = $subReport;
            }
        }

        if (isset($json['timeStart']) && isset($json['timeEnd'])) {
            $report->timeStart = $json['timeStart'];
            $report->timeEnd = $json['timeEnd'];
        }

        return $report;
    }

    /**
     * Ensures an issue parsed with {@see Issue::fromJson()} has its relativePath set.
     *
     * Issues added via {@see Report::addIssue()} get it computed, but parsed issues are
     * inserted directly, so formats that read the relative path (info, raw, ...) would
     * otherwise fail on the uninitialized typed property.
     *
     * @param array<string, mixed> $data The raw issue data; a provided "relativePath" is kept.
     */
    private static function initRelativePath(Report $report, Issue $issue, array $data): void
    {
        if (array_key_exists('relativePath', $data)) {
            return;
        }

        $issue->setRelativePath(Path::stripBasepath($issue->getPath(), $report->getBasePath()));
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        $serializedSubReports = [];
        foreach ($this->subReports as $subReport) {
            $serializedSubReports[] = $subReport->jsonSerialize();
        }

        $totals = $this->getTotalsRecursive();

        return [
            'name' => $this->name,
            'basePath' => Path::normalize($this->basePath),
            'issues' => $this->getIssues(recursive: false),
            'subReports' => $serializedSubReports,
            'timeStart' => $this->timeStart,
            'timeEnd' => $this->timeEnd,
            'totalErrors' => $totals['totalErrors'],
            'totalWarnings' => $totals['totalWarnings'],
            'totalTips' => $totals['totalTips'],
            'totalFiles' => $totals['totalFiles'],
        ];
    }

    /**
     * Prepares issues for JSON serialization, grouping them by file path.
     *
     * @return array
     */
    private function organizeByFile($issues): array {
        $output = [];
        foreach ($issues as $issue) {
            $output[$issue->getPath()][] = $issue;
        }
        return $output;
    }

    public function getTimeEnd(): ?float
    {
        return round($this->timeEnd, 4);
    }

    public function setTimeEnd(?float $timeEnd): void
    {
        $this->timeEnd = $timeEnd;
    }

    public function getTimeStart(): ?float
    {
        return round($this->timeStart, 4);
    }

    public function setTimeStart(?float $timeStart): void
    {
        $this->timeStart = $timeStart;
    }

    public function getName(string|bool $suffix = false): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Recursively calculates and returns the totals for the entire report hierarchy.
     *
     * @return array An associative array with totalErrors, totalWarnings, totalTips, and totalFiles.
     */
    public function getTotalsRecursive(): array
    {
        $totalErrors = 0;
        $totalWarnings = 0;
        $totalTips = 0;
        $allFilePaths = [];

        // Add issues and file paths from the current report
        foreach ($this->issues as $issue) {
            if ($issue->getSeverity() === Issue::SEVERITY_ERROR) {
                $totalErrors++;
            } elseif ($issue->getSeverity() === Issue::SEVERITY_WARNING) {
                $totalWarnings++;
            } elseif ($issue->getSeverity() === Issue::SEVERITY_TIP) {
                $totalTips++;
            }
            $allFilePaths[] = $issue->getPath();
        }

        // Recursively get totals from all subreports
        foreach ($this->subReports as $subReport) {
            $subTotals = $subReport->getTotalsRecursive();
            $totalErrors += $subTotals['totalErrors'];
            $totalWarnings += $subTotals['totalWarnings'];
            $totalTips += $subTotals['totalTips'];
            $allFilePaths = array_merge($allFilePaths, $subTotals['filePaths']);
        }

        return [
            'totalErrors' => $totalErrors,
            'totalWarnings' => $totalWarnings,
            'totalTips' => $totalTips,
            'totalFiles' => count(array_unique($allFilePaths)),
            'filePaths' => $allFilePaths // Also return file paths to merge in the parent recursion
        ];
    }

    public function getTotalErrors(): int
    {
        $totals = $this->getTotalsRecursive();
        return $totals['totalErrors'];
    }

    public function getTotalWarnings(): int
    {
        $totals = $this->getTotalsRecursive();
        return $totals['totalWarnings'];
    }

    public function getTotalTips(): int
    {
        $totals = $this->getTotalsRecursive();
        return $totals['totalTips'];
    }

    public function getTotalFiles(): int
    {
        $totals = $this->getTotalsRecursive();
        return $totals['totalFiles'];
    }

    public function setTotalTime(?float $totalTime): void
    {
        $this->totalTime = round($totalTime, 4);
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }





}
