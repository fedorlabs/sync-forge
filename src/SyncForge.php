<?php

declare(strict_types=1);

namespace SyncForge;

use SyncForge\Diff\DiffEngineInterface;
use SyncForge\Diff\ScalarDiffEngine;
use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Executor\BulkExecutorFactory;
use SyncForge\Executor\PlatformDetectorInterface;
use SyncForge\Fluent\SyncBuilder;
use SyncForge\Key\CompositeKeyResolver;
use SyncForge\Key\KeyResolverInterface;
use SyncForge\Metadata\EntityMetadataProviderInterface;
use SyncForge\Pipeline\ExistingRowsProviderInterface;
use SyncForge\Pipeline\SyncPipeline;
use SyncForge\Pipeline\SyncPipelineInterface;

final class SyncForge
{
    public function __construct(
        EntityMetadataProviderInterface $metadataProvider,
        ?KeyResolverInterface $keyResolver = null,
        ?DiffEngineInterface $diffEngine = null,
        ?BulkExecutorFactory $executorFactory = null,
        ?PlatformDetectorInterface $platformDetector = null,
        ?ExistingRowsProviderInterface $existingRowsProvider = null,
        ?SyncPipelineInterface $pipeline = null,
    ) {
        $keyResolver ??= new CompositeKeyResolver();
        $diffEngine ??= new ScalarDiffEngine();

        if ($pipeline !== null) {
            $this->pipeline = $pipeline;
            return;
        }

        if ($executorFactory === null || $platformDetector === null || $existingRowsProvider === null) {
            throw new InvalidConfigurationException(
                'SyncForge requires executorFactory, platformDetector, and existingRowsProvider ' .
                'for direct construction, or a fully configured custom pipeline.',
            );
        }

        $this->pipeline = new SyncPipeline(
            $metadataProvider,
            $keyResolver,
            $diffEngine,
            $executorFactory,
            $platformDetector,
            $existingRowsProvider,
        );
    }

    private readonly SyncPipelineInterface $pipeline;

    public function for(string $entityClass): SyncBuilder
    {
        return new SyncBuilder($this->pipeline, $entityClass);
    }
}
