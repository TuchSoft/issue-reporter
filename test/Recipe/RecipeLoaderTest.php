<?php

namespace Tuchsoft\IssueReporter\Test\Recipe;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tuchsoft\IssueReporter\Recipe\RecipeException;
use Tuchsoft\IssueReporter\Recipe\RecipeLoader;

#[CoversClass(RecipeLoader::class)]
#[CoversClass(RecipeException::class)]
#[Group('Recipe')]
class RecipeLoaderTest extends TestCase
{
    private RecipeLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new RecipeLoader();
    }

    public function testLoadsFromStdinString(): void
    {
        $recipe = $this->loader->load('-', '{"name":"X","to":"raw"}');

        self::assertSame('X', $recipe['name']);
        self::assertSame('raw', $recipe['to']);
    }

    public function testStdinWithoutContentThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->loader->load('-', null);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('Invalid JSON');
        $this->loader->load('-', '{not json');
    }

    public function testNonObjectThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be a JSON object');
        $this->loader->load('-', '[1,2,3]');
    }

    public function testUnknownTopKeyThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('Unknown key "inptu"');
        $this->loader->load('-', '{"inptu":[]}');
    }

    public function testUnknownNodeKeyThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('Unknown key "pnath"');
        $this->loader->load('-', '{"inputs":[{"pnath":"a"}]}');
    }

    public function testNodeWithoutContentThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must define at least one of');
        $this->loader->load('-', '{"inputs":[{"name":"empty"}]}');
    }

    public function testNodeWithPathAndInputsThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('cannot combine "path" with');
        $this->loader->load('-', '{"inputs":[{"path":"a","inputs":[]}]}');
    }

    public function testNodeWithBothTypeAndFormatThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('defines both "type" and "format"');
        $this->loader->load('-', '{"inputs":[{"path":"a","type":"raw","format":"raw"}]}');
    }

    public function testIssueMissingRequiredKeyThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('missing required key "line"');
        $this->loader->load('-', '{"inputs":[{"issues":[{"code":"E","severity":5,"message":"m","path":"p"}]}]}');
    }

    public function testFormatAliasIsNormalizedToType(): void
    {
        $recipe = $this->loader->load('-', '{"inputs":[{"path":"a","format":"raw"}]}');

        self::assertSame('raw', $recipe['inputs'][0]['type']);
        self::assertArrayNotHasKey('format', $recipe['inputs'][0]);
    }

    public function testNodeMayCombineIssuesAndInputs(): void
    {
        $recipe = $this->loader->load('-', '{"inputs":[{"name":"g","issues":[{"code":"E","severity":5,"message":"m","path":"p","line":1}],"inputs":[{"path":"a"}]}]}');

        self::assertArrayHasKey('issues', $recipe['inputs'][0]);
        self::assertArrayHasKey('inputs', $recipe['inputs'][0]);
    }

    public function testFileNotFoundThrows(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('Cannot read recipe file');
        $this->loader->load('/no/such/recipe-file.json');
    }

    public function testLoadsFromFile(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'rcp_');
        @unlink($base);
        $path = $base . '.json';
        file_put_contents($path, '{"name":"FromFile","to":"raw"}');

        try {
            $recipe = $this->loader->load($path);
            self::assertSame('FromFile', $recipe['name']);
        } finally {
            @unlink($path);
        }
    }

    public function testEmptyRecipeLoads(): void
    {
        self::assertSame([], $this->loader->load('-', '{}'));
    }

    public function testStringKeyMustBeString(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be a string');
        $this->loader->load('-', '{"name":123}');
    }

    public function testRegisterMustBeAnArray(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be an array');
        $this->loader->load('-', '{"register":"x"}');
    }

    public function testRegisterEntriesMustBeStrings(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be a string');
        $this->loader->load('-', '{"register":[1]}');
    }

    public function testOptionsMustBeAnObject(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be an object');
        $this->loader->load('-', '{"options":[1]}');
    }

    public function testOptionValueMustBeScalar(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be a scalar');
        $this->loader->load('-', '{"options":{"pretty":[]}}');
    }

    public function testInputsMustBeAList(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be an array of input nodes');
        $this->loader->load('-', '{"inputs":{"a":1}}');
    }

    public function testIssuesMustBeAList(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be an array of issues');
        $this->loader->load('-', '{"inputs":[{"issues":{"a":1}}]}');
    }

    public function testNodeMustBeAnObject(): void
    {
        $this->expectException(RecipeException::class);
        $this->expectExceptionMessage('must be an object');
        $this->loader->load('-', '{"inputs":[1]}');
    }
}
