<?php

namespace App\Support;

use App\Models\DiningTable;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * A table's QR code: scanning it opens the ordering site with that table chosen. SVG, so
 * it prints sharp at any size.
 */
final class TableQrCode
{
    public static function svg(DiningTable $table): string
    {
        $options = new QROptions([
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            // Medium error correction survives a scuffed or slightly wet table card.
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'drawLightModules' => false,
            'connectPaths' => true,
        ]);

        return (new QRCode($options))->render($table->orderingUrl());
    }
}
