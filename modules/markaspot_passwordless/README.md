# Personal dashboard form layout

`GET|PATCH /api/auth/preferences/form-layout/{jurisdiction_id}` stores a
personal arrangement of whole dashboard form sections. This endpoint changes
no form display, field value, visibility rule or access grant.

The account comes exclusively from the authenticated Drupal cookie session.
The jurisdiction must resolve to a published jurisdiction group readable by
that account. PATCH additionally requires the standard `X-CSRF-Token` header.
Responses use `Cache-Control: private, no-store`. CivicSpot workspace demo
accounts (`DemoStaffAccount`) get the default layout and cannot save a
preference. Other shared logins share one layout per account.

GET returns `{ "layout": null, "writable": true }` when no override exists.
PATCH accepts exactly one `layout` property:

```json
{
  "layout": {
    "version": 1,
    "left": ["details", "location", "media", "contact", "status_history"],
    "right": ["status_actions", "context", "service_provider", "internal", "related"]
  }
}
```

Each stable section ID must appear exactly once. Unknown keys, versions,
missing/duplicate IDs and bodies larger than 2 KiB are rejected. To restore
the shared default, PATCH `{ "layout": null }`.

Storage uses core `user.data`, module `markaspot_passwordless`, name
`form_layout.{resolved_jurisdiction_id}` and the current UID. No field schema,
update hook or user-entity save is needed. The existing language-preference
endpoint remains unchanged. A regular cache rebuild discovers the new route.

This feature belongs to the active Drupal 11 line. It has no Drupal 10
backport.
