<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Kernel;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Kernel\DeprecatedTagsPass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DeprecatedTagsPassTest extends TestCase
{
    #[IgnoreDeprecations]
    public function testAServiceWithAnOldTagGetsTheNewOneAndADeprecation(): void
    {
        $container = new ContainerBuilder();
        $container->register('acme.vehicles_tool', \stdClass::class)->addTag('app.mcp_read_tool', ['priority' => 5]);

        $this->expectUserDeprecationMessage('Since foresi/shared 0.11: The service "acme.vehicles_tool" is tagged "app.mcp_read_tool", which is deprecated since module API 1.0 and removed in 2.0. Tag it "foresi.shared.mcp_read_tool" instead, or let its interface tag it by autoconfiguration.');

        new DeprecatedTagsPass()->process($container);

        self::assertSame(['acme.vehicles_tool' => [['priority' => 5]]], $container->findTaggedServiceIds(ReadTool::TAG));
    }

    #[IgnoreDeprecations]
    public function testAServiceThatHasTheNewTagAlreadyIsNotTaggedTwice(): void
    {
        $container = new ContainerBuilder();
        $container->register('acme.source', \stdClass::class)->addTag(ReadTool::TAG)->addTag('app.mcp_read_tool');

        new DeprecatedTagsPass()->process($container);

        self::assertSame(['acme.source' => [[]]], $container->findTaggedServiceIds(ReadTool::TAG));
    }

    public function testAServiceWithTheNewTagReportsNothing(): void
    {
        $container = new ContainerBuilder();
        $container->register('acme.source', \stdClass::class)->addTag(ReadTool::TAG);

        new DeprecatedTagsPass()->process($container);

        self::assertSame(['acme.source' => [[]]], $container->findTaggedServiceIds(ReadTool::TAG));
    }

    public function testEveryOldNameMapsToTheTagOfAContractInterface(): void
    {
        $contractTags = [];
        foreach ($this->contractClasses() as $class) {
            foreach (new \ReflectionClass($class)->getReflectionConstants() as $constant) {
                if (str_ends_with($constant->getName(), 'TAG') && \is_string($value = $constant->getValue())) {
                    $contractTags[] = $value;
                }
            }
        }

        foreach (DeprecatedTagsPass::RENAMED as $old => $new) {
            self::assertStringStartsWith('app.', $old);
            self::assertMatchesRegularExpression('/^foresi\.[a-z_]+\.[a-z_]+$/', $new);
            // A tag of a module that is not installed has no interface here to look for.
            if (!is_dir($this->moduleDirectory($new))) {
                continue;
            }
            self::assertContains($new, $contractTags, \sprintf('%s is no TAG of an interface in a Contract namespace.', $new));
        }
    }

    /**
     * The directory under src/ of the module a tag foresi.<module>.<name> belongs to.
     */
    private function moduleDirectory(string $tag): string
    {
        $module = explode('.', $tag)[1];

        return \dirname(__DIR__, 4).'/src/'.str_replace('_', '', ucwords($module, '_'));
    }

    /**
     * @return list<class-string>
     */
    private function contractClasses(): array
    {
        $root = \dirname(__DIR__, 4).'/src';
        $classes = [];
        $files = glob($root.'/*/Contract/{,*/,*/*/}*.php', \GLOB_BRACE);
        foreach (false === $files ? [] : $files as $file) {
            $class = 'Foresi\\'.str_replace('/', '\\', substr($file, \strlen($root) + 1, -4));
            if (interface_exists($class) || class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
