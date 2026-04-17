# markaspot_mail scripts

Small operational helpers. Not loaded by Drupal at runtime.

## render-preview-samples.php

Re-renders the two Stage-1 mail preview files under `/tmp` using the real
`MailBrandingService` + `MailHtmlRenderer` services, and inlines the
platform logo as a `data:image/png;base64,...` URI so the previews render
the logo when opened via `file://` in a browser.

Run from DDEV (or any drush-capable environment):

```bash
ddev drush scr modules/contrib/markaspot/modules/markaspot_mail/scripts/render-preview-samples.php
```

Or, from a checkout where the module lives at a different path:

```bash
drush php:script <path-to-module>/scripts/render-preview-samples.php
```

Output files (overwritten in place):

- `/tmp/mail_hero_code.html` (OTP-style hero_code variant)
- `/tmp/mail_card_transactional.html` (card_transactional variant)

Open either file directly in a browser (`file:///tmp/...`) to preview.
