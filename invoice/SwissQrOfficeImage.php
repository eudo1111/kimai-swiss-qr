<?php

namespace KimaiPlugin\SwissQrBundle\Invoice;

use KimaiPlugin\SwissQrBundle\Service\SwissQrService;

/**
 * Shared helpers for embedding the Swiss QR PNG into Office documents.
 */
final class SwissQrOfficeImage
{
    /**
     * @return array{path: string, width: int, height: int, ratio: bool}
     */
    public static function phpWordImageReplace(string $pngPath): array
    {
        return [
            'path' => $pngPath,
            'width' => SwissQrService::QR_SIZE_MM . 'mm',
            'height' => SwissQrService::QR_SIZE_MM . 'mm',
            'ratio' => true,
        ];
    }

    public static function spreadsheetPixelSize(): int
    {
        // 46mm at ~96 DPI ≈ 174px; keep a round size that scans well.
        return 180;
    }

    public static function isPngPlaceholder(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return stripos($value, '${' . SwissQrService::VAR_QR_CODE_PNG_PATH) !== false
            || stripos($value, SwissQrService::VAR_QR_CODE_PNG_PATH) !== false;
    }
}
