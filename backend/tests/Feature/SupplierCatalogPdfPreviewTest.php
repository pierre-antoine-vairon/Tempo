<?php

namespace Tests\Feature;

use App\Services\Purchasing\CatalogParsingProfileRegistry;
use App\Services\Purchasing\PdfTextExtractor;
use App\Services\Purchasing\SupplierCatalogParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

class SupplierCatalogPdfPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('y_orgs', function ($table) {
            $table->unsignedBigInteger('id')->primary();
        });
        Schema::create('y_suppliers', function ($table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('org_id');
            $table->string('name');
        });
        Schema::create('y_supplier_catalogs', function ($table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('name');
            $table->date('effective_from');
            $table->string('source_filename')->nullable();
        });
        Schema::create('y_supplier_categories', function ($table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('source_path');
        });
        Schema::create('y_supplier_items', function ($table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('supplier_sku');
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('purchase_unit_label')->nullable();
            $table->string('pack_label')->nullable();
        });
        Schema::create('y_supplier_item_prices', function ($table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('supplier_item_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->decimal('price_excl_tax', 14, 6);
            $table->string('currency_code', 3);
            $table->date('effective_from');
        });

        DB::table('y_orgs')->insert(['id' => 1]);
        DB::table('y_suppliers')->insert([
            'id' => 10,
            'org_id' => 1,
            'name' => 'Fournisseur test',
        ]);
    }

    public function test_it_rejects_an_unknown_profile(): void
    {
        $response = $this->previewPdf(['profile_id' => 'unknown-format']);

        $response
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'error' => 'catalog_parsing_profile_not_found',
            ]);
    }

    public function test_it_returns_not_found_for_an_unknown_organization_or_supplier(): void
    {
        $this->post('/api/purchasing/suppliers/10/catalog-imports/preview-pdf?org_id=99', [
            'file' => UploadedFile::fake()->create('catalog.pdf', 1, 'application/pdf'),
            'catalog_name' => 'Catalogue test',
        ])->assertStatus(404)->assertJson(['error' => 'organization_not_found']);

        $this->post('/api/purchasing/suppliers/99/catalog-imports/preview-pdf?org_id=1', [
            'file' => UploadedFile::fake()->create('catalog.pdf', 1, 'application/pdf'),
            'catalog_name' => 'Catalogue test',
        ])->assertStatus(404)->assertJson(['error' => 'supplier_not_found']);
    }

    public function test_it_previews_parser_output_without_creating_business_records(): void
    {
        $this->mock(CatalogParsingProfileRegistry::class, function (MockInterface $mock) {
            $mock->shouldReceive('resolve')->once()->with('test-format')->andReturn([]);
        });
        $this->mock(PdfTextExtractor::class, function (MockInterface $mock) {
            $mock->shouldReceive('extract')->once()->andReturn([['page' => 1, 'text' => 'Catalogue test']]);
        });
        $this->mock(SupplierCatalogParser::class, function (MockInterface $mock) {
            $mock->shouldReceive('parse')->once()->andReturn($this->parsedDocument());
        });

        $response = $this->previewPdf(['profile_id' => 'test-format']);

        $response
            ->assertOk()
            ->assertJsonPath('catalog.name', 'Catalogue métier')
            ->assertJsonPath('catalog.operation', 'create_catalog')
            ->assertJsonPath('categories.0.operation', 'create_category')
            ->assertJsonPath('items.0.operation', 'create_item')
            ->assertJsonPath('items.0.price_operation', 'create_price')
            ->assertJsonPath('source_document.catalog_label', 'Libellé PDF')
            ->assertJsonPath('parser_summary.items_parsed', 1)
            ->assertJsonPath('parser_warnings.0.code', 'category_ambiguous')
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('unparsed_rows.0.code', 'unparsed_text_block');

        $this->assertSame(0, DB::table('y_supplier_catalogs')->count());
        $this->assertSame(0, DB::table('y_supplier_categories')->count());
        $this->assertSame(0, DB::table('y_supplier_items')->count());
        $this->assertSame(0, DB::table('y_supplier_item_prices')->count());
    }

    private function previewPdf(array $input)
    {
        return $this->post('/api/purchasing/suppliers/10/catalog-imports/preview-pdf?org_id=1', [
            'file' => UploadedFile::fake()->create('catalog.pdf', 1, 'application/pdf'),
            'catalog_name' => 'Catalogue métier',
            ...$input,
        ]);
    }

    private function parsedDocument(): array
    {
        return [
            'document' => [
                'supplier_label' => 'Fournisseur PDF',
                'catalog_label' => 'Libellé PDF',
                'effective_from' => '2026-01-28',
                'source_filename' => 'catalog.pdf',
            ],
            'categories' => [[
                'client_key' => 'category-epicerie',
                'name' => 'Épicerie',
                'source_path' => 'Épicerie',
                'parent_source_path' => null,
            ]],
            'items' => [[
                'client_key' => 'sku-123456',
                'source' => ['row' => null, 'page' => 1, 'sheet' => null, 'raw_label' => 'Article test'],
                'category_source_path' => 'Épicerie',
                'supplier_sku' => '123456',
                'name' => 'Article test',
                'brand' => null,
                'purchase_unit_label' => 'Carton',
                'pack_label' => 'Carton 6 pcs',
                'price_excl_tax' => '12.3400',
                'currency_code' => 'EUR',
            ]],
            'parser_summary' => ['items_parsed' => 1],
            'parser_warnings' => [['code' => 'category_ambiguous']],
            'unparsed_rows' => [['code' => 'unparsed_text_block']],
        ];
    }
}
