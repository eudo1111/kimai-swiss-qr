# SwissQrBundle for Kimai

SwissQrBundle is a Kimai plugin that enables automatic generation and embedding
of Swiss QR-bill payment references for invoices, using the
[sprain/swiss-qr-bill](https://github.com/sprain/php-swiss-qr-bill) library.
Its either compatible with a normal IBAN or a QR-IBAN.

## Features

* Generates a QR-Code as base64 `invoice.swiss_qr_code`
* Generates the coresponding QR-reference `invoice.swiss_qr_reference`

## Requirements

* Kimai >= 2.17
* PHP >= 8.0
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

1. As kimai has no structured address, please make sure that your and the
customers address last two lines are like this:

```
Examplestreet 1
1234 City
```

2. The field PaymentDetails in your invoice-template-form must be your IBAN:

    * Normal IBAN: Enter you normal IBAN and the qr-reference will start with "RF..."
    * QR-IBAN & QRR-ID: Enter your qr-iban/qrr-id and the qr-reference
will be a 27 digits string

        ```
        CHXX XXXX XXXX XXXX XXXX X/000000
        ```

    * The qrr-id is an additional "reference-code" which must be used in
combination with the qr-iban.
    * The qr-iban is a special iban provided by your bank.

3. To display the QR code, use the following code in your invoice template

```html
  <div class="ch_qrcode">
    <img src="data:image/svg+xml;base64,{{ invoice['invoice.swiss_qr_code'] }}" alt="Swiss QR Code"/>
  </div>
```

4. To display the QR reference: `invoice['invoice.swiss_qr_reference']`
5. Or use the template under `invoice\qr-template.html.twig` and copy it
to the kimai invoice folder: `var/invoices`

## Credits

* [sprain/swiss-qr-bill](https://github.com/sprain/php-swiss-qr-bill)
* [HansPaulHansen/swissqr](https://github.com/HansPaulHansen/swissqr)

## License

MIT
