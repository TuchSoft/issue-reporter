<?php

namespace Tuchsoft\IssueReporter\Format\Base;

use Tuchsoft\IssueReporter\Report;

trait InfoFormatTrait
{

    use RichFormatTrait;

    protected function getSummary(Report $report) {
        $headers = ['Report', 'Files', 'Errors', 'Warnings', 'Tips', 'Time'];
        $rows = [];
        foreach ([...$report->getSubReports(), $report] as $subReport) {
            $totals = $subReport->getTotalsRecursive();
            $rows[] = [
                $this->escapeTableCell($subReport->getName()),
                $totals['totalFiles'],
                $totals['totalErrors'],
                $totals['totalWarnings'],
                $totals['totalTips'],
                $subReport->getTotalTime() . ' ms',
            ];
        }

        return [
            $headers,
            $rows,
        ];
    }

    protected function getDetails(Report $report)
    {
        $tables = [];
        foreach ($report->getIssues() as $issues) {
            $headers = array_filter([
                'Line',
                ' ',
                'Code',
                'Message',
                $this->options['show-help'] ? 'Help' : null,
                $this->options['show-ref'] ? 'Ref' : null,
            ]);

            $rows = [];
            $path = null;

            foreach ($issues as $issue) {
                $rows[] = array_filter([
                    $issue->getLine() . ($issue->getColumn() ? ":{$issue->getColumn()}" : ''),
                    $this->getSeverityIcon($issue->getSeverity()),
                    $this->escapeTableCell($issue->getCode()),
                    $this->escapeTableCell($issue->getMessage()),
                    $this->options['show-help'] ? $this->escapeTableCell($issue->getHelp()) : null,
                    $this->options['show-ref'] ? $this->escapeTableCell($issue->getRef()) : null
                ], fn($value) => $value !== null);

                if (!$path) {
                    $path = $issue->getRelativePath();
                    if ($path == $report->getBasePath()) {
                        $path = $report->getName();
                    }
                }
            }

            $tables[$path] = [
                $headers,
                $rows,
            ];
        }
        return $tables;
    }



}