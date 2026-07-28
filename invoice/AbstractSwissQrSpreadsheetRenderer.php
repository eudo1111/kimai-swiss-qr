<?php

namespace KimaiPlugin\SwissQrBundle\Invoice;

use App\Invoice\InvoiceFilename;
use App\Invoice\InvoiceModel;
use App\Invoice\RendererInterface;
use App\Model\InvoiceDocument;
use KimaiPlugin\SwissQrBundle\Service\SwissQrService;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Shared XLSX/ODS rendering with Swiss QR PNG embedding.
 *
 * Mirrors Kimai's AbstractSpreadsheetRenderer, plus Drawing insertion when a cell
 * contains ${invoice.swiss_qr_code_png}.
 */
abstract class AbstractSwissQrSpreadsheetRenderer implements RendererInterface
{
    public function __construct(private readonly SwissQrService $swissQrService)
    {
    }

    abstract protected function getFileExtensions(): array;

    abstract protected function getContentType(): string;

    abstract protected function getWriterType(): string;

    abstract protected function getTempPrefix(): string;

    public function supports(InvoiceDocument $document): bool
    {
        foreach ($this->getFileExtensions() as $extension) {
            if (stripos($document->getFilename(), $extension) !== false) {
                return true;
            }
        }

        return false;
    }

    public function render(InvoiceDocument $document, InvoiceModel $model): Response
    {
        $spreadsheet = IOFactory::load($document->getFilename());
        $worksheet = $spreadsheet->getActiveSheet();
        $entries = $model->getCalculator()->getEntries();
        $sheetReplacer = $model->toArray();
        $pngPath = $this->swissQrService->materializePngPath();
        $invoiceItemCount = \count($entries);
        $qrCoordinates = null;

        if ($invoiceItemCount > 1) {
            $this->addTemplateRows($worksheet, $invoiceItemCount);
        }

        $title = substr($model->getTemplate()->getTitle(), 0, 31);
        foreach (Worksheet::getInvalidCharacters() as $char) {
            $title = str_replace($char, ' ', $title);
        }
        $worksheet->setTitle($title);

        $entryRow = 0;
        Cell::setValueBinder(new \App\Invoice\Renderer\AdvancedValueBinder());

        foreach ($worksheet->getRowIterator() as $row) {
            $sheetValues = false;
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if ($value === null) {
                    continue;
                }

                if (SwissQrOfficeImage::isPngPlaceholder(\is_string($value) ? $value : null) && $pngPath !== null) {
                    $qrCoordinates = $cell->getCoordinate();
                    $this->embedPng($worksheet, $qrCoordinates, $pngPath);
                    $cell->setValue('');
                    continue;
                }

                $replacer = null;
                if (stripos((string) $value, '${entry.') !== false) {
                    if ($sheetValues === false && isset($entries[$entryRow])) {
                        $sheetValues = $model->itemToArray($entries[$entryRow]);
                    }
                    $replacer = $sheetValues;
                } elseif (stripos((string) $value, '${') !== false) {
                    $replacer = $sheetReplacer;
                }

                if (empty($replacer)) {
                    continue;
                }

                $contentLooksLikeFormula = false;
                foreach ($replacer as $key => $content) {
                    $searchKey = '${' . $key . '}';
                    if (stripos((string) $value, $searchKey) === false) {
                        continue;
                    }
                    if (\is_string($content) && $content !== '' && \in_array($content[0], ['=', '-', '+', '@', "\t", "\r"], true)) {
                        $contentLooksLikeFormula = true;
                    }
                    $value = str_replace($searchKey, $content ?? '', (string) $value);
                }

                if ($contentLooksLikeFormula) {
                    $cell->setValueExplicit($value, DataType::TYPE_STRING);
                } else {
                    $cell->setValue($value);
                }
            }

            if ($sheetValues !== false && $entryRow < $invoiceItemCount - 1) {
                $entryRow++;
            }
        }

        $filename = $this->saveSpreadsheet($spreadsheet);
        if ($pngPath !== null && $qrCoordinates !== null) {
            $this->afterSave($filename, $pngPath, $qrCoordinates);
        }
        $userFilename = (string) new InvoiceFilename($model) . '.' . $document->getFileExtension();

        $response = new BinaryFileResponse($filename);
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $userFilename);
        $response->headers->set('Content-Type', $this->getContentType());
        $response->headers->set('Content-Disposition', $disposition);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function embedPng(Worksheet $worksheet, string $coordinates, string $pngPath): void
    {
        $drawing = new Drawing();
        $drawing->setName('Swiss QR Code');
        $drawing->setDescription('Swiss QR-bill payment code');
        $drawing->setPath($pngPath);
        $drawing->setCoordinates($coordinates);
        $drawing->setWidth(SwissQrOfficeImage::spreadsheetPixelSize());
        $drawing->setHeight(SwissQrOfficeImage::spreadsheetPixelSize());
        $drawing->setWorksheet($worksheet);
    }

    /**
     * Hook for writers that do not persist drawings (e.g. ODS).
     */
    protected function afterSave(string $filename, string $pngPath, string $coordinates): void
    {
    }

    private function saveSpreadsheet(Spreadsheet $spreadsheet): string
    {
        $filename = @tempnam(sys_get_temp_dir(), $this->getTempPrefix());
        if (false === $filename) {
            throw new \Exception('Could not open temporary file');
        }

        $writer = IOFactory::createWriter($spreadsheet, $this->getWriterType());
        $writer->save($filename);

        return $filename;
    }

    private function addTemplateRows(Worksheet $worksheet, int $invoiceItemCount): void
    {
        $startRow = null;
        $rowCounter = 0;

        foreach ($worksheet->getRowIterator() as $row) {
            $cellCounter = 0;
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if ($value !== null && stripos((string) $value, '${entry.') !== false) {
                    $startRow = $row->getRowIndex();
                    $worksheet->insertNewRowBefore($startRow + 1, $invoiceItemCount - 1);
                    break 2;
                }

                if ($cellCounter++ >= 10) {
                    break;
                }
            }

            if ($rowCounter++ >= 100) {
                break;
            }
        }

        if ($startRow === null) {
            throw new \Exception('Invalid invoice document, no template row found.');
        }

        $templateRow = $startRow;
        $iterator = $worksheet->getRowIterator($templateRow, $templateRow + 1);
        $templateColumns = [];
        $tmpRow = $iterator->current();
        foreach ($tmpRow->getCellIterator() as $cell) {
            $templateColumns[$cell->getColumn()] = $cell->getValue();
        }

        $iterator = $worksheet->getRowIterator($startRow, $startRow + $invoiceItemCount - 1);
        foreach ($iterator as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $cell->setValue($templateColumns[$cell->getColumn()]);
            }
        }
    }
}
