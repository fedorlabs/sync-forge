<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use SyncForge\Bridge\DoctrineDbal\DbalExistingRowsProvider;
use SyncForge\Bridge\DoctrineDbal\DbalFallbackBatchExecutor;
use SyncForge\Bridge\DoctrineDbal\DbalMySqlBulkExecutor;
use SyncForge\Bridge\DoctrineDbal\DbalPlatformDetector;
use SyncForge\Bridge\DoctrineDbal\DbalPostgresBulkExecutor;
use SyncForge\Bridge\DoctrineOrm\DoctrineOrmEntityMetadataProvider;
use SyncForge\Diff\ScalarDiffEngine;
use SyncForge\Executor\BulkExecutorFactory;
use SyncForge\Key\CompositeKeyResolver;
use SyncForge\SyncForge;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(CompositeKeyResolver::class);
    $services->set(ScalarDiffEngine::class);

    $services->set(DoctrineOrmEntityMetadataProvider::class)
        ->arg('$entityManager', service('doctrine.orm.entity_manager'));

    $services->set(DbalPlatformDetector::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'));

    $services->set(DbalExistingRowsProvider::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'))
        ->arg('$keyResolver', service(CompositeKeyResolver::class));

    $services->set(DbalPostgresBulkExecutor::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'));

    $services->set(DbalMySqlBulkExecutor::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'));

    $services->set(DbalFallbackBatchExecutor::class)
        ->arg('$connection', service('doctrine.dbal.default_connection'));

    $services->set(BulkExecutorFactory::class)
        ->arg('$executors', [
            service(DbalPostgresBulkExecutor::class),
            service(DbalMySqlBulkExecutor::class),
            service(DbalFallbackBatchExecutor::class),
        ]);

    $services->set(SyncForge::class)
        ->public()
        ->arg('$metadataProvider', service(DoctrineOrmEntityMetadataProvider::class))
        ->arg('$keyResolver', service(CompositeKeyResolver::class))
        ->arg('$diffEngine', service(ScalarDiffEngine::class))
        ->arg('$executorFactory', service(BulkExecutorFactory::class))
        ->arg('$platformDetector', service(DbalPlatformDetector::class))
        ->arg('$existingRowsProvider', service(DbalExistingRowsProvider::class));
};
