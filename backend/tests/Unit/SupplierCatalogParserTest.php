<?php

namespace Tests\Unit;

use App\Services\Purchasing\CatalogParsingProfileRegistry;
use App\Services\Purchasing\SupplierCatalogParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SupplierCatalogParserTest extends TestCase
{
    private const FIRST_DOCUMENT_PROFILE = [
        'id' => 'first-document-format',
        'article' => [
            'sku_pattern' => '\\d{5,}',
            'price_pattern' => '\\d{1,8},\\d{1,6}',
            'trailing_pattern' => 'new\\s+Non\\s+-\\s+-\\s+0,00',
        ],
        'categories' => [
            'simple_patterns' => ['FOURRAGES', 'EPICERIE', 'Huiles'],
        ],
    ];

    public function test_it_parses_document_labels_and_an_item_with_brand_and_packaging(): void
    {
        $result = $this->parse("FOURRAGES\nLC CREME BUENO 6 KG-SEAU6K\n122166 CLUB CAFE Seau 6kg 31,7880 new Non - - 0,00");

        $this->assertSame('VANDENBULCKE', $result['document']['supplier_label']);
        $this->assertSame('CASQUETTES', $result['document']['catalog_label']);
        $this->assertSame('2026-01-28', $result['document']['effective_from']);
        $this->assertSame('sku-122166', $result['items'][0]['client_key']);
        $this->assertSame('CLUB CAFE', $result['items'][0]['brand']);
        $this->assertSame('Seau', $result['items'][0]['purchase_unit_label']);
        $this->assertSame('Seau 6kg', $result['items'][0]['pack_label']);
        $this->assertSame('31.7880', $result['items'][0]['price_excl_tax']);
        $this->assertSame('EUR', $result['items'][0]['currency_code']);
    }

    public function test_it_parses_an_item_without_brand(): void
    {
        $result = $this->parse("EPICERIE\nPRALIGRAIN 5K(NOIS/SUC)\"RANS\"\n120817 colis 5kg 60,4000 new Non - - 0,00");

        $this->assertSame('EPICERIE', $result['items'][0]['category_source_path']);
        $this->assertSame('120817', $result['items'][0]['supplier_sku']);
        $this->assertNull($result['items'][0]['brand']);
        $this->assertSame('colis', $result['items'][0]['purchase_unit_label']);
    }

    public function test_it_parses_inline_names_as_separate_articles(): void
    {
        $result = $this->parse("LC CREME BUENO 6 KG-SEAU6K 122166 CLUB CAFE Seau 6kg 31,7880 new Non - - 0,00\nLC CREME CHOCOLAT NOISETTE 6 KG-SEAU6K 122167 CLUB CAFE Seau 6kg 36,6180 new Non - - 0,00");

        $this->assertSame(['122166', '122167'], array_column($result['items'], 'supplier_sku'));
        $this->assertSame('LC CREME BUENO 6 KG-SEAU6K', $result['items'][0]['name']);
        $this->assertSame('LC CREME CHOCOLAT NOISETTE 6 KG-SEAU6K', $result['items'][1]['name']);
    }

    public function test_it_keeps_consecutive_inline_and_previous_line_articles_separate(): void
    {
        $result = $this->parse("PRALIGRAIN 5K(NOIS/SUC)\"RANS\" 120817 colis 5kg 60,4000 new Non - - 0,00\nSEL DOSETTE 0.8GR X2000\n125100 8,0000 new Non - - 0,00");

        $this->assertSame(['120817', '125100'], array_column($result['items'], 'supplier_sku'));
        $this->assertSame('PRALIGRAIN 5K(NOIS/SUC)"RANS"', $result['items'][0]['name']);
        $this->assertSame('SEL DOSETTE 0.8GR X2000', $result['items'][1]['name']);
        $this->assertStringNotContainsString('120817', $result['items'][1]['name']);
    }

    public function test_it_ignores_structural_page_headers_before_inline_articles(): void
    {
        $profile = self::FIRST_DOCUMENT_PROFILE + [
            'ignored_line_patterns' => [
                'Page\\s+\\d+\\s+de\\s+\\d+',
                'Nom\\s+Réf\\s+Marque\\s+Unité\\s+Prix\\s+Var\\.\\s+Prix\\s+%\\s+€\\s+Qte\\s+Max',
                'Prix',
                'Libre',
            ],
        ];
        $result = (new SupplierCatalogParser)->parse([
            [
                'page' => 2,
                'text' => "Page 2 de 9\nNom Réf Marque Unité Prix Var. Prix % € Qte Max\nPrix\nLibre\nLC THE FRUIT ROUGE 122433 CLUB CAFE Colis 1kg 9,3340 new Non - - 0,00",
            ],
        ], 'headers.pdf', $profile);

        $this->assertSame('LC THE FRUIT ROUGE', $result['items'][0]['name']);
    }

    public function test_it_creates_hierarchical_categories_with_their_parent(): void
    {
        $result = $this->parse('ACCESSOIRES CLUB CAFE\\Textiles');

        $this->assertSame(['ACCESSOIRES CLUB CAFE', 'ACCESSOIRES CLUB CAFE\\Textiles'], array_column($result['categories'], 'source_path'));
        $this->assertSame('ACCESSOIRES CLUB CAFE', $result['categories'][1]['parent_source_path']);
    }

    public function test_it_recognizes_a_normal_case_category_only_when_the_profile_confirms_it(): void
    {
        $result = $this->parse("Huiles\nHuile de tournesol\n123456 Carton 6 pcs 12,3400 new Non - - 0,00");

        $this->assertSame('Huiles', $result['items'][0]['category_source_path']);
        $this->assertSame('Huile de tournesol', $result['items'][0]['name']);
    }

    public function test_it_keeps_an_ambiguous_category_out_of_the_item_name(): void
    {
        $profile = self::FIRST_DOCUMENT_PROFILE;
        $profile['categories']['simple_patterns'] = ['FOURRAGES', 'EPICERIE'];

        $result = (new SupplierCatalogParser)->parse([
            [
                'page' => 1,
                'text' => "Huiles\nHuile tournesol\n123456 Carton 6 pcs 12,3400 new Non - - 0,00",
            ],
        ], 'ambiguous-category.pdf', $profile);

        $this->assertNull($result['items'][0]['category_source_path']);
        $this->assertSame('Huile tournesol', $result['items'][0]['name']);
        $warningIndex = array_search(
            'category_ambiguous',
            array_column($result['parser_warnings'], 'code'),
            true
        );

        $this->assertNotFalse($warningIndex);
        $this->assertSame('Huiles', $result['parser_warnings'][$warningIndex]['source']['raw_label']);
    }

    public function test_it_keeps_an_over_precise_price_out_of_importable_items(): void
    {
        $result = $this->parse("FOURRAGES\nLC CAFE DECAFEINE PASTILLE 7G CT-CT150P\n122423 CLUB CAFE Colis 150 pcs26,78295 new Non - - 0,00");

        $this->assertSame([], $result['items']);
        $this->assertSame('price_precision_not_supported', $result['unparsed_rows'][0]['code']);
        $this->assertSame(1, $result['parser_summary']['items_detected']);
    }

    public function test_it_does_not_merge_articles_around_an_over_precise_price(): void
    {
        $result = $this->parse("ARTICLE A 122430 Colis 1kg 9,3340 new Non - - 0,00\nARTICLE B 122431 Colis 1kg 26,78295 new Non - - 0,00\nARTICLE C 122432 Colis 1kg 9,5900 new Non - - 0,00");

        $this->assertSame(['122430', '122432'], array_column($result['items'], 'supplier_sku'));
        $this->assertSame('price_precision_not_supported', $result['unparsed_rows'][0]['code']);
    }

    public function test_an_ambiguous_category_clears_the_previous_category_context(): void
    {
        $result = $this->parseWithRegistryProfile("Pâtisseries\\MIX\nMIX CRUMBLE 121502 Sac 3,5kg 19,2200 new Non - - 0,00\nFOURRAGES\nLC CREME BUENO 6 KG-SEAU6K 122166 CLUB CAFE Seau 6kg 31,7880 new Non - - 0,00\nLC CREME CHOCOLAT NOISETTE 6 KG-SEAU6K 122167 CLUB CAFE Seau 6kg 36,6180 new Non - - 0,00");

        $this->assertSame('Pâtisseries\\MIX', $result['items'][0]['category_source_path']);
        $this->assertNull($result['items'][1]['category_source_path']);
        $this->assertNull($result['items'][2]['category_source_path']);
        $this->assertSame(['122166', '122167'], array_column(array_slice($result['items'], 1), 'supplier_sku'));
    }

    public function test_an_unimportable_article_does_not_contaminate_following_articles_or_category_context(): void
    {
        $result = $this->parseWithRegistryProfile("Pâtisseries\\MIX\nMIX CRUMBLE DAWN 121503 Sac 3,5kg 19,220002 new Non - - 0,00\nFOURRAGES\nLC CONCENTRE D'ORANGE KG-SEAU3.5K 122128 Seau 3,5kg 26,8275 new Non - - 0,00\nLC CREME CARAMEL B/S KG-SEAU3.5K\n122107 CLUB CAFE Seau 3,5kg 24,1640 new Non - - 0,00");

        $this->assertSame(['122128', '122107'], array_column($result['items'], 'supplier_sku'));
        $this->assertSame('LC CONCENTRE D\'ORANGE KG-SEAU3.5K', $result['items'][0]['name']);
        $this->assertSame('LC CREME CARAMEL B/S KG-SEAU3.5K', $result['items'][1]['name']);
        $this->assertNull($result['items'][0]['category_source_path']);
        $this->assertNull($result['items'][1]['category_source_path']);
        $this->assertContains(
            'price_precision_not_supported',
            array_column($result['unparsed_rows'], 'code')
        );
    }

    public function test_it_keeps_ambiguous_text_blocks_and_generates_stable_client_keys(): void
    {
        $text = "FOURRAGES\nLC CREME BUENO 6 KG-SEAU6K\n122166 CLUB CAFE Seau 6kg 31,7880 new Non - - 0,00\nFragment isolé";

        $first = $this->parse($text);
        $second = $this->parse($text);

        $this->assertSame($first['items'][0]['client_key'], $second['items'][0]['client_key']);
        $this->assertSame('unparsed_text_block', $first['unparsed_rows'][0]['code']);
        $this->assertSame(0, $first['parser_summary']['items_unparsed']);
        $this->assertSame(1, $first['parser_summary']['unparsed_rows_count']);
    }

    public function test_it_parses_a_second_format_with_an_alphanumeric_sku_and_no_trailing_token(): void
    {
        $result = (new SupplierCatalogParser)->parse([
            [
                'page' => 1,
                'text' => "Supplier: Example\nCatalog: Spring effective from 15/04/2026\n€\nCATÉGORIE\nProduit exemple\nABC123 Carton 6 pcs 12,3400",
            ],
        ], 'example.pdf', [
            'id' => 'example-format',
            'article' => [
                'sku_pattern' => '[A-Z]{3}\\d{3}',
                'price_pattern' => '\\d{1,8},\\d{1,4}',
                'trailing_pattern' => null,
            ],
            'categories' => [
                'simple_patterns' => ['CATÉGORIE'],
            ],
        ]);

        $this->assertSame('ABC123', $result['items'][0]['supplier_sku']);
        $this->assertSame('CATÉGORIE', $result['items'][0]['category_source_path']);
        $this->assertSame('12.3400', $result['items'][0]['price_excl_tax']);
        $this->assertSame('example-format', $result['parser_summary']['profile_id']);
    }

    public function test_it_rejects_a_structurally_invalid_profile(): void
    {
        $this->expectException(RuntimeException::class);

        (new SupplierCatalogParser)->parse([], 'invalid.pdf', ['article' => 'invalid']);
    }

    public function test_it_rejects_an_invalid_profile_regex(): void
    {
        $this->expectException(RuntimeException::class);

        (new SupplierCatalogParser)->parse([], 'invalid.pdf', [
            'article' => ['sku_pattern' => '[', 'price_pattern' => '\\d+', 'trailing_pattern' => null],
        ]);
    }

    /** @return array<string, mixed> */
    private function parse(string $body): array
    {
        return (new SupplierCatalogParser)->parse([
            [
                'page' => 1,
                'text' => "Fournisseur : VANDENBULCKE\nCatalogue : CASQUETTES à partir du 28/01/2026\nMinimum de commande : 600.0 €\n{$body}",
            ],
        ], 'Catalogue_VANDENBULCKE.pdf', self::FIRST_DOCUMENT_PROFILE);
    }

    /** @return array<string, mixed> */
    private function parseWithRegistryProfile(string $body): array
    {
        return (new SupplierCatalogParser)->parse([
            [
                'page' => 1,
                'text' => "Fournisseur : EXAMPLE\nCatalogue : TEST à partir du 28/01/2026\nMinimum de commande : 600,0 €\n{$body}",
            ],
        ], 'catalog.pdf', (new CatalogParsingProfileRegistry)->resolve('supplier-table-pdf-v1'));
    }
}
