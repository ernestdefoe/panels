# Panels: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`).
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle.
- Publishing a release is Ernest's click. Every change goes to the Command Center for review first. New extension: dev + ernestdefoe.dev only until Ernest has tested it.
- Scope and Ernest's decisions: `~/github/command-center/scopes/panels.md`.

## Design rules that exist for a reason

- **One set of rules**: `Access` decides who sees, reads and edits what; every endpoint, image token, discussion scope and notification asks it. Explicit series don't exist for anyone not allowed them.
- **Two disks**: open chapters (out, All ages/Teen, free) are static files on `ernestdefoe-panels`; everything else is on `ernestdefoe-panels-private` behind 15-minute HMAC tokens tied to the member (`/panels/img/{token}`, no file extension: nginx would serve it). `Pages::relocate()` moves files whenever a chapter's rules change.
- **Uploads**: `Media\Pipeline` sniffs, checks pixels before decoding, re-encodes everything; `Media\Archive` never extracts, skips zip-slip/symlinks/junk, refuses bombs. Unit tests in `tests/unit` cover each attack.
- **Positions** are taken under a lock on the chapter row: the editor uploads three images at once.
- **Discussions**: plain-text first post, no page images (other extensions read posts directly).
- **Query budgets** (measured, `~/github/scopes/panels/measure.php`): library 3/6, series page 6/8, reader 6/7 (guest/member), the same for 1 or 24 rows.
- Flarum 2 has no `icon()`/`avatar()` helpers and no Laravel `now()`: use `util.icon`, `Avatar`, `Carbon::now()`.

## Testing

- `composer test:setup && composer test` (SQLite), then `~/github/scopes/panels/dbtests.sh` (MySQL `dev_` + PostgreSQL).
- Local forum `~/Herd/panelsdev` (MySQL `pd_`), seed `php ~/github/scopes/panels/seed.php`, shots `node ~/github/scopes/panels/shots.mjs`, upload flow `node flow.mjs`.
