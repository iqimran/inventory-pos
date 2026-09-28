<?php

namespace App\Domain\Barcode;

use InvalidArgumentException;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use Throwable;

/**
 * Renders Code 128 barcodes as SVG. Code 128 is the internal default: it encodes the full printable
 * ASCII range (so existing alphanumeric barcodes and document numbers work) and switches to the
 * compact numeric subset automatically for all-digit values.
 */
class BarcodeRenderer
{
    /** Width in SVG units per barcode module; the image is scaled by CSS when printed. */
    private const MODULE_WIDTH = 2;

    public function supports(string $value): bool
    {
        return $value !== '' && preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    /**
     * @throws InvalidArgumentException for values Code 128 cannot encode
     */
    public function svg(string $value, int $height = 60): string
    {
        if (! $this->supports($value)) {
            throw new InvalidArgumentException('Code 128 can only encode printable ASCII characters.');
        }

        try {
            $barcode = (new TypeCode128)->getBarcode($value);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Cannot encode [{$value}] as Code 128.", previous: $e);
        }

        $svg = (new SvgRenderer)->render($barcode, $barcode->getWidth() * self::MODULE_WIDTH, $height);

        // Let templates size the image freely (every bar scales equally, so the code stays valid).
        return preg_replace('/<svg /', '<svg preserveAspectRatio="none" ', $svg, 1);
    }

    /**
     * SVG as a data URI, for <img src> (no inline markup injection in templates).
     */
    public function dataUri(string $value, int $height = 60): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($value, $height));
    }

    /**
     * Data URI, or null when the value cannot be rendered (e.g. legacy data).
     */
    public function tryDataUri(?string $value, int $height = 60): ?string
    {
        return $value !== null && $this->supports($value) ? $this->dataUri($value, $height) : null;
    }
}
