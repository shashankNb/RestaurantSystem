<?php

namespace App\Filament\Support;

use Filament\Support\Colors\Color;

/**
 * Builds the back office colour palette from a restaurant's brand colour.
 *
 * Filament's generated palette puts a dark brand colour such as #7A1F2B near
 * shade 900, so buttons (shade 600) come out as a light coral. For dark brand
 * colours this anchors shade 600 on the brand colour itself and steps darker
 * from there, which keeps white button text readable.
 */
final class BrandPalette
{
    /**
     * @return array<int, string>
     */
    public static function fromHex(string $hex): array
    {
        $palette = Color::hex($hex);

        [$lightness, $chroma, $hue] = sscanf(Color::convertToOklch($hex), 'oklch(%f %f %f)');

        if (! is_float($lightness) || ! is_float($chroma) || ! is_float($hue) || $lightness >= 0.59) {
            return $palette;
        }

        $palette[500] = self::oklch(($lightness + 0.68) / 2, max($chroma, 0.12), $hue);

        foreach ([600 => 0.0, 700 => 0.05, 800 => 0.1, 900 => 0.14, 950 => 0.2] as $shade => $darker) {
            $palette[$shade] = self::oklch(max($lightness - $darker, 0.15), $chroma, $hue);
        }

        return $palette;
    }

    private static function oklch(float $lightness, float $chroma, float $hue): string
    {
        return sprintf('oklch(%.4f %.4f %.3f)', $lightness, $chroma, $hue);
    }
}
