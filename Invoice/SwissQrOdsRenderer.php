<?php

namespace KimaiPlugin\SwissQrBundle\Invoice;

use KimaiPlugin\SwissQrBundle\Service\SwissQrService;

final class SwissQrOdsRenderer extends AbstractSwissQrSpreadsheetRenderer
{
    protected function getFileExtensions(): array
    {
        return ['.ods'];
    }

    protected function getContentType(): string
    {
        return 'application/vnd.oasis.opendocument.spreadsheet';
    }

    protected function getWriterType(): string
    {
        return 'Ods';
    }

    protected function getTempPrefix(): string
    {
        return 'kimai-invoice-ods';
    }

    /**
     * PhpSpreadsheet's ODS writer ignores Drawing objects, so we inject the PNG
     * into the OpenDocument package after the file has been written.
     */
    protected function afterSave(string $filename, string $pngPath, string $coordinates): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($filename) !== true) {
            throw new \RuntimeException('Could not open generated ODS for QR embedding');
        }

        $pictureName = 'Pictures/swiss-qr.png';
        $pngData = file_get_contents($pngPath);
        if ($pngData === false) {
            $zip->close();
            throw new \RuntimeException('Could not read Swiss QR PNG for ODS embedding');
        }

        $zip->addFromString($pictureName, $pngData);

        $manifest = $zip->getFromName('META-INF/manifest.xml');
        if (\is_string($manifest) && !str_contains($manifest, $pictureName)) {
            $entry = sprintf(
                ' <manifest:file-entry manifest:full-path="%s" manifest:media-type="image/png"/>' . "\n",
                $pictureName
            );
            $manifest = str_replace('</manifest:manifest>', $entry . '</manifest:manifest>', $manifest);
            $zip->addFromString('META-INF/manifest.xml', $manifest);
        }

        $content = $zip->getFromName('content.xml');
        if (!\is_string($content)) {
            $zip->close();
            throw new \RuntimeException('ODS content.xml missing');
        }

        $sizeCm = number_format(SwissQrService::QR_SIZE_MM / 10, 1, '.', '');
        $frame = sprintf(
            '<draw:frame draw:name="SwissQrCode" text:anchor-type="paragraph" svg:width="%scm" svg:height="%scm" draw:z-index="1">'
            . '<draw:image xlink:href="%s" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>'
            . '</draw:frame>',
            $sizeCm,
            $sizeCm,
            $pictureName
        );

        // Prefer replacing an emptied QR placeholder cell; otherwise append near first table.
        if (str_contains($content, 'office:value-type="string"><text:p/></table:table-cell>')) {
            $content = preg_replace(
                '/office:value-type="string"><text:p\/><\/table:table-cell>/',
                'office:value-type="string"><text:p/>' . $frame . '</table:table-cell>',
                $content,
                1
            ) ?? $content;
        } elseif (preg_match('/<table:table-cell([^>]*)\/>/', $content)) {
            $content = preg_replace(
                '/<table:table-cell([^>]*)\/>/',
                '<table:table-cell$1>' . $frame . '</table:table-cell>',
                $content,
                1
            ) ?? $content;
        } else {
            $content = str_replace('</office:spreadsheet>', $frame . '</office:spreadsheet>', $content);
        }

        // Ensure draw namespace exists on document content root.
        if (!str_contains($content, 'xmlns:draw=')) {
            $content = preg_replace(
                '/<office:document-content([^>]*)>/',
                '<office:document-content$1 xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:xlink="http://www.w3.org/1999/xlink">',
                $content,
                1
            ) ?? $content;
        }

        $zip->addFromString('content.xml', $content);
        $zip->close();
    }
}
