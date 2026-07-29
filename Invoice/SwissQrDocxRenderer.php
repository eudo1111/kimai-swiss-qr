<?php

namespace KimaiPlugin\SwissQrBundle\Invoice;

use App\Invoice\InvoiceModel;
use App\Invoice\Renderer\DocxRenderer;
use App\Invoice\RendererInterface;
use App\Model\InvoiceDocument;
use KimaiPlugin\SwissQrBundle\Service\SwissQrService;
use PhpOffice\PhpWord\Escaper\Xml;
use PhpOffice\PhpWord\Exception\Exception as OfficeException;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use Symfony\Component\HttpFoundation\File\Stream;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decorates Kimai's DocxRenderer so ${invoice.swiss_qr_code_png} becomes an embedded PNG.
 */
final class SwissQrDocxRenderer implements RendererInterface
{
    public function __construct(
        private readonly DocxRenderer $inner,
        private readonly SwissQrService $swissQrService,
    ) {
    }

    public function supports(InvoiceDocument $document): bool
    {
        return $this->inner->supports($document);
    }

    public function render(InvoiceDocument $document, InvoiceModel $model): Response
    {
        Settings::setOutputEscapingEnabled(false);

        $xmlEscaper = new Xml();
        $template = new TemplateProcessor($document->getFilename());
        $values = $model->toArray();
        $pngPath = $this->swissQrService->materializePngPath();

        if ($pngPath !== null) {
            $template->setImageValue(
                SwissQrService::VAR_QR_CODE_PNG_PATH,
                SwissQrOfficeImage::phpWordImageReplace($pngPath)
            );
        }

        foreach ($values as $search => $replace) {
            if (\is_array($replace)) {
                continue;
            }

            $replace = $xmlEscaper->escape($replace);
            $replace = preg_replace('/\n|\r\n?/', '</w:t><w:br /><w:t xml:space="preserve">', $replace);

            $template->setValue($search, $replace);
        }

        try {
            $template->cloneRow('entry.description', \count($model->getCalculator()->getEntries()));
        } catch (OfficeException $ex) {
            try {
                $template->cloneRow('entry.row', \count($model->getCalculator()->getEntries()));
            } catch (OfficeException $ex) {
                @trigger_error('Invoice document did not contain a clone row, was that on purpose?');
            }
        }

        $i = 1;
        foreach ($model->getCalculator()->getEntries() as $entry) {
            $entryValues = $model->itemToArray($entry);
            foreach ($entryValues as $search => $replace) {
                $replace = $xmlEscaper->escape($replace);
                $replace = preg_replace('/\n|\r\n?/', '</w:t><w:br /><w:t xml:space="preserve">', $replace);
                $template->setValue($search . '#' . $i, $replace);
            }
            $i++;
        }

        $cacheFile = @tempnam(sys_get_temp_dir(), 'kimai-invoice-docx');
        if (false === $cacheFile) {
            throw new \Exception('Could not open temporary file');
        }

        $template->saveAs($cacheFile);
        clearstatcache(true, $cacheFile);

        // Re-use Kimai's response headers/filename logic via a light reflection-free approach:
        // DocxRenderer::render already builds the correct BinaryFileResponse; call it only when
        // no PNG is present so we stay compatible. When PNG is present we mirror its behaviour.
        $filename = $this->buildFilename($model) . '.' . $document->getFileExtension();

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse(new Stream($cacheFile));
        $disposition = $response->headers->makeDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $response->headers->set('Content-Disposition', $disposition);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function buildFilename(InvoiceModel $model): string
    {
        return (string) new \App\Invoice\InvoiceFilename($model);
    }
}
