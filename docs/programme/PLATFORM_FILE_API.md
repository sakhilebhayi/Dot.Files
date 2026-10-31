# Dot.Files platform file API (R-25 Phase 3, v1)

Dot.Files is the ecosystem's filing room: other Dot platforms store and fetch business files
here instead of keeping their own blobs. v1 is deliberately narrow and enforceable:

- **A platform touches only what it filed.** Every call is scoped to one company (by
  `infodot_team_uuid`, the shared company identity) AND to the caller's own files
  (`files.origin_platform` = the calling platform). Cross-platform reads are a later, owner-
  approved design — not a quiet default.
- **Server-to-server only, every call signed.** No tokens to leak into browsers, no public URLs.
- **The company keeps seeing everything.** Platform-filed documents land in the company's own
  workspace (in a folder named after the platform, with an origin chip), obey the same policies,
  previews, sharing approvals and trash as anything a person filed.

## Authentication

Per-platform shared secrets, one per caller, set on the Dot.Files host as
`DOT_SERVICE_SECRET_<KEY>` (key as in `config/ecosystem.php`, e.g. `DOT_SERVICE_SECRET_DOCS`)
and the same value on the calling platform. A platform with no secret configured cannot call.

Sign exactly like the InfoDot pattern. Headers on every request:

```
X-Dot-Platform:  docs
X-Dot-Timestamp: <unix seconds>
X-Dot-Signature: sha256=<hex hmac_sha256("{timestamp}.{raw json body}", secret)>
Content-Type:    application/json
```

Freshness tolerance is 300 s. The signature covers the raw body, so every endpoint is a POST
with a JSON body (one signing rule, nothing tamperable in the URL).

```php
$body = json_encode($payload);
$ts = time();
$sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret);
Http::withHeaders(['X-Dot-Platform' => 'docs', 'X-Dot-Timestamp' => $ts, 'X-Dot-Signature' => $sig])
    ->withBody($body, 'application/json')->post('https://files.infodot.co.za/api/platform/files/store');
```

## Endpoints

All under `/api/platform/files/*`, all JSON in and out. Errors: `{"error": "invalid_request"}`
401 (bad signature/platform), 422 (bad payload), `{"error": "not_found"}` 404 (wrong company,
not yours, trashed, or genuinely absent — indistinguishable by design).

### POST /api/platform/files/store  (throttle 60/min)
`{team_uuid, name, content_b64, mime?, folder?}` → files the document for that company under
`folder` (default: the platform's display name, auto-created at the root). Limits: 25 MB decoded,
same format allowlist as the workspace upload, names cleaned to a basename. Replies
`{uuid, name, size, mime, reference, open_url}` — `reference` is the InfoDot reference once the
pointer registers (null right after store), `open_url` the deep link people use (`/o/{uuid}`).

### POST /api/platform/files/update  (throttle 60/min)
`{team_uuid, uuid, content_b64, name?}` → saves new content for a file THIS platform filed, as a
NEW version (R-28 Part F): the old content stays in the file's append-only trail, the file row
moves forward, the snapshot regenerates. Replies the file's meta plus `{version, checksum}`.
Same caps and cleaning as store; a trashed file answers `not_found`.

### POST /api/platform/files/fetch  (throttle 120/min)
`{team_uuid, uuid}` → `{uuid, name, size, mime, content_b64}` for a file THIS platform filed for
THAT company. Trashed files answer `not_found`. Files over 25 MB answer 413 `{"error":"too_large"}`
(meta via `meta`).

### POST /api/platform/files/meta  (throttle 120/min)
`{team_uuid, uuid}` → `{uuid, name, size, mime, reference, open_url, filed_at}` without content.

### POST /api/platform/files/list  (throttle 120/min)
`{team_uuid, after_id?}` → `{files: [...meta...], next_after_id}` — the caller's own files for
that company, oldest first, 100 per page.

### POST /api/platform/files/trash  (throttle 60/min)
`{team_uuid, uuid}` → moves the caller's own file to the company's trash (30-day hold, owners
restore from the workspace). Platforms never delete permanently.

## Audit

Every store/fetch/trash lands in `platform_file_events` (platform, team, file uuid, event,
time). The company's people see platform-filed documents with a "filed by Dot.<Platform>"
origin chip in the workspace and on the document page.

## Not in v1 (by design, reported honestly)

Cross-platform reads (needs the owner-approval model), platform-initiated shares, moves/copies,
multipart/streaming uploads (>25 MB goes through the workspace UI), version history, webhooks
back to the platform. The consuming platforms also still need their client calls wired — this
document is the contract they wire against.
