<?php

namespace Tuchsoft\IssueReporter\Test\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;

/**
 * Regression tests for the Report/Issue model round-trip via JSON.
 *
 * These guard the invariant that issues reconstructed by Report::fromJson()
 * (the path used by every parsable format) are fully usable: in particular
 * Issue::$relativePath must be initialized, since readable formats read it.
 */
#[CoversClass(Report::class)]
#[CoversClass(Issue::class)]
#[Group('Model')]
class ReportTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(array $issues): array
    {
        return [
            'name' => 'R',
            'basePath' => '/app/src',
            'issues' => $issues,
            'subReports' => [],
            'timeStart' => 0,
            'timeEnd' => 0,
        ];
    }

    public function testFromJsonComputesRelativePathWhenMissing(): void
    {
        $report = Report::fromJson($this->payload([
            ['code' => 'E1', 'severity' => 5, 'message' => 'm', 'path' => '/app/src/A.php', 'line' => 10, 'column' => 5],
        ]));

        $issues = $report->getIssues(false, false);
        self::assertCount(1, $issues);
        self::assertSame('A.php', $issues[0]->getRelativePath());
    }

    public function testFromJsonComputesRelativePathForGroupedIssues(): void
    {
        $report = Report::fromJson($this->payload([
            '/app/src/A.php' => [
                ['code' => 'E1', 'severity' => 5, 'message' => 'm', 'path' => '/app/src/A.php', 'line' => 10],
            ],
        ]));

        $issues = $report->getIssues(false, false);
        self::assertCount(1, $issues);
        self::assertSame('A.php', $issues[0]->getRelativePath());
    }

    public function testFromJsonKeepsProvidedRelativePath(): void
    {
        $report = Report::fromJson($this->payload([
            ['code' => 'E1', 'severity' => 5, 'message' => 'm', 'path' => '/app/src/A.php', 'relativePath' => 'custom/x.php', 'line' => 10],
        ]));

        $issues = $report->getIssues(false, false);
        self::assertSame('custom/x.php', $issues[0]->getRelativePath());
    }

    public function testParsedIssueSerializesRelativePath(): void
    {
        $report = Report::fromJson($this->payload([
            ['code' => 'E1', 'severity' => 5, 'message' => 'm', 'path' => '/app/src/A.php', 'line' => 10],
        ]));

        // Issue::jsonSerialize() reads relativePath; this must not throw.
        $issue = $report->getIssues(false, false)[0];
        $data = $issue->jsonSerialize();
        self::assertSame('A.php', $data['relativePath']);
    }
}
