<?php

namespace KimaiPlugin\SwissQrBundle\Service;

use App\Entity\Customer;
use App\Entity\InvoiceTemplate;
use App\Invoice\InvoiceModel;
use App\Invoice\InvoiceModelHydrator;
use Sprain\SwissQrBill as QrBill;

require_once __DIR__ . '/../vendor/autoload.php';

class SwissQrService implements InvoiceModelHydrator
{
    public const VAR_REFERENCE = 'invoice.swiss_qr_reference';
    public const VAR_QR_CODE_SVG_B64 = 'invoice.swiss_qr_code';
    public const VAR_QR_CODE_PNG_PATH = 'invoice.swiss_qr_code_png';

    /** Official Swiss QR-bill size in millimeters. */
    public const QR_SIZE_MM = 46;

    private ?QrBill\QrCode\QrCode $pendingQrCode = null;
    private ?string $pngPath = null;

    public function hydrate(InvoiceModel $model): array
    {
        $qrInfo = $this->generateQrCodeFromModel($model);

        // PNG path is intentionally omitted from Twig/toArray output. Office
        // renderers obtain it via materializePngPath() only.
        return [
            self::VAR_REFERENCE => $qrInfo['qrReference'],
            self::VAR_QR_CODE_SVG_B64 => $qrInfo['qrCodeSvgBase64'],
            'template.payment_details' => $qrInfo['iban'],
        ];
    }

    /**
     * Creates (or reuses) a temp PNG for Office embedding. Never expose this
     * path through invoice template variables.
     */
    public function materializePngPath(): ?string
    {
        if ($this->pngPath !== null && is_file($this->pngPath)) {
            return $this->pngPath;
        }

        if ($this->pendingQrCode === null) {
            return null;
        }

        $this->pngPath = $this->writePngTempFile($this->pendingQrCode);

        return $this->pngPath;
    }

    private function generateQrCodeFromModel(InvoiceModel $model): array
    {
        $customer = $model->getCustomer();
        $template = $model->getTemplate();

        return $this->createQrCode(
            $model->getInvoiceNumber(),
            $model->getCalculator()->getTotal(),
            $customer,
            $template
        );
    }

    private function createQrCode(string $invoiceNumber, float $total, Customer $customer, InvoiceTemplate $template): array
    {
        if (strpos($invoiceNumber, '/') !== false) {
            throw new \InvalidArgumentException('There are invalid characters in your invoice number');
        }

        $cleanInvoiceNumber = str_replace('-', '', $invoiceNumber);
        $qrBill = QrBill\QrBill::create();

        $paymentDetails = (string) $template->getPaymentDetails();
        if (!preg_match('/^[a-zA-Z]{2}/', $paymentDetails)) {
            throw new \InvalidArgumentException('Payment details is not a valid IBAN number');
        }

        $company = $template->getCustomer();
        if ($company === null) {
            throw new \InvalidArgumentException('Invoice template has no linked company/customer (creditor)');
        }

        $creditorStreet = $this->resolveStreetLine($company);
        $creditorStreetParts = $this->extractBuildingNumber($creditorStreet);
        $qrBill->setCreditor(QrBill\DataGroup\Element\StructuredAddress::createWithStreet(
            $company->getName(),
            $creditorStreetParts['address'],
            $creditorStreetParts['buildingNumber'],
            $company->getPostCode(),
            $company->getCity(),
            $company->getCountry()
        ));

        $debtorStreet = $this->resolveStreetLine($customer);
        $debtorStreetParts = $this->extractBuildingNumber($debtorStreet);
        $qrBill->setUltimateDebtor(QrBill\DataGroup\Element\StructuredAddress::createWithStreet(
            $customer->getName(),
            $debtorStreetParts['address'],
            $debtorStreetParts['buildingNumber'],
            $customer->getPostCode(),
            $customer->getCity(),
            $customer->getCountry()
        ));

        if (strpos($paymentDetails, '/') !== false) {
            [$iban, $qrrId] = explode('/', $paymentDetails, 2);
            $qrBill->setPaymentReference(QrBill\DataGroup\Element\PaymentReference::create(
                QrBill\DataGroup\Element\PaymentReference::TYPE_QR,
                QrBill\Reference\QrPaymentReferenceGenerator::generate($qrrId, $cleanInvoiceNumber)
            ));
        } else {
            $iban = $paymentDetails;
            $qrBill->setPaymentReference(QrBill\DataGroup\Element\PaymentReference::create(
                QrBill\DataGroup\Element\PaymentReference::TYPE_SCOR,
                QrBill\Reference\RfCreditorReferenceGenerator::generate($cleanInvoiceNumber)
            ));
        }

        $qrBill->setCreditorInformation(QrBill\DataGroup\Element\CreditorInformation::create($iban));
        $qrBill->setPaymentAmountInformation(
            QrBill\DataGroup\Element\PaymentAmountInformation::create($customer->getCurrency(), $total)
        );

        try {
            $qrCode = $qrBill->getQrCode();
            $this->pendingQrCode = $qrCode;
            $this->pngPath = null;

            return [
                'qrCodeSvgBase64' => base64_encode($qrCode->getAsString(QrBill\QrCode\QrCode::FILE_FORMAT_SVG)),
                'qrReference' => $qrBill->getPaymentReference()->getReference(),
                'iban' => $iban,
            ];
        } catch (\Exception $e) {
            $messages = [];
            foreach ($qrBill->getViolations() as $violation) {
                $field = $violation->getPropertyPath() ?: 'UnknownField';
                $messages[] = $field . ': ' . $violation->getMessage();
            }

            throw new \RuntimeException($messages !== [] ? implode('; ', $messages) : $e->getMessage(), 0, $e);
        }
    }

    private function writePngTempFile(QrBill\QrCode\QrCode $qrCode): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kimai-swiss-qr-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temporary file for Swiss QR PNG');
        }

        $pngPath = $tmp . '.png';
        @unlink($tmp);
        $qrCode->writeFile($pngPath);

        // Ensure the file is removed eventually; Office renderers read it during the same request.
        register_shutdown_function(static function () use ($pngPath): void {
            if (is_file($pngPath)) {
                @unlink($pngPath);
            }
        });

        return $pngPath;
    }

    private function resolveStreetLine(Customer $party): string
    {
        foreach ([$party->getAddressLine3(), $party->getAddressLine2(), $party->getAddressLine1()] as $line) {
            if ($line !== null && trim($line) !== '') {
                return trim($line);
            }
        }

        throw new \InvalidArgumentException('Structured street address is missing for ' . $party->getName());
    }

    /**
     * @return array{address: string, buildingNumber: string}
     */
    private function extractBuildingNumber(?string $addressLine): array
    {
        $addressLine = trim((string) $addressLine);
        $buildingNumber = '';

        if (preg_match('/\s(\d+(?:-\d+)?[a-zA-Z]?)$/', $addressLine, $matches)) {
            $buildingNumber = $matches[1];
            $addressLine = preg_replace('/\s' . preg_quote($buildingNumber, '/') . '$/', '', $addressLine) ?? $addressLine;
        }

        return [
            'address' => $addressLine,
            'buildingNumber' => $buildingNumber,
        ];
    }
}
