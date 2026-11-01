<?php

declare(strict_types=1);

namespace SyncForge\Tests\Support;

enum StockStatus: string
{
    case InStock = 'in_stock';
    case SoldOut = 'sold_out';
}
