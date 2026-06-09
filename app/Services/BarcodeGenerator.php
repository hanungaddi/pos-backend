<?php

namespace App\Services;

class BarcodeGenerator
{
    // Code 128 Character Set B width definitions (widths of alternating black and white bars)
    private static array $widths = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', // 0-9
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132', // 10-19
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', // 20-29
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', // 30-39
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', // 40-49
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', // 50-59
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', // 60-69
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', // 70-79
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', // 80-89
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', // 90-99
        '114131', '311141', '411131',
        '211412', // Start A (103)
        '211214', // Start B (104)
        '211232', // Start C (105)
        '233111', // Stop (106)
    ];

    /**
     * Generate Code-128 vector SVG barcode for any standard ASCII text.
     */
    public static function getSvg(string $text, int $height = 50, int $moduleWidth = 2): string
    {
        $startChar = 104; // Start B character
        $checksum = $startChar;
        $encoded = [$startChar];
        
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = ord($text[$i]);
            $val = $char - 32; // Map ASCII to Code 128 B values (ASCII 32 to 127)
            if ($val < 0 || $val > 95) {
                // Skip invalid characters outside standard ASCII printable range
                continue;
            }
            $encoded[] = $val;
            $checksum += $val * ($i + 1);
        }
        
        $stopChar = 106;
        $checksumVal = $checksum % 103;
        $encoded[] = $checksumVal;
        $encoded[] = $stopChar;
        
        // Build the binary module representation (1 for bar, 0 for space)
        $bars = '';
        foreach ($encoded as $charIdx) {
            $widths = self::$widths[$charIdx];
            $bars .= self::widthsToBars($widths);
        }
        $bars .= '11'; // Trailing stop bar (2 modules wide)
        
        // Calculate dimensions
        $width = strlen($bars) * $moduleWidth;
        
        // Build SVG output
        $svg = "<svg width=\"{$width}\" height=\"{$height}\" viewBox=\"0 0 {$width} {$height}\" xmlns=\"http://www.w3.org/2000/svg\">";
        $svg .= "<g fill=\"black\">";
        for ($i = 0; $i < strlen($bars); $i++) {
            if ($bars[$i] === '1') {
                $x = $i * $moduleWidth;
                $svg .= "<rect x=\"{$x}\" y=\"0\" width=\"{$moduleWidth}\" height=\"{$height}\" />";
            }
        }
        $svg .= "</g></svg>";
        
        return $svg;
    }

    private static function widthsToBars(string $widths): string
    {
        $bars = '';
        $isBlack = true;
        for ($i = 0; $i < strlen($widths); $i++) {
            $w = (int)$widths[$i];
            $bars .= str_repeat($isBlack ? '1' : '0', $w);
            $isBlack = !$isBlack;
        }
        return $bars;
    }
}
