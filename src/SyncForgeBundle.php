<?php

declare(strict_types=1);

namespace SyncForge;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use SyncForge\DependencyInjection\SyncForgeExtension;

final class SyncForgeBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SyncForgeExtension();
    }
}
