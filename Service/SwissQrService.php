<?php

namespace KimaiPlugin\SwissQrBundle\Service;

use App\Entity\Invoice;
use App\Invoice\InvoiceModel;
use App\Invoice\InvoiceModelHydrator;
use Sprain\SwissQrBill as QrBill;
use Exception;

require_once __DIR__.'/../vendor/autoload.php';

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
        $qrBill = QrBill\QrBill::create();

        $paymentDetails = $template->getPaymentDetails();
        $country = null;

        if (preg_match('/^[a-zA-Z]{2}/', $paymentDetails, $matches)) {
            $country = $matches[0];
        } else {
            throw new \InvalidArgumentException('Payment details is not a valid IBAN number');
        }

        // Parse creditor address
        $company = $template->getCustomer();
        $address = '';
        if (!empty($company->getAddressLine3())) {
            $address = $company->getAddressLine3();
        } elseif (!empty($company->getAddressLine2())) {
            $address = $company->getAddressLine2();
        } elseif (!empty($company->getAddressLine1())) {
            $address = $company->getAddressLine1();
        } else {
            throw new \InvalidArgumentException('Company address is missing');
        }
        $companyAddress = $this->extractBuildingNumber($address);
        $creditor = QrBill\DataGroup\Element\StructuredAddress::createWithStreet(
            $company->getName(),
            $companyAddress['address'],
            $companyAddress['buildingNumber'],
            $company->getPostCode(),
            $company->getCity(),
            $company->getCountry()
        );
        $qrBill->setCreditor($creditor);

        // Add debtor information
        $customerAddress = $this->extractBuildingNumber($customer->getAddressLine3());
        $debtor = QrBill\DataGroup\Element\StructuredAddress::createWithStreet(
            $customer->getName(),
            $customerAddress['address'],
            $customerAddress['buildingNumber'],
            $customer->getPostCode(),
            $customer->getCity(),
            $customer->getCountry()
        );
        $qrBill->setUltimateDebtor($debtor);

        $qrrId = "";
        if (strpos($paymentDetails, '/') !== false) {
            $qrrId = explode('/', $paymentDetails)[1];
            $iban = explode('/', $paymentDetails)[0];
            $qrBill->setPaymentReference(QrBill\DataGroup\Element\PaymentReference::create(QrBill\DataGroup\Element\PaymentReference::TYPE_QR, QrBill\Reference\QrPaymentReferenceGenerator::generate($qrrId, $cleanInvoiceNumber)));
        } else {
            $iban = $paymentDetails;
            $qrBill->setPaymentReference(QrBill\DataGroup\Element\PaymentReference::create(QrBill\DataGroup\Element\PaymentReference::TYPE_SCOR, QrBill\Reference\RfCreditorReferenceGenerator::generate($cleanInvoiceNumber)));
        }
        $creditorInformation = QrBill\DataGroup\Element\CreditorInformation::create($iban);

        $qrBill->setCreditorInformation($creditorInformation);

        // Add payment information
        $qrBill->setPaymentAmountInformation(QrBill\DataGroup\Element\PaymentAmountInformation::create($customer->getCurrency(), $total));

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

    private function extractBuildingNumber(string $addressLine = null): array
    {
        $addressLine = trim($addressLine);
        $buildingNumber = "";

        if (preg_match('/\s(\d+(?:-\d+)?[a-zA-Z]?)$/', $addressLine, $matches)) {
            $buildingNumber = $matches[1];
            // Remove the building number from the address line
            $addressLine = preg_replace('/\s' . preg_quote($buildingNumber, '/') . '$/', '', $addressLine);
        }

        return [
            'address' => $addressLine,
            'buildingNumber' => $buildingNumber
        ];
    }
}