<?php

namespace KimaiPlugin\SwissQrBundle\Service;

use App\Entity\Invoice;
use App\Invoice\InvoiceModel;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\Reference\RfCreditorReferenceGenerator;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\QrCode\QrCode;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Utils\FileHelper;

use Exception;

class SwissQrService
{
    private $params;
    private FileHelper $fileHelper;

    public function __construct(ParameterBagInterface $params, FileHelper $fileHelper)
    {
        $this->params = $params;
        $this->fileHelper = $fileHelper;
    }

    public function generateQrCode($invoice): string
    {
        if ($invoice instanceof InvoiceModel) {
            return $this->generateQrCodeFromModel($invoice);
        }
        
        if ($invoice instanceof Invoice) {
            return $this->generateQrCodeFromInvoice($invoice);
        }

        throw new \InvalidArgumentException('Invalid invoice type provided');
    }

    private function generateQrCodeFromModel(InvoiceModel $model): string
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

    private function generateQrCodeFromInvoice(Invoice $invoice): string
    {
        $customer = $invoice->getCustomer();
        $template = $customer->getInvoiceTemplate();

        return $this->createQrCode(
            $invoice->getInvoiceNumber(),
            $invoice->getTotal(),
            $customer,
            $template
        );
    }

    private function createQrCode(string $invoiceNumber, float $total, $customer, $template): string
    {

        // Check if there are any "/" in the invoice number
        if (strpos($invoiceNumber, '/') !== false) {
            throw new \InvalidArgumentException('There are invalid characters in your invoice number');
        }

        // Remove all "-" from the invoice number
        $cleanInvoiceNumber = str_replace('-', '', $invoiceNumber);
        // Create QR Bill
        $qrBill = QrBill::create();

        $paymentDetails = $template->getPaymentDetails();
        $country = null;

        if (preg_match('/^[a-zA-Z]{2}/', $paymentDetails, $matches)) {
            $country = $matches[0];
        } else {
            throw new \InvalidArgumentException('Payment details is not a valid IBAN number');
        }

        // Parse creditor address
        list($street, $buildingNumber, $postal, $city) = $this->parseAddress($template->getAddress());

        // Add creditor information
        $creditor = StructuredAddress::createWithStreet(
            $template->getCompany(),
            $street,
            $buildingNumber,
            $postal,
            $city,
            $$country
        );
        $qrBill->setCreditor($creditor);

        // Parse debtor address
        list($debtorStreet, $debtorBuildingNumber, $debtorPostal, $debtorCity) = $this->parseAddress($customer->getAddress());

        // Add debtor information
        $debtor = StructuredAddress::createWithStreet(
            $customer->getName(),
            $debtorStreet,
            $debtorBuildingNumber,
            $debtorPostal,
            $debtorCity,
            $customer->getCountry()
        );
        $qrBill->setUltimateDebtor($debtor);

        $besrId = "";
        if (strpos($paymentDetails, '/') !== false) {
            $besrId = explode('/', $paymentDetails)[1];
            $iban = explode('/', $paymentDetails)[0];
            $qrBill->setPaymentReference(PaymentReference::create(PaymentReference::TYPE_QR, QrPaymentReferenceGenerator::generate($besrId, $cleanInvoiceNumber)));
        } else {
            $iban = $paymentDetails;
            $qrBill->setPaymentReference(PaymentReference::create(PaymentReference::TYPE_SCOR, RfCreditorReferenceGenerator::generate($cleanInvoiceNumber)));
        }
        $creditorInformation = CreditorInformation::create($iban);

        $qrBill->setCreditorInformation($creditorInformation);

        // Add payment information
        $qrBill->setPaymentAmountInformation(PaymentAmountInformation::create($customer->getCurrency(), $total));
        $qrBill->setAdditionalInformation(AdditionalInformation::create($template->getPaymentTerms()));

        // Generate QR Code
        try {
            $qrCode = $qrBill->getQrCode();

            // Convert QrCode object to image data and then to base64 string
            $image = $qrCode->getAsString();

            // Generate a unique filename, e.g. using the invoice number
            $filename = $this->fileHelper->getDataDirectory('qrcodes') . FileHelper::convertToAsciiFilename($cleanInvoiceNumber);

            // Save the QR code image as a file
            $this->fileHelper->saveFile($filename . '.png', $image);

            return $qrBill->getPaymentReference()->getReference();
        } catch (\Exception $e) {
            $messages = [];
            foreach ($qrBill->getViolations() as $violation) {
                // Use the property path as the field name, fallback to 'UnknownField' if empty
                $field = $violation->getPropertyPath() ?: 'UnknownField';
                $messages[] = $field . ': ' . $violation->getMessage();
            }
            throw new \RuntimeException(implode('; ', $messages));
        }
    }

    /**
     * Parses an address string in the format:
     *   'StreetName BuildingNumber\nPostalCode City'
     * Returns array: [street, buildingNumber, postal, city]
     */
    private function parseAddress($address)
    {
        $street = $buildingNumber = $postal = $city = '';
        if (empty($address)) {
            return [$street, $buildingNumber, $postal, $city];
        }
        $lines = preg_split('/\r?\n/', trim($address));
        if (count($lines) >= 1) {
            $firstLineParts = preg_split('/\s+/', trim($lines[0]), 2);
            $street = $firstLineParts[0] ?? '';
            $buildingNumber = $firstLineParts[1] ?? '';
        }
        if (count($lines) >= 2) {
            if (preg_match('/(\d{4,6})\s*(.*)/', trim($lines[1]), $matches)) {
                $postal = $matches[1];
                $city = $matches[2];
            } else {
                $city = trim($lines[1]);
            }
        }
        return [$street, $buildingNumber, $postal, $city];
    }
} 