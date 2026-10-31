<?php

declare(strict_types=1);

namespace SyncForge\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use SyncForge\SyncForge;
use SyncForge\SyncForgeBundle;

final class SyncForgeExtensionTest extends TestCase
{
    public function testBundleLoadsServiceDefinitions(): void
    {
        $container = new ContainerBuilder();
        $extension = (new SyncForgeBundle())->getContainerExtension();

        $extension->load([], $container);

        self::assertTrue($container->hasDefinition(SyncForge::class));
    }
}
