<?php

namespace Tests\Unit;

use App\Services\Purchasing\CatalogParsingProfileRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CatalogParsingProfileRegistryTest extends TestCase
{
    public function test_it_resolves_a_known_format_profile(): void
    {
        $profile = (new CatalogParsingProfileRegistry)->resolve('supplier-table-pdf-v1');

        $this->assertSame('supplier-table-pdf-v1', $profile['id']);
        $this->assertSame('\\d{5,}', $profile['article']['sku_pattern']);
        $this->assertArrayNotHasKey('categories', $profile);
    }

    public function test_it_rejects_an_unknown_format_profile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CatalogParsingProfileRegistry)->resolve('unknown-format');
    }
}
