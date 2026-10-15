<?php

namespace Tuchsoft\IssueReporter\Test\Format;


use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\Raw;
use Tuchsoft\IssueReporter\Format\RawXml;
use Tuchsoft\IssueReporter\Test\Base\AbstractJsonFormatTest;
use Tuchsoft\IssueReporter\Test\Base\AbstractXmlFormatTest;
use Tuchsoft\IssueReporter\Test\Base\Provider\JsonOptionsProvider;
use Tuchsoft\IssueReporter\Test\Base\Provider\ReportProvider;


#[CoversClass(\Tuchsoft\IssueReporter\Format\RawXml::class)]
#[Group('RawXml')]
class RawXmlTest extends AbstractXmlFormatTest
{
    use ReportProvider;
    use JsonOptionsProvider;

    /**
     * @var Raw $formatter
     */
    protected FormatInterface $formatter;
    protected static string $formatterClass = RawXml::class;



    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new RawXml();
    }



    /**
     * Verifies that the parse method throws an exception for data missing required keys.
     * This is handled by Report::fromJson and ensures data integrity.
     */
    public function testParseThrowsExceptionForInvalidStructure(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required field: basePath');
        // A valid JSON, but not a valid Report structure
        $xml = '<?xml version="1.0" encoding="UTF-8"?><report><issues></issues></report>';
        $this->formatter->parse($xml);
    }



}

