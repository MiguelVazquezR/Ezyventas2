<?php

namespace App\Services\Printing;

/**
 * Turns the URL of an image of a template into an ESC/POS raster command.
 *
 * Thermal printers only understand 1-bit bitmaps, and the phone cannot download
 * the image, so the server rasterizes it (`MonochromeBitmap`) and wraps it in a
 * `GS v 0` command (raster bit image), which every 58/80 mm ESC/POS printer
 * understands.
 */
class EscPosImageRasterizer
{
    /** Paper width in dots. */
    public const DOTS_58MM = 384;

    public const DOTS_80MM = 576;

    /**
     * Bytes of the image command, or null when the image cannot be used (a
     * broken logo must never stop the ticket from printing).
     */
    public static function command(string $url, int $paperWidthDots): ?string
    {
        // The image is centered on a canvas as wide as the paper, so every row
        // of the raster has the same size and the ticket stays aligned.
        $bitmap = MonochromeBitmap::fromUrl($url, $paperWidthDots, $paperWidthDots);

        if ($bitmap === null) {
            return null;
        }

        // GS v 0 m xL xH yL yH d1...dk
        return "\x1D\x76\x30\x00"
            . chr($bitmap['bytes_per_row'] % 256) . chr(intdiv($bitmap['bytes_per_row'], 256))
            . chr($bitmap['height'] % 256) . chr(intdiv($bitmap['height'], 256))
            . $bitmap['data'];
    }

    /**
     * Dots of the paper width of a template.
     */
    public static function dotsForPaperWidth(string $paperWidth): int
    {
        return $paperWidth === '58mm' ? self::DOTS_58MM : self::DOTS_80MM;
    }
}

