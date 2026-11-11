<?php

namespace App\Http\Controllers;

use App\Services\Purchasing\PdfTextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SupplierCatalogPdfExtractionController extends Controller
{
    public function extract(Request $request, PdfTextExtractor $extractor): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $file = $validated['file'];

        try {
            $pages = $extractor->extract($file->getRealPath());
        } catch (RuntimeException) {
            return response()->json([
                'ok' => false,
                'error' => 'pdf_extraction_failed',
                'message' => 'Le PDF n’a pas pu être lu.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'filename' => $file->getClientOriginalName(),
            'page_count' => count($pages),
            'pages' => $pages,
        ]);
    }
}
