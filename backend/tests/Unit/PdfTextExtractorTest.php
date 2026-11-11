<?php

namespace Tests\Unit;

use App\Services\Purchasing\PdfTextExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PdfTextExtractorTest extends TestCase
{
    public function test_it_rejects_an_unreadable_file(): void
    {
        $this->expectException(RuntimeException::class);

        (new PdfTextExtractor)->extract('/tmp/tempo-pdf-does-not-exist.pdf');
    }
}
