# markaspot_mail — Translation workflow

This directory ships the `.pot` template with all source strings from
`markaspot_mail` builders + the `mail-layout.html.twig` template, plus
one `.po` file per locale supported by the Mark-a-Spot frontend.

## Status

| File              | Scope               | Status                        |
|-------------------|---------------------|-------------------------------|
| `markaspot_mail.pot` | Source strings (EN) | 70 messages, kept in sync by hand until `drush locale:check` is wired up |
| `de.po`              | German (primary)    | Fully translated (first-mover, Civic Patches in-house) |
| `cs.po`              | Czech               | Custom-term mail path translated; broader catalog is partial |
| `ar.po`              | Arabic              | Custom-term mail path translated; broader catalog is partial |
| `da.po`              | Danish              | Custom-term mail path translated; broader catalog is partial |
| `de-ls.po`           | German Leichte Sprache | Custom-term mail path translated in plain language; broader catalog is partial |
| `es.po`              | Spanish             | Custom-term mail path translated; broader catalog is partial |
| `fi.po`              | Finnish             | Custom-term mail path translated; broader catalog is partial |
| `fr.po`              | French              | Custom-term mail path translated; broader catalog is partial |
| `hu.po`              | Hungarian           | Custom-term mail path translated; broader catalog is partial |
| `it.po`              | Italian             | Custom-term mail path translated; broader catalog is partial |
| `nb.po`              | Norwegian Bokmål    | Custom-term mail path translated; broader catalog is partial |
| `nl.po`              | Dutch               | Custom-term mail path translated; broader catalog is partial |
| `pl.po`              | Polish              | Custom-term mail path translated; broader catalog is partial |
| `pt.po`              | Portuguese          | Custom-term mail path translated; broader catalog is partial |
| `sv.po`              | Swedish             | Custom-term mail path translated; broader catalog is partial |
| `tr.po`              | Turkish             | Custom-term mail path translated; broader catalog is partial |
| `uk.po`              | Ukrainian           | Custom-term mail path translated; broader catalog is partial |

## How Drupal picks these up

On module install, Drupal's locale system auto-imports `.po` files from
this directory if the corresponding language is enabled on the site.
The imports land in `locales_source` + `locales_target` tables and
become available via `$this->t()` → `\Drupal::translation()->translate()`
whenever the recipient's langcode matches.

No manual import step needed. Enabling a new language on a live site
runs the import automatically. Extending a shipped `.po` for existing
tenants needs a module update hook that calls the shared importer, as
`markaspot_mail_update_10009()` does for the runtime wording sources.

## Translator workflow

For each stub `.po` file:

1. Fill every `msgstr ""` with the translated string. Keep `@placeholder`
   tokens EXACTLY — they're substituted at runtime by the builder.
2. Preserve quote conventions per locale (German uses „ und " by
   convention; French uses « und »; English uses " und ").
3. For OTP / time-sensitive copy, keep wording concise — recipients read
   these on mobile under stress.
4. Do not translate these "msgstr" literal strings:
   - `"Impressum"` and `"Datenschutz"` in the template strings — these
     are German legal terms used as link labels in the bottom footer
     regardless of locale, and the operator's actual legal pages may not
     have locale-specific URLs.

## Context notes for tricky strings

- **"Report"** refers to a civic/municipal report (citizen report of a
  broken street light, graffiti, etc.), not a document or analytics
  report. In DE = "Meldung", in NL = "melding", in FR = "signalement".

- **"Workspace"** refers to the CivicSpot SaaS tenancy concept (a
  jurisdiction-scoped container). In DE = "Arbeitsbereich", in FR =
  "espace de travail", in IT = "spazio di lavoro".

- **"Verify your account"** is the hero headline above the OTP code.
  Short and direct; avoid corporate phrasing.

- **"@platform_name: Your verification code"** is a mail-subject
  template. `@platform_name` will substitute to the jurisdiction's
  configured platform_name or the CivicSpot brand name. Keep the colon
  separator — it's how RFC 5322-style subject prefixes read in most
  inbox clients.

- **"Thank you for using the issue tracker. Your report ..."** reads
  slightly formal in English; DE translation uses the standard "Sie"
  register consistent with German municipal correspondence.

## Runtime citizen-wording placeholders

The shipped `markaspot_mail.texts` notification templates use these explicit
runtime values when a jurisdiction selects a different citizen term:

- `{{ citizen_term_singular }}` and `{{ citizen_term_singular_title }}`
- `{{ citizen_term_plural }}` and `{{ citizen_term_plural_title }}`

`NotificationTextBuilder` and its plaintext `hook_mail()` fallback replace
them from the jurisdiction's `i18n.wording` setting before Drupal resolves
`[node:*]` tokens. The update hook changes only active templates that exactly
match the prior shipped EN or DE defaults. Existing operator-edited templates
and ECA copy are never rewritten, but an operator can add one of these
placeholders deliberately to a custom notification template.

## Config-level translations (separate from this directory)

The subject + body templates for individual mails live in consumer
module config, NOT in this translations directory. Per-locale overrides
go to:

```
modules/markaspot_feedback/config/install/language/<langcode>/markaspot_feedback.mail.yml
modules/markaspot_passwordless/config/install/language/<langcode>/markaspot_passwordless.mail.yml
modules/markaspot_fastmap/config/install/language/<langcode>/markaspot_fastmap.mail.yml
modules/markaspot_resubmission/config/install/language/<langcode>/markaspot_resubmission.mail.yml
modules/markaspot_group/config/install/language/<langcode>/markaspot_group.mail.yml
```

### Config-locale gap as of 2026-04-17

| Module                  | Locales shipped today                         | Missing (of 17 MaS locales)                                    |
|-------------------------|-----------------------------------------------|----------------------------------------------------------------|
| markaspot_passwordless  | ar, cs, da, de, es, fi, fr, it, nb, nl, pl, pt, sv, tr, uk | de-ls, hu                                      |
| markaspot_fastmap       | ar, cs, da, de, es, fr, it, nl, pl, pt-br, tr, uk | de-ls, fi, hu, nb, sv                                      |
| markaspot_feedback      | de                                            | ar, da, de-ls, es, fi, fr, hu, it, nb, nl, pl, pt, sv, tr, uk  |
| markaspot_resubmission  | de                                            | ar, da, de-ls, es, fi, fr, hu, it, nb, nl, pl, pt, sv, tr, uk  |
| markaspot_group         | de                                            | ar, da, de-ls, es, fi, fr, hu, it, nb, nl, pl, pt, sv, tr, uk  |

Closing these gaps is out of scope for markaspot_mail (they belong to
the consumer modules), but the workflow is the same: copy the EN
`*.mail.yml` into `language/<langcode>/*.mail.yml` and translate the
`subject` + `body` fields while preserving `@placeholder` tokens and
`[node:*]` Drupal tokens unchanged.

## Verification

After filling in a new locale:

```bash
# In the worktree root:
drush cr
drush locale:check
drush locale:update

# Render a preview in the target locale:
drush scr modules/contrib/markaspot/modules/markaspot_mail/scripts/render-preview-samples.php
# (Override $langcode in the script or via hook_language_types_info_alter.)
```

## Not yet wired up

- Automated `.pot` regeneration from PHP / Twig source. For now
  `markaspot_mail.pot` is hand-maintained; whenever a builder adds a
  new `$this->t(...)` source string, update the `.pot` in the same
  commit.
- localize.drupal.org community-translation push. This module is
  profile-internal, not a published contrib module; community
  translation would require the module to be opened up first.
