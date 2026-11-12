<?php

namespace App\Services\Purchasing;

use InvalidArgumentException;

class CatalogParsingProfileRegistry
{
    /** @return array<string, mixed> */
    public function resolve(?string $profileId): array
    {
        if ($profileId === null || trim($profileId) === '') {
            return [];
        }

        $profiles = [
            'supplier-table-pdf-v1' => [
                'id' => 'supplier-table-pdf-v1',
                'article' => [
                    'sku_pattern' => '\\d{5,}',
                    'price_pattern' => '\\d{1,8},\\d{1,6}',
                    'trailing_pattern' => 'new\\s+Non\\s+-\\s+-\\s+0,00',
                    'allow_inline_name' => true,
                    'allow_previous_line_name' => true,
                ],
                'ignored_line_patterns' => [
                    'Page\\s+\\d+\\s+de\\s+\\d+',
                    'Nom\\s+Réf\\s+Marque\\s+Unité\\s+Prix(?:\\s+Var\\.\\s+Prix\\s+%\\s+€\\s+Qte\\s+Max)?',
                    'Prix',
                    'Libre',
                ],
            ],
        ];

        if (! array_key_exists($profileId, $profiles)) {
            throw new InvalidArgumentException('Catalog parsing profile not found.');
        }

        return $profiles[$profileId];
    }
}
