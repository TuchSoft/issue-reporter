<?php

namespace Tuchsoft\IssueReporter\Format;

use DOMDocument;
use DOMException;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Tuchsoft\IssueReporter\Format\Base\AbstractFormat;
use Tuchsoft\IssueReporter\Format\Base\ParsableFormatInterface;
use Tuchsoft\IssueReporter\Format\Base\ParsableMessageFormatTrait;
use Tuchsoft\IssueReporter\Format\Base\XmlFormatTrait;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Utils\Path;

/**
 * An implementation of a report format that serializes and deserializes
 * a Report object to and from the JUnit XML format.
 */
class Junit extends AbstractFormat implements ParsableFormatInterface
{
    use XmlFormatTrait;
    use ParsableMessageFormatTrait;

    /**
     * @return string The description of the format.
     */
    public static function getDesc(): string
    {
        return "JUnit XML representation for static analysis reports";
    }

    public static function supports(): array
    {
        return self::FEATURE_ISSUE_STANDARD;
    }

    public static function supportsExtra(): array
    {
        return [];
    }

    public static function getOptionsDefinition(int $returnType = self::OPTIONS_NORMAL): array
    {
        return [...parent::getOptionsDefinition($returnType), ...self::getXmlOptions($returnType), ...self::newOption('parse-message', InputOption::VALUE_NEGATABLE, 'try (or don\'t try --no-show-ref) to parse the message for help and ref field', true, $returnType)];
    }

    /**
     * Generates a JUnit XML string from a Report object.
     *
     * @param Report $report The report object to serialize.
     * @return string The JUnit XML string.
     * @throws DOMException If there is an error creating the XML document.
     */
    public function generate(Report $report): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;


        $totalErrorCount = $report->getTotalErrors();
        $totalWarningCount = $report->getTotalWarnings() + $report->getTotalTips();

        // Create the root <testsuites> element
        $testSuites = $dom->createElement('testsuites');
        $testSuites->setAttribute('failures', (string)($totalErrorCount + $totalWarningCount));
        $testSuites->setAttribute('errors', (string)$totalErrorCount);
        $testSuites->setAttribute('name', $report->getName());
        $testSuites->setAttribute('time', $report->getTotalTime());
        $testSuites->setAttribute('timestamp', (string)round($report->getTimeEnd()));
        $dom->appendChild($testSuites);

        foreach ($report->getIssues() as $path => $issues) {
            $testSuite = $dom->createElement('testsuite');
            $testSuite->setAttribute('name', $path);
            $testSuite->setAttribute('tests', (string)count($issues));
            $testSuite->setAttribute('failures', (string)count($issues));
            $testSuite->setAttribute('file', $path);
            $testSuites->appendChild($testSuite);

            /** @var Issue $issue */
            foreach ($issues as $issue) {
                $props = ['severity' => $issue->getSeverity(), 'line' => $issue->getLine(), 'column' => $issue->getColumn(), 'extra' => json_encode($issue->getExtra())];

                if ($this->options['show-help']) {
                    $props['help'] = $issue->getHelp();
                }

                if ($this->options['show-ref']) {
                    $props['ref'] = $issue->getRef();
                }


                $testCase = $dom->createElement('testcase');
                $testCase->setAttribute('name',  $issue->getCode());
                $testCase->setAttribute('file', $path);
                $testCase->setAttribute('line', $issue->getLine());

                $failure = $dom->createElement('failure');


                $failure->setAttribute('type', $issue->getCode());

                $failure->setAttribute('message', $issue->getMessage());
                $fullMessage = $this->getParsableMessage($issue, true);

                $failure->appendChild($dom->createTextNode($fullMessage));

                $properties = $dom->createElement('properties');
                foreach ($props as $key => $value) {
                    $property = $dom->createElement('property');
                    $property->setAttribute('name', $key);
                    $property->setAttribute('value', $value);
                    $properties->appendChild($property);
                }


                $testCase->appendChild($failure);
                $testCase->appendChild($properties);
                $testSuite->appendChild($testCase);
            }
        }

        return $this->xmlEncode($dom);
    }

    /**
     * Parses a JUnit XML string and returns a Report object.
     *
     * @param string $input The XML string to parse.
     * @param string $name The name for the new Report object.
     * @return Report The parsed Report object.
     * @throws InvalidArgumentException If the XML is invalid or the structure is incorrect.
     */
    public function parse(string $input, ?string $name = null): Report
    {
        if (!$name) {
            $name = static::getDefaultReportName();
        }

        if (trim($input) === '') {
            throw new InvalidArgumentException('Invalid XML input: empty string');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($input);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMessage = "Invalid XML input: ";
            foreach ($errors as $error) {
                $errorMessage .= "{$error->message} ";
            }
            libxml_clear_errors();
            throw new InvalidArgumentException($errorMessage);
        }

        $flatIssues = [];
        $reportName = (string)($xml['name'] ?? $name);
        $allPaths = [];

        foreach ($xml->testsuite as $testsuite) {
            $path = (string)$testsuite['name'];
            $allPaths[] = $path;

            foreach ($testsuite->testcase as $testcase) {
                // If no failure or error, move to the next item
                if (!isset($testcase->failure) && !isset($testcase->error)) {
                    continue;
                }

                $issueElement = $testcase->failure ?? $testcase->error;
                $message = (string)($issueElement['message'] ?? $issueElement[0]);
                $help = (string)($issueElement['message'] ? ($issueElement[0] ?? null) : $issueElement[1] ?? null);


                $severity = Issue::SEVERITY_ERROR;
                $code = (string)$issueElement['type'];
                $line = (int)($testcase['line'] ?? 0);
                $column = 0;
                $extra = [];
                $ref = '';


                if ($this->options['parse-message']) {
                    $parsed = $this->parseMessage($message);
                    if (isset($parsed['message']) && $parsed['message'] !== '') {
                        $message = $parsed['message'];
                    }
                    if (!$line && !empty($parsed['line'])) {
                        $line = (int)$parsed['line'];
                    }
                    if (!empty($parsed['col'])) {
                        $column = (int)$parsed['col'];
                    }
                    if (!empty($parsed['help'])) {
                        $help = $parsed['help'];
                    }
                    if (!empty($parsed['ref'])) {
                        $ref = $parsed['ref'];
                    }
                    if (!$code && !empty($parsed['cod'])) {
                        $code = $parsed['cod'];
                    }
                    if (!empty($parsed['severity'])) {
                        $severity = match (strtoupper(trim($parsed['severity']))) {
                            'ERROR' => Issue::SEVERITY_ERROR,
                            'WARNING' => Issue::SEVERITY_WARNING,
                            'TIP' => Issue::SEVERITY_TIP,
                            default => $severity,
                        };
                    }
                }


                if (isset($testcase->properties)) {
                    foreach ($testcase->properties->property as $property) {
                        $propName = (string)$property['name'];
                        $propValue = (string)$property['value'];

                        switch ($propName) {
                            case 'severity':
                                $severity = (int)$propValue;
                                break;
                            case 'line':
                                $line = (int)$propValue;
                                break;
                            case 'column':
                                $column = (int)$propValue;
                                break;
                            case 'extra':
                                $decodedExtra = json_decode($propValue, true);
                                $extra = is_array($decodedExtra) ? $decodedExtra : [];
                                break;
                            case 'ref':
                                $ref = $propValue;
                                break;
                            case 'help':
                                $help = $propValue;
                                break;
                        }
                    }
                }
                $flatIssues[] = ['message' => $message, 'line' => $line, 'column' => $column, 'path' => $path, 'code' => $code, 'severity' => $severity, 'ref' => $ref, 'help' => $help, 'extra' => $extra,];
            }
        }

        $timeEnd = (int)($xml['timestamp'] ?? 0);
        // The "time" attribute is written in milliseconds (like Report::getTotalTime()),
        // while "timestamp" is in epoch seconds, so convert when reconstructing the start.
        $timeStart = $timeEnd ? $timeEnd - ((int)($xml['time'] ?? 0) / 1000) : 0;

        $reportData = ['name' => $reportName, 'issues' => $flatIssues, 'subReports' => [], 'timeStart' => $timeStart, 'timeEnd' => $timeEnd, 'basePath' => Path::findCommonBasePath($allPaths)];

        return Report::fromJson($reportData);
    }
}
