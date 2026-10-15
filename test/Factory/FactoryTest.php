<?php

namespace Tuchsoft\IssueReporter\Test\Factory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tuchsoft\IssueReporter\Factory;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Format\Raw;
use Tuchsoft\IssueReporter\Transformer\base\TransformerInterface;
use Tuchsoft\IssueReporter\Transformer\MessageReplacer;

#[CoversClass(Factory::class)]
#[Group('Factory')]
class FactoryTest extends TestCase
{
    public function testBuiltInFormatsAreRegistered(): void
    {
        $formats = Factory::getRegistered(Factory::FORMAT);

        self::assertArrayHasKey('raw', $formats);
        self::assertArrayHasKey('phpcs', $formats);
        self::assertTrue(is_a($formats['raw'], FormatInterface::class, true));
    }

    public function testBuiltInTransformersAreRegistered(): void
    {
        $transformers = Factory::getRegistered(Factory::TRANSFORMER);

        // Guards the per-type interface validation: transformers must not be
        // dropped by the format-only check.
        self::assertArrayHasKey('messagereplacer', $transformers);
        self::assertTrue(is_a($transformers['messagereplacer'], TransformerInterface::class, true));
    }

    public function testCreateFormat(): void
    {
        self::assertInstanceOf(Raw::class, Factory::create(Factory::FORMAT, 'raw', []));
    }

    public function testCreateTransformer(): void
    {
        self::assertInstanceOf(MessageReplacer::class, Factory::create(Factory::TRANSFORMER, 'messagereplacer', []));
    }

    public function testCreateUnknownThrows(): void
    {
        $this->expectException(\Exception::class);
        Factory::create(Factory::FORMAT, 'does-not-exist', []);
    }
}
