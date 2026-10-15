<?php

namespace Tuchsoft\IssueReporter\Test\Recipe;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tuchsoft\IssueReporter\Format\Raw;
use Tuchsoft\IssueReporter\Recipe\RecipeBuilder;
use Tuchsoft\IssueReporter\Recipe\RecipeException;
use Tuchsoft\IssueReporter\Report;

#[CoversClass(RecipeBuilder::class)]
#[CoversClass(RecipeException::class)]
#[Group('Recipe')]
class RecipeBuilderTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    private RecipeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RecipeBuilder();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->files = [];
    }

    private function rawFile(string $name, int $errors, int $warnings): string
    {
        $report = new Report($name, '/app');
        $report->start();
        for ($i = 0; $i < $errors; $i++) {
            $report->error("E$i", "error $i", "src/f$i.php", 10);
        }
        for ($i = 0; $i < $warnings; $i++) {
            $report->warning("W$i", "warning $i", "src/w$i.php", 20);
        }
        $report->complete();

        $base = tempnam(sys_get_temp_dir(), 'irb_');
        @unlink($base);
        $path = $base . '.json';
        $this->files[] = $path;
        file_put_contents($path, (new Raw())->generate($report));

        return $path;
    }

    public function testBuildsTreeWithGroupsAndInlineIssues(): void
    {
        $first = $this->rawFile('first', 1, 1);
        $second = $this->rawFile('second', 1, 0);

        $recipe = [
            'name' => 'Root',
            'basePath' => '/project',
            'from' => 'raw',
            'inputs' => [
                ['type' => 'raw', 'path' => $first, 'name' => 'first'],
                [
                    'name' => 'backend',
                    'basePath' => '/project/backend',
                    'inputs' => [
                        ['type' => 'raw', 'path' => $second, 'name' => 'second'],
                    ],
                ],
                [
                    'name' => 'manual',
                    'issues' => [
                        ['code' => 'E1', 'severity' => 5, 'message' => 'boom', 'path' => 'src/A.php', 'line' => 10],
                    ],
                ],
            ],
        ];

        $root = $this->builder->build($recipe, []);

        self::assertSame('Root', $root->getName());
        self::assertSame(3, $root->getTotalErrors());
        self::assertSame(1, $root->getTotalWarnings());

        $sub = $root->getSubReports();
        self::assertCount(3, $sub);
        self::assertArrayHasKey('backend', $sub);
        self::assertCount(1, $sub['backend']->getSubReports());
        self::assertArrayHasKey('manual', $sub);
        self::assertSame(1, $sub['manual']->getTotalErrors());
    }

    public function testDuplicateNameThrows(): void
    {
        $a = $this->rawFile('a', 1, 0);
        $b = $this->rawFile('b', 1, 0);

        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('Duplicate report name');

        $this->builder->build([
            'inputs' => [
                ['type' => 'raw', 'path' => $a, 'name' => 'same'],
                ['type' => 'raw', 'path' => $b, 'name' => 'same'],
            ],
        ], []);
    }

    public function testNonParsableTypeThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('unknown or not parsable');

        $this->builder->build([
            'inputs' => [
                ['type' => 'infomd', 'path' => '/tmp/whatever'],
            ],
        ], []);
    }

    public function testStdinLeafRejectedWhenStdinUsedForRecipe(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('STDIN is already used');

        $this->builder->build([
            'inputs' => [
                ['type' => 'raw', 'path' => '-'],
            ],
        ], [], false);
    }

    public function testInheritedFromAndGroupTypeOverride(): void
    {
        $a = $this->rawFile('a', 1, 0);
        $b = $this->rawFile('b', 1, 0);

        $root = $this->builder->build([
            'from' => 'raw',
            'inputs' => [
                ['path' => $a, 'name' => 'a'], // inherits root "from"
                ['name' => 'g', 'type' => 'raw', 'inputs' => [
                    ['path' => $b, 'name' => 'b'], // inherits group "type"
                ]],
            ],
        ], []);

        self::assertSame(2, $root->getTotalErrors());
        self::assertArrayHasKey('g', $root->getSubReports());
    }

    public function testUnreadableFileLeafThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('Cannot read input file');

        $this->builder->build([
            'inputs' => [
                ['type' => 'raw', 'path' => '/no/such/file-xyz.json'],
            ],
        ], []);
    }

    public function testEmptyInputsBuildsEmptyRoot(): void
    {
        self::assertCount(0, $this->builder->build(['inputs' => []], [])->getSubReports());
        self::assertCount(0, $this->builder->build([], [])->getSubReports());
    }

    public function testCombinesIssuesAndInputs(): void
    {
        $b = $this->rawFile('b', 1, 0);

        $root = $this->builder->build([
            'inputs' => [
                [
                    'name' => 'g',
                    'issues' => [
                        ['code' => 'E1', 'severity' => 5, 'message' => 'm', 'path' => 'p.php', 'line' => 1],
                    ],
                    'inputs' => [
                        ['type' => 'raw', 'path' => $b, 'name' => 'b'],
                    ],
                ],
            ],
        ], []);

        $sub = $root->getSubReports()['g'];
        self::assertSame(2, $sub->getTotalErrors());
        self::assertCount(1, $sub->getSubReports());
    }

    public function testGroupBasePathOverride(): void
    {
        $root = $this->builder->build([
            'basePath' => '/root',
            'inputs' => [
                [
                    'name' => 'g',
                    'basePath' => '/project/sub',
                    'issues' => [
                        ['code' => 'E1', 'severity' => 5, 'message' => 'm', 'path' => 'a.php', 'line' => 1],
                    ],
                ],
            ],
        ], []);

        $sub = $root->getSubReports()['g'];
        self::assertStringContainsString('sub', $sub->getBasePath());
        self::assertStringContainsString('sub', $sub->getIssues(false, false)[0]->getPath());
    }
}
