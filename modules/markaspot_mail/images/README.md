# markaspot_mail assets

Mail assets referenced by `markaspot_mail.settings`.

## `mark-a-spot-logo@2x.png`

Default platform logo used in transactional mails sent under the `platform`
branding mode. The file shipped here is the existing Mark-a-Spot mark
(100x100, RGBA) reused from `modules/markaspot_blocks/images/logo-small.png`
so that every install has a working platform logo out of the box.

If you need to ship a higher-resolution or client-neutral logo for Stage 4
(OTP mails), drop the new PNG in this directory and point
`platform.logo_path` in `markaspot_mail.settings` at it. Keep it as an
absolute-URL reference (PNG only — the mail pipeline never inlines SVG).

Jurisdictions ship their own logo via `field_logo_light` on the `jur` group
entity; that path always wins over the platform default in `jurisdiction`
mode.
