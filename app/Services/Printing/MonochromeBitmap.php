<?php

namespace App\Services\Printing;

use GdImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the URL of an image of a template into a 1-bit bitmap.
 *
 * Thermal printers (ESC/POS) and label printers (TSPL) only understand
 * monochrome bitmaps, and the phone cannot download the image (the internal
 * domains do not resolve from the device), so the server downloads it, scales
 * it to the width of the document and dithers it to black and white. Each
 * printer has its own formatter on top of this bitmap.
 */
class MonochromeBitmap
{
    /** Everything darker than this level is printed as a black dot. */
    private const BLACK_THRESHOLD = 128;

    /**
     * @param  int  $maxWidthDots  widest the image may be printed (dots)
     * @param  int|null  $canvasWidthDots  width of the resulting bitmap: when it
     *                                     is given, the image is centered on a
     *                                     canvas of that width (tickets); when it
     *                                     is null the bitmap keeps the width of
     *                                     the scaled image (labels, which place
     *                                     the image with their own coordinates)
     * @return array{width: int, height: int, bytes_per_row: int, data: string}|null
     */
    public static function fromUrl(string $url, int $maxWidthDots, ?int $canvasWidthDots = null): ?array
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

        $scale = $width > $maxWidthDots ? $maxWidthDots / $width : 1.0;
        $targetWidth = (int) max(1, floor($width * $scale));
        $targetHeight = (int) max(1, round($height * $scale));
        $bitmapWidth = $canvasWidthDots ?? $targetWidth;

        $canvas = imagecreatetruecolor($bitmapWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled(
            $canvas,
            $source,
            intdiv($bitmapWidth - $targetWidth, 2),
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        $data = self::pack($canvas, $bitmapWidth, $targetHeight);

        imagedestroy($canvas);
        imagedestroy($source);

        return [
            'width' => $bitmapWidth,
            'height' => $targetHeight,
            'bytes_per_row' => (int) ceil($bitmapWidth / 8),
            'data' => $data,
        ];
    }

    /**
     * Packs the canvas as 1-bit rows (bit 1 = black dot, MSB first).
     */
    private static function pack(GdImage $canvas, int $width, int $height): string
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
            Log::warning("No se pudo descargar la imagen del ticket para impresión: {$exception->getMessage()}");

            return null;
        }

        if (!$response->successful()) {
            Log::warning("La imagen del ticket respondió {$response->status()}: {$url}");

            return null;
        }

        $image = @imagecreatefromstring($response->body());

        if (!$image instanceof GdImage) {
            Log::warning("No se pudo convertir a imagen el archivo del ticket: {$url}");

            return null;
        }

        return $image;
    }
}
