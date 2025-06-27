# SwissQrBundle for Kimai

SwissQrBundle is a Kimai plugin that enables automatic generation and embedding of Swiss QR-bill payment references for invoices, using the [sprain/swiss-qr-bill](https://github.com/sprain/php-swiss-qr-bill) library.

## Features
- Generates QR pictures stored under var/data/qrcodes
- Provides the QR pictures as an endpoint under https://{your-kimai-instance}/qrcodes/{invoice-number}.png
- Stores the QR reference as invoice meta data

## Requirements
- Kimai >= 2.17
- PHP >= 8.0
- [sprain/swiss-qr-bill](https://github.com/sprain/php-swiss-qr-bill)

## Installation
1. **Copy the plugin**
   - Place the `SwissQrBundle` folder into your Kimai installation at `var/plugins/SwissQrBundle`.

2. **Install dependencies**
   - Run `composer install` inside the `SwissQrBundle` directory if not already done.

3. **Clear the cache**
   - From your Kimai root directory, run:
     ```
     bin/console kimai:reload
     ```

4. **Set permissions**
   - Ensure the web server user can write to `var/data/qrcodes` (for storing QR code images).

## Usage
- When you preview, create or update an invoice, the plugin will automatically generate a Swiss QR code and reference and store it as meta data.
- The QR code image is saved in `var/data/qrcodes` and can be served via the `/qrcodes/{filename}` endpoint (requires authentication).
- To display the QR reference or QR code in your invoice template, use the meta field or the provided option (e.g., `{{ invoice.meta.qr_reference }}`).
- The field PaymentDetails in your invoice template form must be your IBAN (with or without dashes)
- Address of your company and your customer must be set in the following format: Examplestreet 1 1234 City

## Credits
- [sprain/swiss-qr-bill](https://github.com/sprain/php-swiss-qr-bill)
- [HansPaulHansen/swissqr](https://github.com/HansPaulHansen/swissqr)

## License
MIT 