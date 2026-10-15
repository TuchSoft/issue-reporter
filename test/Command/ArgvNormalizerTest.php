<?php

namespace Tuchsoft\IssueReporter\Test\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tuchsoft\IssueReporter\Command\ArgvNormalizer;

#[CoversClass(ArgvNormalizer::class)]
#[Group('Command')]
class ArgvNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string[], 1: string[]}>
     */
    public static function cases(): array
    {
        return [
            'long form' => [
                ['bin/console', 'convert', '--config', '-'],
                ['bin/console', 'convert', '--config=-'],
            ],
            'short form' => [
                ['bin/console', 'convert', '-c', '-'],
                ['bin/console', 'convert', '-c-'],
            ],
            'long form followed by another option' => [
                ['bin/console', 'convert', '--config', '-', '--quiet'],
                ['bin/console', 'convert', '--config=-', '--quiet'],
            ],
            'already normalized long' => [
                ['bin/console', 'convert', '--config=-'],
                ['bin/console', 'convert', '--config=-'],
            ],
            'already normalized short' => [
                ['bin/console', 'convert', '-c-'],
                ['bin/console', 'convert', '-c-'],
            ],
            'normal file value untouched' => [
                ['bin/console', 'convert', '--config', 'recipe.json'],
                ['bin/console', 'convert', '--config', 'recipe.json'],
            ],
            'unrelated lone dash untouched' => [
                ['bin/console', 'convert', 'report.json', '-'],
                ['bin/console', 'convert', 'report.json', '-'],
            ],
            'empty argv' => [
                [],
                [],
            ],
            'only program name' => [
                ['bin/console'],
                ['bin/console'],
            ],
        ];
    }

    /**
     * @param string[] $argv
     * @param string[] $expected
     */
    #[DataProvider('cases')]
    public function testNormalize(array $argv, array $expected): void
    {
        self::assertSame($expected, ArgvNormalizer::normalize($argv));
    }
}
