# SwissQrBundle for Kimai

SwissQrBundle is a Kimai plugin that enables automatic generation and embedding
of Swiss QR-bill payment references for invoices, using the
[sprain/swiss-qr-bill](https://github.com/sprain/php-swiss-qr-bill) library.
Its either compatible with a normal IBAN or a QR-IBAN.

## Features

* Generates a QR-Code as base64 SVG `invoice.swiss_qr_code` (HTML/Twig templates)
* Embeds a temporary PNG for Word / Excel / ODS via the `${invoice.swiss_qr_code_png}` placeholder (path is never exposed to Twig/`toArray()`)
* Generates the corresponding QR-reference `invoice.swiss_qr_reference`

## Requirements

* Kimai >= 2.41
* PHP >= 8.1
* [sprain/swiss-qr-bill](https://github.com/sprain/php-swiss-qr-bill)

## Installation

1. **Copy the plugin**

```bash
cd var/plugins/
git clone https://github.com/eudo1111/kimai-swiss-qr.git SwissQrBundle
```

2. **Install dependencies**

Run `composer install` inside the `SwissQrBundle` directory if not already done.

```bash
cd SwissQrBundle
composer install
```

3. **Clear the cache**

From your Kimai root directory, run:

```bash
bin/console kimai:reload
```

## Usage

1. It takes the last not-empty line of the three address-lines as the street.
2. The field **PaymentDetails** in your invoice template must be your IBAN:

    * Normal IBAN: Enter you normal IBAN and the qr-reference will start with "RF..."
    * QR-IBAN & QRR-ID: Enter your qr-iban/qrr-id and the qr-reference will be a 27 digits string

        ```text
        CHXX XXXX XXXX XXXX XXXX X/000000
        ```

    * The qrr-id is an additional "reference-code" which must be used in
combination with the qr-iban.
    * The qr-iban is a special iban provided by your bank.

3. To display the QR code, use the following code in your invoice template

**HTML / TWIG**

```html
<div class="ch_qrcode">
  <img src="data:image/svg+xml;base64,{{ invoice['invoice.swiss_qr_code'] }}" alt="Swiss QR Code"/>
</div>
```

**Word (DOCX)** – put this placeholder in the document (optionally with size):

```text
${invoice.swiss_qr_code_png}
${invoice.swiss_qr_code_png:46mm:46mm}
```

**Excel / ODS** – put `${invoice.swiss_qr_code_png}` in a cell. The plugin replaces
   it with an embedded PNG drawing. The filesystem path is never available as a
   Twig/`toArray()` variable (HTML templates must use `invoice.swiss_qr_code`).

4. QR reference: `${invoice.swiss_qr_reference}` / `invoice['invoice.swiss_qr_reference']`

5. Sample HTML template is shipped under `invoice-templates/`:

```bash
cp invoice-templates/swiss-iban.html.twig ../../invoices/
bin/console kimai:reload
```

## Credits

* [sprain/swiss-qr-bill](https://github.com/sprain/php-swiss-qr-bill)
* [HansPaulHansen/swissqr](https://github.com/HansPaulHansen/swissqr)

## License

MIT
