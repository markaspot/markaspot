# markaspot_mail — Translation workflow

This directory ships the `.pot` template with all source strings from
`markaspot_mail` builders + the `mail-layout.html.twig` template, plus
one `.po` file per locale supported by the Mark-a-Spot frontend.

## Status

| File              | Scope               | Status                        |
|-------------------|---------------------|-------------------------------|
| `markaspot_mail.pot` | Source strings (EN) | 44 messages, kept in sync by hand until `drush locale:check` is wired up |
| `de.po`              | German (primary)    | Fully translated (first-mover, Civic Patches in-house) |
| `cs.po`              | Czech               | Fully translated |
| `ar.po`              | Arabic              | Stub — msgstrs empty |
| `da.po`              | Danish              | Stub — msgstrs empty |
| `de-ls.po`           | German Leichte Sprache | Stub — msgstrs empty (sensitive register, recommend human translator) |
| `es.po`              | Spanish             | Stub — msgstrs empty |
| `fi.po`              | Finnish             | Stub — msgstrs empty |
| `fr.po`              | French              | Stub — msgstrs empty |
| `hu.po`              | Hungarian           | Stub — msgstrs empty |
| `it.po`              | Italian             | Stub — msgstrs empty |
| `nb.po`              | Norwegian Bokmål    | Stub — msgstrs empty |
| `nl.po`              | Dutch               | Stub — msgstrs empty |
| `pl.po`              | Polish              | Stub — msgstrs empty |
| `pt.po`              | Portuguese          | Stub — msgstrs empty |
| `sv.po`              | Swedish             | Stub — msgstrs empty |
| `tr.po`              | Turkish             | Stub — msgstrs empty |
| `uk.po`              | Ukrainian           | Stub — msgstrs empty |

## How Drupal picks these up

On module install, Drupal's locale system auto-imports `.po` files from
this directory if the corresponding language is enabled on the site.
The imports land in `locales_source` + `locales_target` tables and
become available via `$this->t()` → `\Drupal::translation()->translate()`
whenever the recipient's langcode matches.

No manual import step needed. Enabling a new language on a live site
runs the import automatically; extending a shipped `.po` later requires
`drush locale:check` + `drush locale:update` or a module reinstall.

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
