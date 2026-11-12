<?php

namespace App\Http\Controllers;

use App\Services\Purchasing\CatalogParsingProfileRegistry;
use App\Services\Purchasing\PdfTextExtractor;
use App\Services\Purchasing\SupplierCatalogParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class SupplierCatalogDocumentParserController extends Controller
{
    public function parse(
        Request $request,
        PdfTextExtractor $extractor,
        CatalogParsingProfileRegistry $profiles,
        SupplierCatalogParser $parser
    ): JsonResponse {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'profile_id' => ['nullable', 'string', 'max:100'],
        ]);

        $file = $validated['file'];
        try {
            $profile = $profiles->resolve($validated['profile_id'] ?? null);
        } catch (InvalidArgumentException) {
            return response()->json([
                'ok' => false,
                'error' => 'catalog_parsing_profile_not_found',
                'message' => 'Le profil de lecture du catalogue est introuvable.',
            ], 422);
        }

        try {
            $pages = $extractor->extract($file->getRealPath());
        } catch (RuntimeException) {
            return response()->json([
                'ok' => false,
                'error' => 'pdf_extraction_failed',
                'message' => 'Le PDF n’a pas pu être lu.',
            ], 422);
        }

        try {
            $result = $parser->parse($pages, $file->getClientOriginalName(), $profile);
        } catch (RuntimeException) {
            return response()->json([
                'ok' => false,
                'error' => 'catalog_parsing_profile_invalid',
                'message' => 'Le profil de lecture du catalogue est invalide.',
            ], 422);
        }

        return response()->json(['ok' => true, ...$result]);
    }
}
