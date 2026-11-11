<?php

namespace App\Services\Purchasing;

use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

class PdfTextExtractor
{
    private const DECODE_MEMORY_LIMIT = 67108864;

    /**
     * @return array<int, array{page: int, text: string}>
     */
    public function extract(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('PDF text extraction failed.');
        }

        try {
            $config = new Config;
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(self::DECODE_MEMORY_LIMIT);

            $document = (new Parser([], $config))->parseFile($path);
            $pages = $document->getPages();

            return array_map(
                fn ($page, int $index) => [
                    'page' => $index + 1,
                    'text' => $this->normalizeText($page->getText()),
                ],
                $pages,
                array_keys($pages)
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('PDF text extraction failed.', 0, $exception);
        }
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\0", '', $text);

        $utf8Text = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        return $utf8Text === false ? '' : $utf8Text;
    }
}
