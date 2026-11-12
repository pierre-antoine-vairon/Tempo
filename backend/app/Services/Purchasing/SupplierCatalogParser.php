<?php

namespace App\Services\Purchasing;

use DateTimeImmutable;
use RuntimeException;

class SupplierCatalogParser
{
    private const DEFAULT_PURCHASE_UNITS = [
        'Seau', 'Sachet', 'Colis', 'Pièce', 'Piece', 'Boîte', 'Boite',
        'Flacon', 'Bidon', 'Carton', 'Paquet', 'Pack',
    ];

    /**
     * @param  array<int, array{page: int, text: string}>  $pages
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function parse(array $pages, string $sourceFilename, array $profile = []): array
    {
        $profile = $this->profile($profile);
        $warnings = [];
        $unparsedRows = [];
        $categories = [];
        $items = [];
        $seenSkus = [];
        $currentCategoryPath = null;
        $itemsDetected = 0;
        $documentText = implode("\n", array_column($pages, 'text'));

        $effectiveFrom = $this->extractEffectiveFrom($documentText, $profile, $warnings);
        $currencyCode = $this->detectCurrency($documentText);
        if ($currencyCode === null) {
            $warnings[] = $this->issue('currency_not_detected', 'La devise n’a pas été identifiée dans le document.');
        }

        foreach ($pages as $page) {
            $pageNumber = (int) ($page['page'] ?? 0);
            $pendingLines = [];
            $lines = preg_split('/\R/u', (string) ($page['text'] ?? '')) ?: [];

            foreach ($lines as $rawLine) {
                $line = trim($rawLine);
                if ($line === '' || $this->isDocumentMetadata($line, $profile) || $this->isCurrencyMarker($line) || $this->isIgnoredStructuralLine($line, $profile)) {
                    continue;
                }

                if ($this->looksLikeCategoryPath($line, $profile)) {
                    $this->addCategoryPath($categories, $line, $profile);
                    $currentCategoryPath = $line;
                    $this->flushUnparsedLines($pendingLines, $pageNumber, $unparsedRows, $warnings);

                    continue;
                }

                $article = $this->articleAnchor($line, $profile);
                if ($article === null) {
                    $pendingLines[] = $line;

                    continue;
                }

                $itemsDetected++;
                $rawLabel = $this->normalizeWhitespace(implode("\n", [...$pendingLines, $line]));
                $inlineName = $article['inline_name'];
                $this->resolvePendingCategory(
                    $pendingLines,
                    $inlineName !== null,
                    $currentCategoryPath,
                    $categories,
                    $profile,
                    $pageNumber,
                    $warnings
                );
                if ($inlineName !== null) {
                    $this->flushUnparsedLines($pendingLines, $pageNumber, $unparsedRows, $warnings);
                    $name = $inlineName;
                } else {
                    $name = $this->normalizeWhitespace(implode(' ', $pendingLines));
                }
                $pendingLines = [];
                if ($name === '') {
                    $this->addUnparsedRow($unparsedRows, $warnings, 'item_name_not_found', 'Le nom de l’article précédant la référence fournisseur est introuvable.', $pageNumber, $rawLabel);

                    continue;
                }
                if ($this->containsCompleteArticleSignature($name, $profile)) {
                    $this->addUnparsedRow($unparsedRows, $warnings, 'item_name_contains_article_signature', 'Le nom de l’article contient une autre signature complète d’article.', $pageNumber, $rawLabel);

                    continue;
                }

                $price = str_replace(',', '.', $article['price']);
                if (! preg_match('/^\d{1,8}\.\d{1,4}$/', $price)) {
                    $this->addUnparsedRow($unparsedRows, $warnings, 'price_precision_not_supported', 'Le prix HT ne peut pas être représenté sans arrondi dans DECIMAL(12,4).', $pageNumber, $rawLabel);

                    continue;
                }

                if (isset($seenSkus[$article['sku']])) {
                    $this->addUnparsedRow($unparsedRows, $warnings, 'duplicate_supplier_sku_in_document', 'La référence fournisseur apparaît plusieurs fois dans le document.', $pageNumber, $rawLabel);

                    continue;
                }

                $seenSkus[$article['sku']] = true;
                $metadata = $this->packageMetadata($article['details'], $profile);
                if ($metadata['warning'] !== null) {
                    $warnings[] = $this->issue($metadata['warning']['code'], $metadata['warning']['message'], $pageNumber, $rawLabel);
                }

                $items[] = [
                    'client_key' => 'sku-'.$article['sku'],
                    'source' => ['row' => null, 'page' => $pageNumber, 'sheet' => null, 'raw_label' => $rawLabel],
                    'category_source_path' => $currentCategoryPath,
                    'supplier_sku' => $article['sku'],
                    'name' => $name,
                    'brand' => $metadata['brand'],
                    'purchase_unit_label' => $metadata['purchase_unit_label'],
                    'pack_label' => $metadata['pack_label'],
                    'price_excl_tax' => $price,
                    'currency_code' => $currencyCode,
                ];
            }

            $this->flushUnparsedLines($pendingLines, $pageNumber, $unparsedRows, $warnings);
        }

        return [
            'document' => [
                'supplier_label' => $this->extractLabel($documentText, $profile['metadata_labels']['supplier']),
                'catalog_label' => $this->extractCatalogLabel($documentText, $profile),
                'effective_from' => $effectiveFrom,
                'source_filename' => $sourceFilename,
            ],
            'categories' => array_values($categories),
            'items' => $items,
            'parser_summary' => [
                'pages_processed' => count($pages),
                'categories_detected' => count($categories),
                'items_detected' => $itemsDetected,
                'items_parsed' => count($items),
                'items_unparsed' => $itemsDetected - count($items),
                'unparsed_rows_count' => count($unparsedRows),
                'warning_count' => count($warnings),
                'profile_id' => $profile['id'],
            ],
            'parser_warnings' => $warnings,
            'unparsed_rows' => $unparsedRows,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function profile(array $input): array
    {
        $profile = array_replace_recursive([
            'id' => null,
            'article' => [
                'sku_pattern' => '[A-Za-z0-9][A-Za-z0-9._\/-]{2,}',
                'price_pattern' => '\d{1,8}[,.]\d{1,6}',
                'trailing_pattern' => null,
                'allow_inline_name' => true,
                'allow_previous_line_name' => true,
            ],
            'categories' => ['path_separator' => '\\', 'path_pattern' => null, 'simple_patterns' => []],
            'purchase_units' => self::DEFAULT_PURCHASE_UNITS,
            'metadata_labels' => [
                'supplier' => ['Fournisseur', 'Supplier'],
                'catalog' => ['Catalogue', 'Catalog'],
                'ignored' => ['Minimum de commande'],
            ],
            'effective_date_patterns' => ['(?:à\s+partir\s+du|effective\s+from)\s+(\d{2}[\/-]\d{2}[\/-]\d{4})'],
            'ignored_line_patterns' => [],
        ], $input);

        $this->validateProfile($profile);

        return $profile;
    }

    /** @param array<string, mixed> $profile */
    private function validateProfile(array $profile): void
    {
        if (! is_array($profile['article'] ?? null)
            || ! $this->nonEmptyString($profile['article']['sku_pattern'] ?? null)
            || ! $this->nonEmptyString($profile['article']['price_pattern'] ?? null)
            || ! ($profile['article']['trailing_pattern'] === null || is_string($profile['article']['trailing_pattern']))
            || ! is_bool($profile['article']['allow_inline_name'] ?? null)
            || ! is_bool($profile['article']['allow_previous_line_name'] ?? null)) {
            throw new RuntimeException('Invalid catalog parsing profile article configuration.');
        }

        if (! is_array($profile['categories'] ?? null)
            || ! $this->nonEmptyString($profile['categories']['path_separator'] ?? null)
            || ! is_array($profile['categories']['simple_patterns'] ?? null)
            || ! is_array($profile['purchase_units'] ?? null)
            || ! is_array($profile['metadata_labels'] ?? null)
            || ! is_array($profile['effective_date_patterns'] ?? null)
            || ! is_array($profile['ignored_line_patterns'] ?? null)) {
            throw new RuntimeException('Invalid catalog parsing profile structure.');
        }

        foreach (['supplier', 'catalog', 'ignored'] as $label) {
            if (! is_array($profile['metadata_labels'][$label] ?? null)) {
                throw new RuntimeException('Invalid catalog parsing profile metadata labels.');
            }
        }

        $patterns = [
            $profile['article']['sku_pattern'],
            $profile['article']['price_pattern'],
            ...$profile['categories']['simple_patterns'],
            ...$profile['effective_date_patterns'],
            ...$profile['ignored_line_patterns'],
        ];
        if ($profile['article']['trailing_pattern'] !== null) {
            $patterns[] = $profile['article']['trailing_pattern'];
        }
        if (is_string($profile['categories']['path_pattern'] ?? null) && $profile['categories']['path_pattern'] !== '') {
            $patterns[] = $profile['categories']['path_pattern'];
        }

        foreach ($patterns as $pattern) {
            if (! $this->nonEmptyString($pattern) || ! $this->isValidRegexFragment($pattern)) {
                throw new RuntimeException('Invalid catalog parsing profile regular expression.');
            }
        }
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isValidRegexFragment(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match('/(?:'.$pattern.')/ui', '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<string, mixed> $profile @param array<int, array<string, mixed>> $warnings */
    private function extractEffectiveFrom(string $text, array $profile, array &$warnings): ?string
    {
        $dates = [];
        foreach ($profile['effective_date_patterns'] as $pattern) {
            preg_match_all('/'.$pattern.'/ui', $text, $matches);
            foreach ($matches[1] ?? [] as $date) {
                $dates[] = $date;
            }
        }
        $dates = array_values(array_unique($dates));
        if (count($dates) !== 1) {
            $warnings[] = $this->issue(count($dates) === 0 ? 'effective_from_not_found' : 'effective_from_ambiguous', count($dates) === 0 ? 'La date d’effet n’a pas été trouvée dans le document.' : 'Plusieurs dates d’effet différentes ont été trouvées dans le document.');

            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d/m/Y', str_replace('-', '/', $dates[0]));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $warnings[] = $this->issue('effective_from_invalid', 'La date d’effet extraite est invalide.');

            return null;
        }

        return $date->format('Y-m-d');
    }

    /** @param array<string, mixed> $profile */
    private function extractCatalogLabel(string $text, array $profile): ?string
    {
        $labels = implode('|', array_map(fn (mixed $label) => preg_quote((string) $label, '/'), $profile['metadata_labels']['catalog']));
        $dates = implode('|', array_map(fn (mixed $pattern) => '(?:'.$pattern.')', $profile['effective_date_patterns']));
        if (! preg_match('/^(?:'.$labels.')\s*:\s*(.+?)(?:\s+(?:'.$dates.')\b|$)/mui', $text, $matches)) {
            return null;
        }

        return $this->normalizeWhitespace($matches[1]);
    }

    /** @param array<int, mixed> $labels */
    private function extractLabel(string $text, array $labels): ?string
    {
        $pattern = implode('|', array_map(fn (mixed $label) => preg_quote((string) $label, '/'), $labels));
        if ($pattern === '' || ! preg_match('/^(?:'.$pattern.')\s*:\s*(.+)$/mui', $text, $matches)) {
            return null;
        }

        return $this->normalizeWhitespace($matches[1]);
    }

    /** @param array<string, mixed> $profile @return array{sku: string, details: string, price: string, inline_name: ?string}|null */
    private function articleAnchor(string $line, array $profile): ?array
    {
        $article = $profile['article'];
        $trailingPattern = $article['trailing_pattern'] === null
            ? '\\s*'
            : '\\s*(?:'.$article['trailing_pattern'].')\\s*';
        $pattern = '/^(?<prefix>.*?)(?<!\S)(?<sku>'.$article['sku_pattern'].')\s+(?<details>.*?)(?<price>'.$article['price_pattern'].')'.$trailingPattern.'$/ui';
        if (preg_match($pattern, $line, $matches) !== 1) {
            return null;
        }

        $inlineName = $this->normalizeWhitespace($matches['prefix']);
        if ($inlineName !== '' && ! $article['allow_inline_name']) {
            return null;
        }
        if ($inlineName === '' && ! $article['allow_previous_line_name']) {
            return null;
        }

        return [
            'sku' => $matches['sku'],
            'details' => trim($matches['details']),
            'price' => $matches['price'],
            'inline_name' => $inlineName === '' ? null : $inlineName,
        ];
    }

    /** @param array<int, string> $pendingLines @param array<string, array<string, string|null>> $categories @param array<string, mixed> $profile @param array<int, array<string, mixed>> $warnings */
    private function resolvePendingCategory(array &$pendingLines, bool $hasInlineName, ?string &$currentCategoryPath, array &$categories, array $profile, int $pageNumber, array &$warnings): void
    {
        if ($pendingLines === [] || (! $hasInlineName && count($pendingLines) <= 1)) {
            return;
        }

        $candidate = $pendingLines[0];
        if ($this->isConfirmedSimpleCategory($candidate, $profile)) {
            $currentCategoryPath = $candidate;
            $this->addCategoryPath($categories, $candidate, $profile);
            array_shift($pendingLines);

            return;
        }

        if ($this->looksLikePossibleCategory($candidate)) {
            $currentCategoryPath = null;
            array_shift($pendingLines);
            $warnings[] = $this->issue('category_ambiguous', 'La première ligne avant l’article pourrait être une catégorie, sans confirmation du profil de format.', $pageNumber, $candidate);
        }
    }

    /** @param array<string, mixed> $profile */
    private function containsCompleteArticleSignature(string $value, array $profile): bool
    {
        $article = $profile['article'];
        $trailing = $article['trailing_pattern'] === null
            ? '\\s*$'
            : '(?:'.$article['trailing_pattern'].')(?:\\s|$)';
        $pattern = '/(?<!\\S)(?:'.$article['sku_pattern'].')\\s+.+?(?:'.$article['price_pattern'].')\\s*'.$trailing.'/ui';

        return preg_match($pattern, $value) === 1;
    }

    /** @param array<string, mixed> $profile */
    private function looksLikeCategoryPath(string $line, array $profile): bool
    {
        $categories = $profile['categories'];
        $separator = $categories['path_separator'];
        if (! is_string($separator) || $separator === '' || ! str_contains($line, $separator)) {
            return false;
        }
        if (is_string($categories['path_pattern']) && $categories['path_pattern'] !== '') {
            return preg_match('/^(?:'.$categories['path_pattern'].')$/ui', $line) === 1;
        }

        $parts = array_map('trim', explode($separator, $line));

        return count($parts) > 1 && ! in_array('', $parts, true) && ! preg_match('/\d/', $line) && strlen($line) <= 500;
    }

    /** @param array<string, mixed> $profile */
    private function isConfirmedSimpleCategory(string $line, array $profile): bool
    {
        foreach ($profile['categories']['simple_patterns'] as $pattern) {
            if (preg_match('/^(?:'.$pattern.')$/ui', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikePossibleCategory(string $line): bool
    {
        return strlen($line) <= 160 && ! preg_match('/\d/', $line) && preg_match('/\p{L}/u', $line) === 1;
    }

    /** @param array<string, array<string, string|null>> $categories @param array<string, mixed> $profile */
    private function addCategoryPath(array &$categories, string $sourcePath, array $profile): void
    {
        $separator = $profile['categories']['path_separator'];
        $parts = array_values(array_filter(array_map('trim', explode($separator, $sourcePath))));
        $paths = [];
        foreach ($parts as $part) {
            $paths[] = $part;
            $path = implode($separator, $paths);
            $key = $this->key($path);
            if (! isset($categories[$key])) {
                $categories[$key] = [
                    'client_key' => 'category-'.substr(sha1($key), 0, 12),
                    'name' => $part,
                    'source_path' => $path,
                    'parent_source_path' => count($paths) === 1 ? null : implode($separator, array_slice($paths, 0, -1)),
                ];
            }
        }
    }

    /** @param array<string, mixed> $profile @return array{brand: ?string, purchase_unit_label: ?string, pack_label: ?string, warning: ?array{code: string, message: string}} */
    private function packageMetadata(string $details, array $profile): array
    {
        $units = implode('|', array_map(fn (mixed $unit) => preg_quote((string) $unit, '/'), $profile['purchase_units']));
        if ($units === '' || ! preg_match('/\b('.$units.')\b/ui', $details, $matches, PREG_OFFSET_CAPTURE)) {
            return ['brand' => null, 'purchase_unit_label' => null, 'pack_label' => null, 'warning' => ['code' => 'purchase_unit_not_detected', 'message' => 'L’unité ou le conditionnement n’a pas été identifié de manière fiable.']];
        }

        $unit = $matches[1][0];
        $offset = $matches[1][1];
        $candidate = trim(substr($details, 0, $offset));
        $brand = $this->looksLikeBrand($candidate) ? $candidate : null;

        return ['brand' => $brand, 'purchase_unit_label' => $unit, 'pack_label' => $this->normalizeWhitespace(substr($details, $offset)), 'warning' => $candidate !== '' && $brand === null ? ['code' => 'brand_not_identified', 'message' => 'Le texte précédant le conditionnement ne ressemble pas à une marque fiable.'] : null];
    }

    private function looksLikeBrand(string $value): bool
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = $ascii === false ? $value : $ascii;

        return $ascii !== '' && preg_match('/^[A-Z][A-Z &\-]{1,80}$/', $ascii) === 1;
    }

    private function detectCurrency(string $text): ?string
    {
        return match (true) {
            preg_match('/(?:€|\bEUR\b)/ui', $text) === 1 => 'EUR',
            preg_match('/(?:\$|\bUSD\b)/ui', $text) === 1 => 'USD',
            preg_match('/(?:£|\bGBP\b)/ui', $text) === 1 => 'GBP',
            default => null,
        };
    }

    private function isCurrencyMarker(string $line): bool
    {
        return preg_match('/^(?:€|EUR|\$|USD|£|GBP)$/ui', $line) === 1;
    }

    /** @param array<string, mixed> $profile */
    private function isIgnoredStructuralLine(string $line, array $profile): bool
    {
        foreach ($profile['ignored_line_patterns'] as $pattern) {
            if (preg_match('/^(?:'.$pattern.')$/ui', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $profile */
    private function isDocumentMetadata(string $line, array $profile): bool
    {
        $labels = [...$profile['metadata_labels']['supplier'], ...$profile['metadata_labels']['catalog'], ...$profile['metadata_labels']['ignored']];
        $pattern = implode('|', array_map(fn (mixed $label) => preg_quote((string) $label, '/'), $labels));

        return $pattern !== '' && preg_match('/^(?:'.$pattern.')\s*:/ui', $line) === 1;
    }

    /** @param array<int, string> $pendingLines @param array<int, array<string, mixed>> $unparsedRows @param array<int, array<string, mixed>> $warnings */
    private function flushUnparsedLines(array &$pendingLines, int $page, array &$unparsedRows, array &$warnings): void
    {
        if ($pendingLines === []) {
            return;
        }
        $rawLabel = $this->normalizeWhitespace(implode("\n", $pendingLines));
        $pendingLines = [];
        if ($rawLabel !== '') {
            $this->addUnparsedRow($unparsedRows, $warnings, 'unparsed_text_block', 'Le bloc de texte ne peut pas être rattaché de manière fiable à un article.', $page, $rawLabel);
        }
    }

    /** @param array<int, array<string, mixed>> $unparsedRows @param array<int, array<string, mixed>> $warnings */
    private function addUnparsedRow(array &$unparsedRows, array &$warnings, string $code, string $message, int $page, string $rawLabel): void
    {
        $issue = $this->issue($code, $message, $page, $rawLabel);
        $unparsedRows[] = $issue;
        $warnings[] = $issue;
    }

    /** @return array{code: string, message: string, source: array{page: int|null, raw_label: string|null}} */
    private function issue(string $code, string $message, ?int $page = null, ?string $rawLabel = null): array
    {
        return ['code' => $code, 'message' => $message, 'source' => ['page' => $page, 'raw_label' => $rawLabel]];
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function key(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtolower($this->normalizeWhitespace($ascii === false ? $value : $ascii));
    }
}
