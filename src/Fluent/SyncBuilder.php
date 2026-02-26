<?php

declare(strict_types=1);

namespace SyncForge\Fluent;

use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Pipeline\SyncPipelineInterface;
use SyncForge\Report\SyncResult;
use SyncForge\SyncContext;

final class SyncBuilder
{
    /** @var list<string> */
    private array $keyFields = [];
    /** @var iterable<array<string,mixed>>|null */
    private ?iterable $source = null;
    private int $chunkSize = 1000;
    private bool $deleteMissing = false;
    private bool $dryRun = false;
    private bool $continueOnError = false;

    public function __construct(
        private readonly SyncPipelineInterface $pipeline,
        private readonly string $entityClass,
    ) {
    }

    /**
     * @param list<string> $fields
     */
    public function key(array $fields): self
    {
        if ($fields === []) {
            throw new InvalidConfigurationException('Key fields cannot be empty.');
        }

        foreach ($fields as $field) {
            if ($field === '') {
                throw new InvalidConfigurationException('Key field cannot be empty string.');
            }
        }

        $this->keyFields = array_values($fields);

        return $this;
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     */
    public function source(iterable $rows): self
    {
        $this->source = $rows;

        return $this;
    }

    public function chunkSize(int $size): self
    {
        if ($size < 1) {
            throw new InvalidConfigurationException('Chunk size must be greater than 0.');
        }

        $this->chunkSize = $size;

        return $this;
    }

    public function deleteMissing(bool $enabled = true): self
    {
        $this->deleteMissing = $enabled;

        return $this;
    }

    public function dryRun(bool $enabled = true): self
    {
        $this->dryRun = $enabled;

        return $this;
    }

    public function continueOnError(bool $enabled = true): self
    {
        $this->continueOnError = $enabled;

        return $this;
    }

    public function run(): SyncResult
    {
        if ($this->keyFields === []) {
            throw new InvalidConfigurationException('Sync key must be configured before run().');
        }

        if ($this->source === null) {
            throw new InvalidConfigurationException('Sync source must be configured before run().');
        }

        return $this->pipeline->run(new SyncContext(
            entityClass: $this->entityClass,
            keyFields: $this->keyFields,
            source: $this->source,
            chunkSize: $this->chunkSize,
            deleteMissing: $this->deleteMissing,
            dryRun: $this->dryRun,
            continueOnError: $this->continueOnError,
        ));
    }
}
