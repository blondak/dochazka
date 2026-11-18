<?php
declare(strict_types=1);

namespace Dochazka\Absence;

use Dochazka\Http\Problem;

final class MedicalProof
{
    public static function normalize(string $base64): string
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false || strlen($bytes) > 5 * 1024 * 1024) {
            throw new Problem('Fotografie může mít nejvýše 5 MiB.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) ||
            $info[0] * $info[1] > 16_000_000) {
            throw new Problem('Vyberte JPEG nebo PNG do 16 megapixelů.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) throw new Problem('Fotografii se nepodařilo načíst.');
        $scale = min(1, 2400 / max($info[0], $info[1]));
        $width = max(1, (int) round($scale * $info[0]));
        $height = max(1, (int) round($scale * $info[1]));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        ob_start();
        try {
            if (!imagejpeg($canvas, null, 90)) throw new Problem('Fotografii se nepodařilo uložit.');
            return base64_encode((string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
