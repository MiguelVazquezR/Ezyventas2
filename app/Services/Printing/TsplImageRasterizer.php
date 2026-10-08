<?php

namespace App\Services\Printing;

/**
 * Turns the URL of an image of a label template into a TSPL BITMAP command.
 *
 * Label printers (TSPL) print images with `BITMAP`, which needs the picture
 * already rasterized to 1 bit. The phone cannot download the image, so the
 * server does it and the light client only sends the resulting text.
 */
class TsplImageRasterizer
{
    /**
     * `BITMAP x,y,width,height,mode,data` ready to be added to the TSPL text, or
     * null when the image cannot be used (the label still prints without it).
     */
    public static function command(string $url, int $maxWidthDots, int $x, int $y): ?string
    {
        $bitmap = MonochromeBitmap::fromUrl($url, $maxWidthDots);

        if ($bitmap === null) {
            return null;
        }

        return sprintf(
            'BITMAP %d,%d,%d,%d,0,%s',
            $x,
            $y,
            $bitmap['bytes_per_row'],
            $bitmap['height'],
            bin2hex($bitmap['data'])
        );
    }
}
