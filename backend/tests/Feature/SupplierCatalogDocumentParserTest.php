<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SupplierCatalogDocumentParserTest extends TestCase
{
    public function test_it_rejects_an_unknown_profile_before_pdf_extraction(): void
    {
        $response = $this->post('/api/purchasing/catalog-imports/parse', [
            'file' => UploadedFile::fake()->create('catalog.pdf', 1, 'application/pdf'),
            'profile_id' => 'unknown-format',
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'error' => 'catalog_parsing_profile_not_found',
            ]);
    }
}
