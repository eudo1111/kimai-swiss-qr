<?php

namespace KimaiPlugin\SwissQrBundle\Invoice;

final class SwissQrXlsxRenderer extends AbstractSwissQrSpreadsheetRenderer
{
    protected function getFileExtensions(): array
    {
        return ['.xlsx', '.xls'];
    }

    protected function getContentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    protected function getWriterType(): string
    {
        return 'Xlsx';
    }

    protected function getTempPrefix(): string
    {
        return 'kimai-invoice-xlsx';
    }
}
