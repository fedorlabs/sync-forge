<?php

declare(strict_types=1);

namespace SyncForge\Fluent;

use Closure;
use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Pipeline\SyncPipelineInterface;
use SyncForge\Pipeline\TouchRule;
use SyncForge\Report\ChunkResult;
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
    /** @var array<string,scalar|null> */
    private array $scope = [];
    private bool $returnInserted = false;
    private bool $returnUpdated = false;
    private bool $returnDeleted = false;
    /** @var list<string>|null */
    private ?array $updateFields = null;
    private bool $insertMissing = true;
    private bool $updateExisting = true;
    /** @var array<string,mixed> */
    private array $markMissing = [];
    private ?Closure $onChunk = null;
    /** @var array<string,mixed> */
    private array $insertDefaults = [];
    /** @var list<TouchRule> */
    private array $touchRules = [];

    public function __construct(
        private readonly SyncPipelineInterface $pipeline,
        private readonly string $entityClass,
    ) {
    }

    /**
     * @param array<int, string> $fields
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

    /**
     * Collects the key and identifier fields of inserted rows, as stored in the database,
     * into SyncResult::$insertedKeys. The list is held in memory for the whole run.
     */
    public function returnInserted(bool $enabled = true): self
    {
        $this->returnInserted = $enabled;

        return $this;
    }

    /**
     * Compares and updates only these fields, for example a price feed that must not touch the rest of the row.
     * Inserted rows are still written with every field the source carries.
     *
     * @param array<array-key,mixed> $fields
     */
    public function updateFields(array $fields): self
    {
        $validated = [];
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                throw new InvalidConfigurationException('updateFields() must be a list of non-empty field names.');
            }
            $validated[] = $field;
        }

        if ($validated === []) {
            throw new InvalidConfigurationException('updateFields() cannot be empty.');
        }

        $this->updateFields = $validated;

        return $this;
    }

    /**
     * With false, rows missing from the database are not inserted (counted as skipped).
     */
    public function insertMissing(bool $enabled = true): self
    {
        $this->insertMissing = $enabled;

        return $this;
    }

    /**
     * With false, existing rows are not updated (counted as skipped), so the sync only adds new rows.
     */
    public function updateExisting(bool $enabled = true): self
    {
        $this->updateExisting = $enabled;

        return $this;
    }

    /**
     * Instead of deleting rows that are missing from the source, writes these values to them
     * (for example ['isActive' => false]); rows that already have the values are left alone.
     * Works like deleteMissing() with the same scope and safety guards; the two cannot be combined.
     *
     * @param array<array-key,mixed> $values field => value
     */
    public function markMissing(array $values): self
    {
        $validated = [];
        foreach ($values as $field => $value) {
            if (!is_string($field) || $field === '') {
                throw new InvalidConfigurationException('markMissing() must map non-empty field names to values.');
            }
            $validated[$field] = $value;
        }

        if ($validated === []) {
            throw new InvalidConfigurationException('markMissing() cannot be empty.');
        }

        $this->markMissing = $validated;

        return $this;
    }

    /**
     * Calls $callback after each chunk, successful or failed, for progress output.
     *
     * @param callable(ChunkResult): void $callback
     */
    public function onChunk(callable $callback): self
    {
        $this->onChunk = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Collects the key and identifier fields of updated rows into SyncResult::$updatedKeys.
     */
    public function returnUpdated(bool $enabled = true): self
    {
        $this->returnUpdated = $enabled;

        return $this;
    }

    /**
     * Collects the key fields of rows removed by deleteMissing into SyncResult::$deletedKeys.
     */
    public function returnDeleted(bool $enabled = true): self
    {
        $this->returnDeleted = $enabled;

        return $this;
    }

    /**
     * Sets fields on inserted rows that do not carry them, for values the database does not default
     * and the entity constructor used to set (for example created_at). A Closure is evaluated once
     * per run, so all rows get the same value. Values from the source row always win.
     *
     * @param array<array-key,mixed> $values field => value
     */
    public function setOnInsert(array $values): self
    {
        foreach ($values as $field => $value) {
            if (!is_string($field) || $field === '') {
                throw new InvalidConfigurationException('setOnInsert() must map non-empty field names to values.');
            }

            $this->insertDefaults[$field] = $value;
        }

        return $this;
    }

    /**
     * Sets $field on an updated row when a watched field changed in the same update, like an entity
     * setter that stamps a modification time. Watch a list of fields, or '*' for any change. The value
     * defaults to the start of the run; a Closure is evaluated once per run. A value from the source
     * row for $field wins. Call it once per field to touch.
     *
     * @param array<array-key,mixed>|string $watched
     */
    public function touchOnChange(string $field, array|string $watched = '*', mixed $value = null): self
    {
        if ($field === '') {
            throw new InvalidConfigurationException('touchOnChange() field cannot be empty.');
        }

        $fields = null;
        if ($watched !== '*') {
            $fields = [];
            foreach (is_array($watched) ? $watched : [] as $watchedField) {
                if (!is_string($watchedField) || $watchedField === '') {
                    throw new InvalidConfigurationException('touchOnChange() watched fields must be non-empty strings.');
                }
                $fields[] = $watchedField;
            }

            if ($fields === []) {
                throw new InvalidConfigurationException("touchOnChange() watched must be '*' or a non-empty list of fields.");
            }
        }

        $this->touchRules[] = new TouchRule($field, $fields, $value);

        return $this;
    }

    /**
     * Limits the sync to rows matching these field values: existing rows are looked up and deleted only within
     * the scope, and the values are filled into inserted rows that do not carry them.
     *
     * @param array<array-key,mixed> $scope
     */
    public function scope(array $scope): self
    {
        $validated = [];

        foreach ($scope as $field => $value) {
            if (!is_string($field) || $field === '') {
                throw new InvalidConfigurationException('Scope must map non-empty field names to values.');
            }

            if ($value !== null && !is_scalar($value)) {
                throw new InvalidConfigurationException(sprintf('Scope value for "%s" must be scalar or null.', $field));
            }

            $validated[$field] = $value;
        }

        $this->scope = $validated;

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

        if ($this->deleteMissing && $this->markMissing !== []) {
            throw new InvalidConfigurationException('deleteMissing() and markMissing() cannot be combined.');
        }

        return $this->pipeline->run(new SyncContext(
            entityClass: $this->entityClass,
            keyFields: $this->keyFields,
            source: $this->source,
            chunkSize: $this->chunkSize,
            deleteMissing: $this->deleteMissing,
            dryRun: $this->dryRun,
            continueOnError: $this->continueOnError,
            scope: $this->scope,
            returnInserted: $this->returnInserted,
            insertDefaults: $this->insertDefaults,
            touchRules: $this->touchRules,
            returnUpdated: $this->returnUpdated,
            returnDeleted: $this->returnDeleted,
            updateFields: $this->updateFields,
            insertMissing: $this->insertMissing,
            updateExisting: $this->updateExisting,
            markMissing: $this->markMissing,
            onChunk: $this->onChunk,
        ));
    }
}
