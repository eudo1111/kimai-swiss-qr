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
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\QrCode\QrCode;
use App\Invoice\InvoiceModelHydrator;

use Exception;
class SwissQrService implements InvoiceModelHydrator
{

    public function __construct()
    {
    }

    /**
     * @param InvoiceModel $model
     * @return array
     */

     public function hydrate(InvoiceModel $model): array
     {
        $qrInfo = $this->generateQrCodeFromModel($model);
        return [
            'invoice.swiss_qr_reference' => $qrInfo['qrReference'],
            'invoice.swiss_qr_code' => $qrInfo['qrCode'],
            'template.payment_details' => $qrInfo['iban'],
        ];
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

    private function createQrCode(string $invoiceNumber, float $total, $customer, $template): array
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
            $country
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

        $qrrId = "";
        if (strpos($paymentDetails, '/') !== false) {
            $qrrId = explode('/', $paymentDetails)[1];
            $iban = explode('/', $paymentDetails)[0];
            $qrBill->setPaymentReference(PaymentReference::create(PaymentReference::TYPE_QR, QrPaymentReferenceGenerator::generate($qrrId, $cleanInvoiceNumber)));
        } else {
            $iban = $paymentDetails;
            $qrBill->setPaymentReference(PaymentReference::create(PaymentReference::TYPE_SCOR, RfCreditorReferenceGenerator::generate($cleanInvoiceNumber)));
        }
        $creditorInformation = CreditorInformation::create($iban);

        $qrBill->setCreditorInformation($creditorInformation);

        // Add payment information
        $qrBill->setPaymentAmountInformation(PaymentAmountInformation::create($customer->getCurrency(), $total));

        // Generate QR Code
        try {
            $qrCode = $qrBill->getQrCode();
            // Convert QrCode object to image data
            $qrInfo['qrCode'] = base64_encode($qrCode->getAsString());
            $qrInfo['qrReference'] = $qrBill->getPaymentReference()->getReference();
            $qrInfo['iban'] = $iban;
            return $qrInfo;
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

    private function parseAddress($address)
    {
        // 1. Initialize variables to their default empty state.
        $street = $buildingNumber = $postal = $city = '';
    
        // 2. If the input is empty, null, or just whitespace, return the empty set.
        if (empty(trim($address))) {
            return [$street, $buildingNumber, $postal, $city];
        }
    
        // 3. Split the address into lines and remove any blank lines.
        $allLines = preg_split('/\r?\n/', $address);
        $filteredLines = array_filter($allLines, 'trim');
        if (empty($filteredLines)) {
            return [$street, $buildingNumber, $postal, $city];
        }
        // Re-index the array to be contiguous
        $lines = array_values($filteredLines);
    
        // 4. Take only the last two lines for parsing.
        // array_slice handles cases where there are fewer than 2 lines gracefully.
        $addressLines = array_slice($lines, -2);
        $lineCount = count($addressLines);
    
        // 5. The last available line is always parsed for postal code and city.
        // This will be at index 0 if there's only one line, or index 1 if there are two.
        $lastLine = $addressLines[$lineCount - 1];
        if (preg_match('/(\d{4,6})\s*(.*)/', trim($lastLine), $matches)) {
            $postal = $matches[1];
            $city = trim($matches[2]);
        } else {
            // If no postal code is found, assume the whole line is the city.
            $city = trim($lastLine);
        }
    
        // 6. If there are at least two lines, parse the second-to-last for street info.
        if ($lineCount >= 2) {
            $streetLine = $addressLines[0];
            // This regex is more robust, capturing multi-word street names correctly.
            // It identifies the last "word" as the building number.
            if (preg_match('/^(.*?)\s+([\w\d\-\/]+)$/', trim($streetLine), $matches)) {
                $street = trim($matches[1]);
                $buildingNumber = trim($matches[2]);
            } else {
                // If the pattern doesn't match (e.g., just one word), assume it's the street.
                $street = trim($streetLine);
            }
        }
    
        // 7. Return the parsed components.
        return [$street, $buildingNumber, $postal, $city];
    }
}