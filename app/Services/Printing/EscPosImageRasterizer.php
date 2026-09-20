<?php

namespace App\Services\Printing;

use GdImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the URL of an image of a template into an ESC/POS raster command.
 *
 * Thermal printers only understand 1-bit bitmaps, and the phone cannot download
 * the image (the internal domains do not resolve from the device), so the
 * server downloads it, scales it to the paper width, centers it and dithers it
 * to black and white. The result is a `GS v 0` command (raster bit image), which
 * every 58/80 mm ESC/POS printer understands.
 */
class EscPosImageRasterizer
{
    /** Paper width in dots. */
    public const DOTS_58MM = 384;

    public const DOTS_80MM = 576;

    /** Everything darker than this level is printed as a black dot. */
    private const BLACK_THRESHOLD = 128;

    /**
     * Bytes of the image command, or null when the image cannot be used (a
     * broken logo must never stop the ticket from printing).
     */
    public static function command(string $url, int $paperWidthDots): ?string
    {
        $bitmap = self::bitmap($url, $paperWidthDots);

        if ($bitmap === null) {
            return null;
        }

        $bytesPerRow = (int) ceil($bitmap['width'] / 8);

        // GS v 0 m xL xH yL yH d1...dk
        return "\x1D\x76\x30\x00"
            . chr($bytesPerRow % 256) . chr(intdiv($bytesPerRow, 256))
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

    /**
     * @return array{width: int, height: int, data: string}|null
     */
    private static function bitmap(string $url, int $paperWidthDots): ?array
    {
        $source = self::download($url);

        if ($source === null) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width < 1 || $height < 1) {
            imagedestroy($source);

            return null;
        }

        $scale = $width > $paperWidthDots ? $paperWidthDots / $width : 1.0;
        $targetWidth = (int) max(1, floor($width * $scale));
        $targetHeight = (int) max(1, round($height * $scale));

        // The image is centered on a full width canvas, so the raster of every
        // row has the same size and the ticket stays aligned.
        $canvas = imagecreatetruecolor($paperWidthDots, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled(
            $canvas,
            $source,
            intdiv($paperWidthDots - $targetWidth, 2),
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        $data = self::toMonochromeBytes($canvas, $paperWidthDots, $targetHeight);

        imagedestroy($canvas);
        imagedestroy($source);

        return ['width' => $paperWidthDots, 'height' => $targetHeight, 'data' => $data];
    }

    /**
     * Packs the canvas as 1-bit rows (bit 1 = black dot, MSB first).
     */
    private static function toMonochromeBytes(GdImage $canvas, int $width, int $height): string
    {
        $data = '';

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x += 8) {
                $byte = 0;

                for ($bit = 0; $bit < 8; $bit++) {
                    $pixel = imagecolorat($canvas, $x + $bit, $y);
                    $gray = (($pixel >> 16) & 0xFF) * 0.299
                        + (($pixel >> 8) & 0xFF) * 0.587
                        + ($pixel & 0xFF) * 0.114;

                    if ($gray < self::BLACK_THRESHOLD) {
                        $byte |= 0x80 >> $bit;
                    }
                }

                $data .= chr($byte);
            }
        }

        return $data;
    }

    private static function download(string $url): ?GdImage
    {
        try {
            $response = Http::timeout(5)->get($url);
        } catch (Throwable $exception) {
            Log::warning("No se pudo descargar la imagen del ticket para ESC/POS: {$exception->getMessage()}");

            return null;
        }

        if (!$response->successful()) {
            Log::warning("La imagen del ticket para ESC/POS respondió {$response->status()}: {$url}");

            return null;
        }

        $image = @imagecreatefromstring($response->body());

        if (!$image instanceof GdImage) {
            Log::warning("No se pudo convertir a imagen el archivo del ticket para ESC/POS: {$url}");

            return null;
        }

        return $image;
    }
}
