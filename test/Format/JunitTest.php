<?php

namespace Tuchsoft\IssueReporter\Test\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SimpleXMLElement;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\Junit;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Test\Base\AbstractXmlFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;

/**
 * Adversarial tests for the JUnit XML format.
 *
 * Covers the generate() output structure (testsuites/testsuite/testcase/failure/properties),
 * option interactions (parse-message, show-help, show-ref, pretty), parsing of valid and
 * hostile inputs and full generate/parse round-trips.
 */
#[CoversClass(\Tuchsoft\IssueReporter\Format\Junit::class)]
#[Group('Junit')]
class JunitTest extends AbstractXmlFormatTest
{
    use ReportProvider;

    /**
     * The JUnit formatter instance used for testing.
     * @var Junit $formatter
     */
    protected FormatInterface $formatter;

    /**
     * The class name of the formatter under test.
     * @var string
     */
    protected static string $formatterClass = Junit::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new Junit();
    }

    /**
     * The message used by the controlled report, full of XML-significant characters.
     */
    private static function specialMessage(): string
    {
        return 'Error with special chars & < > " \' end';
    }

    /**
     * Builds a fully deterministic report (fixed issue data and fixed timings) so that
     * the generated XML can be asserted attribute-by-attribute without time flakiness.
     */
    private function buildControlledReport(): Report
    {
        $report = new Report('JUnit Report', '/project/base');
        $report->start();

        $error = new Issue(
            'ABC.1',
            Issue::SEVERITY_ERROR,
            self::specialMessage(),
            '/project/base/src/A.php',
            10,
            5,
            'https://ex.com/a',
            'Fix A & <b>'
        );
        $error->setExtra(['foo' => 'bar', 'n' => 42]);

        $report->addIssues(
            $error,
            new Issue('WARN.2', Issue::SEVERITY_WARNING, 'Warning msg', '/project/base/src/A.php', 20, 0),
            new Issue('TIP.3', Issue::SEVERITY_TIP, 'Tip msg', '/project/base/src/B.php', 0, 0)
        );

        $report->complete();
        $report->setTimeStart(1000.0);
        $report->setTimeEnd(1003.0);

        return $report;
    }

    /**
     * Builds the expected <testsuite> snippet for the controlled report.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function expectedSuites(bool $parseMessage, bool $showHelp, bool $showRef): array
    {
        $specialMsg = self::specialMessage();
        $extraA = json_encode(['foo' => 'bar', 'n' => 42]);

        $textA = $parseMessage ? 'ERROR on line 10:5 - ' . $specialMsg : $specialMsg;
        $textB = $parseMessage ? 'WARNING on line 20 - Warning msg' : 'Warning msg';
        $textC = $parseMessage ? 'TIP - Tip msg' : 'Tip msg';

        if ($parseMessage && $showHelp) {
            $textA .= '  (Fix A & <b>)';
        }
        if ($parseMessage && $showRef) {
            $textA .= '  [https://ex.com/a]';
        }

        $propsA = ['severity' => '5', 'line' => '10', 'column' => '5', 'extra' => $extraA];
        $propsB = ['severity' => '3', 'line' => '20', 'column' => '0', 'extra' => '[]'];
        $propsC = ['severity' => '0', 'line' => '0', 'column' => '0', 'extra' => '[]'];
        if ($showHelp) {
            $propsA['help'] = 'Fix A & <b>';
            $propsB['help'] = '';
            $propsC['help'] = '';
        }
        if ($showRef) {
            $propsA['ref'] = 'https://ex.com/a';
            $propsB['ref'] = '';
            $propsC['ref'] = '';
        }

        return [
            '/project/base/src/A.php' => [
                'tests' => '2',
                'failures' => '2',
                'cases' => [
                    'ABC.1' => ['line' => '10', 'type' => 'ABC.1', 'message' => $specialMsg, 'text' => $textA, 'properties' => $propsA],
                    'WARN.2' => ['line' => '20', 'type' => 'WARN.2', 'message' => 'Warning msg', 'text' => $textB, 'properties' => $propsB],
                ],
            ],
            '/project/base/src/B.php' => [
                'tests' => '1',
                'failures' => '1',
                'cases' => [
                    'TIP.3' => ['line' => '0', 'type' => 'TIP.3', 'message' => 'Tip msg', 'text' => $textC, 'properties' => $propsC],
                ],
            ],
        ];
    }

    /**
     * Data provider for the exact generated XML structure under option combinations.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function generateProvider(): array
    {
        $root = [
            'failures' => '3',
            'errors' => '1',
            'name' => 'JUnit Report',
            'time' => '3000',
            'timestamp' => '1003',
        ];

        return [
            'default (parse-message, show-help)' => [
                'options' => [],
                'root' => $root,
                'suites' => self::expectedSuites(true, true, false),
            ],
            'no parse-message' => [
                'options' => ['parse-message' => false],
                'root' => $root,
                'suites' => self::expectedSuites(false, true, false),
            ],
            'no show-help' => [
                'options' => ['show-help' => false],
                'root' => $root,
                'suites' => self::expectedSuites(true, false, false),
            ],
            'show-ref' => [
                'options' => ['show-ref' => true],
                'root' => $root,
                'suites' => self::expectedSuites(true, true, true),
            ],
            'no parse-message, no show-help, show-ref' => [
                'options' => ['parse-message' => false, 'show-help' => false, 'show-ref' => true],
                'root' => $root,
                'suites' => self::expectedSuites(false, false, true),
            ],
        ];
    }

    /**
     * Asserts the exact generated XML structure: root aggregates, testsuites, testcases,
     * failures and properties (names, order and values).
     *
     * @param array<string, bool> $options
     * @param array<string, string> $root
     * @param array<string, mixed> $suites
     */
    #[DataProvider('generateProvider')]
    public function testGenerateStructure(array $options, array $root, array $suites): void
    {
        $this->formatter->setOptions($options);
        $report = $this->buildControlledReport();

        $xmlString = $this->formatter->generate($report);
        $xml = simplexml_load_string($xmlString);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml, 'Generated output is not valid XML.');
        $this->assertSame('testsuites', $xml->getName(), 'Root element must be <testsuites>.');

        foreach ($root as $attribute => $value) {
            $this->assertSame($value, (string)$xml[$attribute], "testsuites/@$attribute mismatch.");
        }

        $xmlSuites = [];
        foreach ($xml->testsuite as $suite) {
            $xmlSuites[(string)$suite['name']] = $suite;
        }
        $this->assertSame(array_keys($suites), array_keys($xmlSuites), 'Unexpected testsuite set/order.');

        foreach ($suites as $path => $suiteExp) {
            $suite = $xmlSuites[$path];
            $this->assertSame($suiteExp['tests'], (string)$suite['tests'], "testsuite[@name=$path]/@tests mismatch.");
            $this->assertSame($suiteExp['failures'], (string)$suite['failures'], "testsuite[@name=$path]/@failures mismatch.");
            $this->assertSame($path, (string)$suite['file'], "testsuite[@name=$path]/@file mismatch.");

            $xmlCases = [];
            foreach ($suite->testcase as $case) {
                $xmlCases[(string)$case['name']] = $case;
            }
            $this->assertSame(array_keys($suiteExp['cases']), array_keys($xmlCases), "Unexpected testcase set/order in $path.");

            foreach ($suiteExp['cases'] as $code => $caseExp) {
                $case = $xmlCases[$code];
                $this->assertSame($code, (string)$case['name'], 'testcase/@name mismatch.');
                $this->assertSame($path, (string)$case['file'], 'testcase/@file mismatch.');
                $this->assertSame($caseExp['line'], (string)$case['line'], "testcase[@name=$code]/@line mismatch.");

                $this->assertInstanceOf(SimpleXMLElement::class, $case->failure, "testcase[@name=$code] is missing a <failure>.");
                $failure = $case->failure;
                $this->assertSame($caseExp['type'], (string)$failure['type'], 'failure/@type mismatch.');
                $this->assertSame($caseExp['message'], (string)$failure['message'], 'failure/@message mismatch.');
                $this->assertSame($caseExp['text'], (string)$failure, 'failure text content mismatch.');

                $properties = [];
                foreach ($case->properties->property as $property) {
                    $properties[(string)$property['name']] = (string)$property['value'];
                }
                $this->assertSame($caseExp['properties'], $properties, "property set mismatch for testcase $code.");
            }
        }
    }

    /**
     * Valid, structural parse cases (parsable-message parsing disabled to isolate the
     * XML structure handling from the message-parsing heuristics).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function parseProvider(): array
    {
        return [
            'failure without properties' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0" encoding="UTF-8"?>
<testsuites name="Root Name">
  <testsuite name="/project/base/src/A.php">
    <testcase name="C1" line="12"><failure type="T1" message="Msg 1">body text</failure></testcase>
  </testsuite>
</testsuites>',
                'expectedName' => 'Root Name',
                'expectedBasePath' => '/project/base/src/',
                'expectedIssues' => [
                    ['code' => 'T1', 'message' => 'Msg 1', 'line' => 12, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'body text', 'ref' => '', 'extra' => []],
                ],
            ],
            'error element only' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php"><testcase name="C" line="3"><error type="E1" message="Err msg">err body</error></testcase></testsuite></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => '/p/',
                'expectedIssues' => [
                    ['code' => 'E1', 'message' => 'Err msg', 'line' => 3, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'err body', 'ref' => '', 'extra' => []],
                ],
            ],
            'testcase without failure or error is skipped' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php"><testcase name="C1"><skipped/></testcase><testcase name="C2"><failure type="T" message="M">b</failure></testcase></testsuite></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => '/p/',
                'expectedIssues' => [
                    ['code' => 'T', 'message' => 'M', 'line' => 0, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'b', 'ref' => '', 'extra' => []],
                ],
            ],
            'multiple testsuites' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"><testsuite name="/project/base/a.php"><testcase name="C1" line="1"><failure type="T1" message="M1">b1</failure></testcase></testsuite><testsuite name="/project/base/b.php"><testcase name="C2" line="2"><failure type="T2" message="M2">b2</failure></testcase></testsuite></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => '/project/base/',
                'expectedIssues' => [
                    ['code' => 'T1', 'message' => 'M1', 'line' => 1, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'b1', 'ref' => '', 'extra' => []],
                    ['code' => 'T2', 'message' => 'M2', 'line' => 2, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'b2', 'ref' => '', 'extra' => []],
                ],
            ],
            'properties override attributes' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php"><testcase name="IGNORED" line="12"><failure type="TYPE.from.attr" message="Attr message">attr body</failure><properties><property name="severity" value="3"/><property name="line" value="99"/><property name="column" value="7"/><property name="extra" value="{&quot;k&quot;:&quot;v&quot;,&quot;n&quot;:5}"/><property name="ref" value="https://ref.example"/><property name="help" value="Prop help"/></properties></testcase></testsuite></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => '/p/',
                'expectedIssues' => [
                    ['code' => 'TYPE.from.attr', 'message' => 'Attr message', 'line' => 99, 'column' => 7, 'severity' => Issue::SEVERITY_WARNING, 'help' => 'Prop help', 'ref' => 'https://ref.example', 'extra' => ['k' => 'v', 'n' => 5]],
                ],
            ],
            'duplicate testcases are all kept' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php"><testcase name="C"><failure type="T" message="M">b</failure></testcase><testcase name="C"><failure type="T" message="M">b</failure></testcase></testsuite></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => '/p/',
                'expectedIssues' => [
                    ['code' => 'T', 'message' => 'M', 'line' => 0, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'b', 'ref' => '', 'extra' => []],
                    ['code' => 'T', 'message' => 'M', 'line' => 0, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'b', 'ref' => '', 'extra' => []],
                ],
            ],
            'both failure and error prefers failure' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php"><testcase name="C"><failure type="F" message="fail">fb</failure><error type="E" message="err">eb</error></testcase></testsuite></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => '/p/',
                'expectedIssues' => [
                    ['code' => 'F', 'message' => 'fail', 'line' => 0, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'fb', 'ref' => '', 'extra' => []],
                ],
            ],
            'empty report' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => '/',
                'expectedIssues' => [],
            ],
            'unknown elements are ignored' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php"><system-out>noise</system-out><testcase name="C"><failure type="T" message="M">b</failure></testcase><system-err>noise2</system-err></testsuite></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => '/p/',
                'expectedIssues' => [
                    ['code' => 'T', 'message' => 'M', 'line' => 0, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'b', 'ref' => '', 'extra' => []],
                ],
            ],
            'nested testsuite is not descended into' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites name="R"><testsuite name="outer"><testsuite name="inner"><testcase name="C"><failure type="T" message="M">b</failure></testcase></testsuite></testsuite></testsuites>',
                'expectedName' => 'R',
                'expectedBasePath' => 'outer/',
                'expectedIssues' => [],
            ],
            'name falls back to provided name' => [
                'options' => ['parse-message' => false],
                'xmlInput' => '<?xml version="1.0"?><testsuites><testsuite name="/p/a.php"><testcase name="C"><failure type="T" message="M">b</failure></testcase></testsuite></testsuites>',
                'expectedName' => 'Parsed Name',
                'expectedBasePath' => '/p/',
                'expectedIssues' => [
                    ['code' => 'T', 'message' => 'M', 'line' => 0, 'column' => 0, 'severity' => Issue::SEVERITY_ERROR, 'help' => 'b', 'ref' => '', 'extra' => []],
                ],
            ],
        ];
    }

    /**
     * @param array<string, bool> $options
     * @param array<int, array<string, mixed>> $expectedIssues
     */
    #[DataProvider('parseProvider')]
    public function testParse(array $options, string $xmlInput, string $expectedName, string $expectedBasePath, array $expectedIssues): void
    {
        $this->formatter->setOptions($options);

        $report = $this->formatter->parse($xmlInput, 'Parsed Name');
        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame($expectedName, $report->getName());
        $this->assertSame($expectedBasePath, $report->getBasePath());

        $issues = $report->getIssues(false, false);
        $this->assertCount(count($expectedIssues), $issues);

        foreach ($expectedIssues as $index => $expected) {
            $issue = $issues[$index];
            $this->assertSame($expected['code'], $issue->getCode(), "code mismatch at index $index");
            $this->assertSame($expected['message'], $issue->getMessage(), "message mismatch at index $index");
            $this->assertSame($expected['line'], $issue->getLine(), "line mismatch at index $index");
            $this->assertSame($expected['column'], $issue->getColumn(), "column mismatch at index $index");
            $this->assertSame($expected['severity'], $issue->getSeverity(), "severity mismatch at index $index");
            $this->assertSame($expected['help'], $issue->getHelp(), "help mismatch at index $index");
            $this->assertSame($expected['ref'], $issue->getRef(), "ref mismatch at index $index");
            $this->assertSame($expected['extra'], $issue->getExtra(), "extra mismatch at index $index");
        }
    }

    // ---------------------------------------------------------------------
    // Round-trip tests
    // ---------------------------------------------------------------------

    /**
     * A default generate/parse cycle must preserve every field that this format is
     * capable of storing.
     */
    public function testGenerateParseRoundTripPreservesFieldsWhenParsableMessageDisabled(): void
    {
        $this->formatter->setOptions(['parse-message' => false, 'show-ref' => true]);
        $report = $this->buildControlledReport();

        $xmlString = $this->formatter->generate($report);
        $parsed = $this->formatter->parse($xmlString, 'JUnit Report');
        $issues = $parsed->getIssues(false, false);

        $this->assertCount(3, $issues);

        $error = $issues[0];
        $this->assertSame('ABC.1', $error->getCode());
        $this->assertSame(self::specialMessage(), $error->getMessage());
        $this->assertSame(10, $error->getLine());
        $this->assertSame(5, $error->getColumn());
        $this->assertSame(Issue::SEVERITY_ERROR, $error->getSeverity());
        $this->assertSame('Fix A & <b>', $error->getHelp());
        $this->assertSame('https://ex.com/a', $error->getRef());
        $this->assertSame(['foo' => 'bar', 'n' => 42], $error->getExtra());

        $warning = $issues[1];
        $this->assertSame('WARN.2', $warning->getCode());
        $this->assertSame(Issue::SEVERITY_WARNING, $warning->getSeverity());
        $this->assertSame(20, $warning->getLine());

        $tip = $issues[2];
        $this->assertSame('TIP.3', $tip->getCode());
        $this->assertSame(Issue::SEVERITY_TIP, $tip->getSeverity());
        $this->assertSame(0, $tip->getLine());
    }

    /**
     * With the DEFAULT options (parse-message enabled), the issue code must still survive
     * a generate/parse cycle.
     */
    public function testParsePreservesIssueCodeWithDefaultOptions(): void
    {
        $report = $this->buildControlledReport();
        $xmlString = $this->formatter->generate($report);

        $parsed = $this->formatter->parse($xmlString, 'JUnit Report');
        $issues = $parsed->getIssues(false, false);

        $this->assertSame('ABC.1', $issues[0]->getCode(), 'Issue code should survive a default generate/parse cycle.');
    }

    /**
     * A raw JUnit document (no <properties> block) must keep the line number from the
     * <testcase line="..."> attribute even when parse-message is enabled.
     */
    public function testParsePreservesLineNumberWithoutPropertiesBlock(): void
    {
        $xmlInput = '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php">'
            . '<testcase name="C1" line="7"><failure type="T1" message="Msg">body</failure></testcase>'
            . '</testsuite></testsuites>';

        $report = $this->formatter->parse($xmlInput, 'R');
        $issue = $report->getIssues(false, false)[0];

        $this->assertSame(7, $issue->getLine(), 'Line attribute should survive parsing without a <properties> block.');
    }

    /**
     * Parsing a plain message must not emit PHP "Undefined array key" warnings.
     */
    public function testParseEmitsNoUndefinedArrayKeyWarnings(): void
    {
        $xmlInput = '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php">'
            . '<testcase><failure message="plain message">body</failure></testcase>'
            . '</testsuite></testsuites>';

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $this->formatter->parse($xmlInput, 'R');
            $this->addToAssertionCount(1);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The <time> attribute is written in milliseconds but read back as seconds, so a
     * round-trip corrupts the report timing. This test documents the expected behaviour.
     */
    public function testRoundTripPreservesReportTiming(): void
    {
        $report = $this->buildControlledReport();
        $this->assertSame(3000.0, (float)$report->getTotalTime(), 'Precondition: total time is 3000ms.');

        $xmlString = $this->formatter->generate($report);
        $parsed = $this->formatter->parse($xmlString, 'JUnit Report');

        $this->assertEqualsWithDelta(1000.0, $parsed->getTimeStart(), 0.0002, 'Report start time is not preserved.');
        $this->assertEqualsWithDelta(1003.0, $parsed->getTimeEnd(), 0.0002, 'Report end time is not preserved.');
        $this->assertEqualsWithDelta(3000.0, (float)$parsed->getTotalTime(), 0.0002, 'Report total time is not preserved.');
    }

    // ---------------------------------------------------------------------
    // Hostile / edge case parsing
    // ---------------------------------------------------------------------

    public function testParseThrowsForTruncatedXml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML input');
        $this->formatter->parse('<?xml version="1.0"?><testsuites name="R"><testsuite name="f.php"><testcase');
    }

    public function testParseWhitespaceOnlyThrowsInvalidXml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML input');
        $this->formatter->parse("  \n\t ");
    }

    /**
     * The parser does not validate the document's root element (unlike e.g. Checkstyle),
     * so any root carrying <testsuite> children is accepted.
     */
    public function testParseDoesNotValidateRootElement(): void
    {
        $xmlInput = '<?xml version="1.0"?><not-testsuites name="R"><testsuite name="/p/a.php">'
            . '<testcase name="C"><failure type="T" message="M">b</failure></testcase></testsuite></not-testsuites>';

        $report = $this->formatter->parse($xmlInput, 'R');

        $this->assertSame('R', $report->getName());
        $this->assertCount(1, $report->getIssues(false, false));
    }

    /**
     * A document whose root is a bare <testsuite> (common for single-suite producers)
     * yields no issues because only <testsuites> children are traversed.
     */
    public function testParseBareTestsuiteRootYieldsNoIssues(): void
    {
        $xmlInput = '<?xml version="1.0"?><testsuite name="f.php">'
            . '<testcase name="C"><failure type="T" message="M">b</failure></testcase></testsuite>';

        $report = $this->formatter->parse($xmlInput, 'Provided Name');

        $this->assertCount(0, $report->getIssues(false, false));
    }

    public function testParseReadsTimestampAndTimeAttributes(): void
    {
        $xmlInput = '<?xml version="1.0"?><testsuites name="R" timestamp="1000" time="250">'
            . '<testsuite name="/p/a.php"><testcase name="C"><failure type="T" message="M">b</failure></testcase></testsuite></testsuites>';

        $report = $this->formatter->parse($xmlInput, 'R');

        $this->assertEqualsWithDelta(1000.0, $report->getTimeEnd(), 0.0002);
        // "time" is expressed in milliseconds (as generated by Report::getTotalTime()),
        // so 250 means 0.25s and the start is 1000 - 250/1000.
        $this->assertEqualsWithDelta(999.75, $report->getTimeStart(), 0.0002);
    }

    public function testParseHandlesNonArrayExtraPropertyGracefully(): void
    {
        // json_decode('123') yields an int, which is not a valid "extra" payload.
        $xmlInput = '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php">'
            . '<testcase name="C"><failure type="T" message="M">b</failure>'
            . '<properties><property name="extra" value="123"/></properties></testcase></testsuite></testsuites>';

        $report = $this->formatter->parse($xmlInput, 'R');
        $issues = $report->getIssues(false, false);

        $this->assertCount(1, $issues);
        $this->assertSame([], $issues[0]->getExtra(), 'A non-array "extra" value must not crash parsing.');
    }

    public function testParseNonObjectExtraJsonBecomesEmptyArray(): void
    {
        $xmlInput = '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php">'
            . '<testcase name="C"><failure type="T" message="M">b</failure>'
            . '<properties><property name="extra" value="null"/></properties></testcase></testsuite></testsuites>';

        $report = $this->formatter->parse($xmlInput, 'R');

        $this->assertSame([], $report->getIssues(false, false)[0]->getExtra());
    }

    public function testParseBrokenExtraJsonBecomesEmptyArray(): void
    {
        $xmlInput = '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php">'
            . '<testcase name="C"><failure type="T" message="M">b</failure>'
            . '<properties><property name="extra" value="{not json"/></properties></testcase></testsuite></testsuites>';

        $report = $this->formatter->parse($xmlInput, 'R');

        $this->assertSame([], $report->getIssues(false, false)[0]->getExtra());
    }

    public function testParseZeroAndNegativeLineNumbers(): void
    {
        $this->formatter->setOptions(['parse-message' => false]);

        $xmlInput = '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php">'
            . '<testcase name="ZERO" line="0"><failure type="T0" message="zero">b</failure></testcase>'
            . '<testcase name="NEG" line="-5"><failure type="T1" message="neg">b</failure></testcase>'
            . '</testsuite></testsuites>';

        $issues = $this->formatter->parse($xmlInput, 'R')->getIssues(false, false);

        $this->assertSame(0, $issues[0]->getLine());
        $this->assertSame(-5, $issues[1]->getLine());
    }

    public function testParseEmptyMessageStaysEmpty(): void
    {
        $this->formatter->setOptions(['parse-message' => false]);

        $xmlInput = '<?xml version="1.0"?><testsuites name="R"><testsuite name="/p/a.php">'
            . '<testcase name="C" line="1"><failure type="T1" message="">body</failure></testcase></testsuite></testsuites>';

        $issue = $this->formatter->parse($xmlInput, 'R')->getIssues(false, false)[0];

        $this->assertSame('', $issue->getMessage());
        $this->assertSame('T1', $issue->getCode());
    }

    /**
     * Special characters, unicode, newlines, tabs and very long strings must survive a
     * generate/parse round-trip verbatim.
     */
    public function testGenerateParseRoundTripPreservesSpecialCharacters(): void
    {
        $this->formatter->setOptions(['parse-message' => false]);

        $messages = [
            'amp & lt < gt > quot " apos \'',
            'unicode: é ü 日本語 🎉',
            "line1\nline2\ttabbed",
            str_repeat('x', 5000),
        ];

        foreach ($messages as $message) {
            $report = new Report('R', '/p');
            $report->start();
            $report->addIssue(new Issue('C', Issue::SEVERITY_WARNING, $message, '/p/f.php', 1, 1));
            $report->complete();

            $xmlString = $this->formatter->generate($report);
            $parsed = $this->formatter->parse($xmlString, 'R');

            $this->assertSame($message, $parsed->getIssues(false, false)[0]->getMessage(), 'Message was not preserved verbatim.');
        }
    }

    public function testGenerateEmptyReportProducesEmptyTestsuites(): void
    {
        $report = new Report('Empty Report', '/project/base');
        $report->start();
        $report->complete();
        $report->setTimeStart(10.0);
        $report->setTimeEnd(10.0);

        $xmlString = $this->formatter->generate($report);
        $xml = simplexml_load_string($xmlString);

        $this->assertSame('testsuites', $xml->getName());
        $this->assertSame('0', (string)$xml['failures']);
        $this->assertSame('0', (string)$xml['errors']);
        $this->assertSame('Empty Report', (string)$xml['name']);
        $this->assertSame(0, $xml->testsuite->count());

        $parsed = $this->formatter->parse($xmlString, 'Empty Report');
        $this->assertSame('Empty Report', $parsed->getName());
        $this->assertCount(0, $parsed->getIssues(false, false));
    }
}
