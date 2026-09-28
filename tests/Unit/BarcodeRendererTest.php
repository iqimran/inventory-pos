<?php

namespace Tests\Unit;

use App\Domain\Barcode\BarcodeRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BarcodeRendererTest extends TestCase
{
    public function test_renders_code128_svg()
    {
        $svg = (new BarcodeRenderer)->svg('200000000042');

        $this->assertStringContainsString('<svg preserveAspectRatio="none" ', $svg); // fills the label box
        $this->assertStringContainsString('<desc>200000000042</desc>', $svg);
        // Code 128: start + 6 digit pairs (subset C) + check + stop = 101 modules × 2 units.
        $this->assertStringContainsString('width="202"', $svg);
        $this->assertGreaterThan(20, substr_count($svg, '<rect '));
    }

    public function test_encodes_alphanumeric_document_numbers()
    {
        $svg = (new BarcodeRenderer)->svg('SALE-202609-000001');

        $this->assertStringContainsString('<desc>SALE-202609-000001</desc>', $svg);
    }

    public function test_value_is_escaped_in_the_svg()
    {
        $svg = (new BarcodeRenderer)->svg('A<B>&"C');

        $this->assertStringContainsString('<desc>A&lt;B&gt;&amp;&quot;C</desc>', $svg);
        $this->assertStringNotContainsString('<B>', $svg);
    }

    public function test_data_uri()
    {
        $uri = (new BarcodeRenderer)->dataUri('12345678');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertStringContainsString('<desc>12345678</desc>', base64_decode(substr($uri, strlen('data:image/svg+xml;base64,'))));
    }

    public function test_rejects_values_code128_cannot_encode()
    {
        $renderer = new BarcodeRenderer;

        $this->assertFalse($renderer->supports(''));
        $this->assertFalse($renderer->supports("tab\tinside"));
        $this->assertFalse($renderer->supports('ক্যাশ'));
        $this->assertNull($renderer->tryDataUri(null));
        $this->assertNull($renderer->tryDataUri('ক্যাশ'));

        $this->expectException(InvalidArgumentException::class);
        $renderer->svg('ক্যাশ');
    }
}
