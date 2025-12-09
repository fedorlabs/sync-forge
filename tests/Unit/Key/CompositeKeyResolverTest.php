<?php

declare(strict_types=1);

namespace SyncForge\Tests\Unit\Key;

use JsonException;
use PHPUnit\Framework\TestCase;
use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Key\CompositeKeyResolver;

final class CompositeKeyResolverTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testMakeKeyForSingleField(): void
    {
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(['external_id' => 'SKU-1'], ['external_id']);

        self::assertSame('11:external_id=7:s:SKU-1', $key);
    }

    /**
     * @throws JsonException
     */
    public function testMakeKeyForCompositeFields(): void
    {
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(
            ['sku' => 'A-1', 'warehouse' => 'EU'],
            ['sku', 'warehouse'],
        );

        self::assertSame('3:sku=5:s:A-1|9:warehouse=4:s:EU', $key);
    }

    /**
     * @throws JsonException
     */
    public function testIndexByKeyCountsDuplicatesAndLastWins(): void
    {
        $resolver = new CompositeKeyResolver();

        $index = $resolver->indexByKey([
            ['external_id' => 'A-1', 'name' => 'first'],
            ['external_id' => 'A-1', 'name' => 'second'],
            ['external_id' => 'B-1', 'name' => 'third'],
        ], ['external_id']);

        self::assertSame(1, $index->duplicateCount);
        self::assertCount(2, $index->rowsByKey);

        $key = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);
        self::assertSame('second', $index->rowsByKey[$key]['name']);
    }

    /**
     * @throws JsonException
     */
    public function testMissingKeyFieldThrows(): void
    {
        $resolver = new CompositeKeyResolver();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Missing key field "external_id"');

        $resolver->makeKey(['sku' => 'A-1'], ['external_id']);
    }

    /**
     * @throws JsonException
     */
    public function testEmptyKeyFieldsThrows(): void
    {
        $resolver = new CompositeKeyResolver();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Key fields cannot be empty');

        $resolver->makeKey(['external_id' => 'A-1'], []);
    }

    public function testTypedEncodingAvoidsBoolStringCollisions(): void
    {
        $resolver = new CompositeKeyResolver();

        $boolKey = $resolver->makeKey(['flag' => true], ['flag']);
        $stringKey = $resolver->makeKey(['flag' => 'true'], ['flag']);

        self::assertNotSame($boolKey, $stringKey);
    }
}
