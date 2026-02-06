# Nara Webhook Handler (WordPress Plugin)

Receive Tally.so form submissions via webhooks, format all field types cleanly, and email admins with a readable, styled HTML email.

This plugin is designed for feedback / internal notification use cases and supports all Tally field types, including files, matrices, rankings, payments, hidden fields, and calculated fields.

---

## Features

- WordPress REST API endpoint for Tally webhooks
- Signature verification (Tally-Signature)
- Handles all Tally field types:
  - Text, number, email, phone, link
  - Long text (textarea)
  - Multiple choice, checkboxes, dropdown, multi-select
  - Ranking, rating, linear scale
  - File upload & signature
  - Matrix (rows / columns)
  - Payment fields
  - Hidden & calculated fields
- Clean, readable HTML email layout (email-client safe)
- Admin settings page
- Test email button
- Debug logging (incoming + outgoing)
- Easy Postman testing

---

## Requirements

- WordPress 5.8+
- PHP 8.0+
- A working mail setup (recommended: SMTP plugin like WP Mail SMTP)

---

## Installation1
1. Download the zip and unzip the folder
2. Copy and paste the plugin folder to :

    `wp-content/plugins/`

3. Activate plugin in WordPress Admin → Plugins

---

## Webhook Endpoint

After activation, your webhook endpoint is:

https://nara.com/wp-json/nara/tally/v1/webhook

Copy this URL into:

Tally → Integrations → Webhooks

---

## Admin Settings

WordPress Admin → Settings → Tally Webhook

### Available settings

Admin emails  
Tally signing secret  
Require signature  
Debug logging  
Send test email  
Clear logs  

---

## Signature Verification

Tally sends a header:

Tally-Signature

Computed as:

base64( HMAC_SHA256( raw_request_body, signing_secret ) )

The plugin recomputes this value and compares it using hash_equals.

---

## Debug Logging

When enabled, logs are written to:

wp-content/uploads/tally-webhook-emailer/

incoming.log  
outgoing.log  

Logs may contain personal data. Enable only during testing.

---

## Testing with Postman

Endpoint:

POST https://nara.com/wp-json/nara/tally/v1/webhook

Header:

Content-Type: application/json

If signature is required:

Tally-Signature: <computed value>

---

### Postman Pre-request Script

const secret = pm.environment.get("tally_secret");
const rawBody = pm.request.body.raw;

const signature = CryptoJS.enc.Base64.stringify(
  CryptoJS.HmacSHA256(rawBody, secret)
);

pm.request.headers.upsert({
  key: "Tally-Signature",
  value: signature
});

---

## Date & Time Formatting

Dates and times are converted using WordPress settings:

Settings → General → Date Format / Time Format

---

## Editing / Extending

Main methods:

handle_webhook()  
build_email() 
format_field_value_html()  
verify_tally_signature()  
log_write()  

---

## License

MIT License

---

Happy shipping.
