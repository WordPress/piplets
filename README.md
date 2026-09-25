# piplet

piplet is a small, self-modifying wiki. Its PHP, HTML, CSS, JavaScript, and every note live in one deployable file. There is no database, package install, build step, or network dependency.

```text
wiki-piplet.php
├── PHP persistence and HTTP API
├── HTML, editable CSS, and browser UI
├── __halt_compiler();
├── PIPLET-DATA/2
└── { generation, versioned JSON notes, and appearance }
```

## Demos

### Full version

https://github.com/user-attachments/assets/87a2f715-31d8-478a-8dbe-cc641d7865c0

### Unsafe version

https://github.com/user-attachments/assets/267b7f6f-9739-47da-a813-a336d66a69fc

## Run it

64-bit PHP 8.1 or newer is required. Every HTTP request requires a password, including requests from loopback. For local development, initialize a persistent private document root once. The guard deliberately refuses to overwrite an existing notebook:

```sh
install -d -m 700 /absolute/private/piplet-local &&
test ! -e /absolute/private/piplet-local/index.php &&
install -m 600 wiki-piplet.php /absolute/private/piplet-local/index.php
```

Start and restart it separately; never repeat the install command over the stateful copy:

```sh
PIPLET_PASSWORD='choose-a-long-random-password' \
  php -S 127.0.0.1:8080 -t /absolute/private/piplet-local \
  /absolute/private/piplet-local/index.php
```

Then open `http://127.0.0.1:8080/`. Do not serve the repository: tests, backups, benchmark files, and temporary artifacts do not belong in the document root.

For a real deployment, use a dedicated HTTPS origin containing only `index.php`. Configure `PIPLET_PASSWORD` in the PHP worker environment, deny every other path and dotfile at the web server, and make the backend unreachable except through the TLS proxy. The PHP process must be able to read the file and write its containing directory because a save creates a private temporary snapshot beside the file and atomically renames it into place.

If TLS terminates at a trusted proxy, set `PIPLET_PUBLIC_HTTPS=1` so CSRF cookies are marked `Secure`. piplet deliberately ignores `Forwarded` and `X-Forwarded-*`; accepting those from clients would let them choose the cookie policy. Never set the flag for a publicly reachable plain-HTTP origin.

```sh
PIPLET_PASSWORD='choose-a-long-random-password' \
PIPLET_PUBLIC_HTTPS=1 \
  php-fpm
```

Authentication is HTTP Basic, so TLS is mandatory whenever traffic can leave the machine. piplet is intended for one person or a small trusted group; it has no accounts, roles, audit log, or merge editor. Put no other application or user-controlled content on its origin: same-origin access would also grant access to piplet's authenticated API and browser storage.

## Use it

- `New note` creates an in-place editor.
- `Ctrl/⌘ S` saves; `Esc` cancels; `/` searches; `N` creates; `E` edits the first open note.
- Drafts whose serialized recovery record is at most 524,288 JavaScript string units survive an accidental navigation in `sessionStorage`, subject to the browser's own quota. Each draft receives an immutable random physical key; legacy recovery records are copied to that format before editing, and recovery records cannot name other keys to delete. Discovery inspects and accounts for at most 2,048 keys and 2 MiB of recovery text. On reload, piplet opens an available recovery before showing saved content. Only an explicit save changes the PHP file. If recovery storage is full or a draft is larger, piplet keeps that editor open and asks you to save before switching. A later read-only launch exposes an available browser recovery draft, including exact title and tag JSON, for copying.
- Note bodies support headings, lists, block quotes, fenced code, `**bold**`, inline backticks, and `[[label|note-id]]` links.
- Every open note shows a `Linked from` row of the notes whose bodies link to it, newest first. Both `[[title]]` and `[[label|note-id]]` targets count; links inside fenced or inline code do not, and a note never lists itself. The row shows at most 20 linking notes and summarizes the rest.
- `Appearance` previews and saves a coordinated palette, reading font, text size, line length, and an optional custom stylesheet.
- `Download a snapshot` exports the current file. Keep it as a backup, but restore its data through the trusted current executable rather than replacing a live deployment with old code.

Two editors can safely change different notes. The document has a generation, and every note and appearance record has a random version plus a display revision. A stale, deleted/recreated, forked, or rekeyed record returns HTTP 409 with explicit recovery choices. New-note requests carry a stable creation token, so retrying after a lost response does not create a second slug while the original note exists.

## Security boundary

The safe version is designed to protect note integrity against hostile HTTP input, stale clients, ordinary process crashes, and stored markup. It does not protect against an attacker who can replace `index.php`, write its directory, read the configured password, control the TLS proxy, or execute code as the PHP user.

Before exposing it beyond loopback, verify all of these:

- The deployment has its own origin and private document root. Only the canonical `index.php` route is public; dotfiles, `.piplet-tmp-*`, tests, source-control metadata, snapshots, and backups are denied or stored elsewhere.
- The directory is owned by the deployment account and normally mode `0700` (or a reviewed `0750` group setup); the file is normally `0600` (or reviewed `0640`). No untrusted local user can write the directory or create hard links to the file.
- TLS is enforced before credentials are sent. The backend listens on a private socket/address, the proxy strips client forwarding headers, and `PIPLET_PUBLIC_HTTPS=1` is set only when every public request is HTTPS.
- The proxy accepts at most 5 MiB request bodies, a 4 KiB request target, at most 64 headers, and at most 8 KiB per header/32 KiB in aggregate. It rejects duplicate or conflicting `Host`, `Content-Length`, `Transfer-Encoding`, and `Authorization` fields; rejects `Content-Length` plus `Transfer-Encoding`; does not decompress request bodies; enforces header/body deadlines and the same limit for fixed and chunked bodies; forwards one normalized `Authorization` value; rate-limits failed authentication and successful reads/downloads; rejects cross-origin browser subresources using Fetch Metadata; and uses a bounded request/writer queue. piplet applies the same Fetch Metadata isolation when those browser headers are present, but the proxy is the cheaper enforcement point. PHP uses `memory_limit=128M` or higher and provides `flock`, `fsync`, atomic same-filesystem `rename`, and a local POSIX filesystem.
- Backups are private and tested. OPcache validates timestamps, or is explicitly invalidated when application code is deployed. NFS/SMB, multiple hosts, serverless filesystems, and online raw-file replacement are unsupported.

The response CSP blocks remote scripts, frames, workers, styles, fonts, and images except inline nonce-authorized application code and `data:` images. Note and boot data cross into the DOM as text/base64 rather than HTML. Custom CSS remains deliberately powerful: it can hide or restyle the interface, but CSP prevents it from becoming script or loading remote assets.

### Check, restore, and rekey

Run the trusted current executable for integrity checks and restores:

```sh
php /srv/piplet/index.php --check
php /srv/piplet/index.php --rekey
php /srv/piplet/index.php \
  --import-snapshot-data /offline/backups/piplet-2026-08-18.php \
  --rekey
```

For an import, stop or drain the proxy and PHP workers first. Save the current target and its hash, run the command above, run `--check`, verify owner/mode, restart PHP or invalidate OPcache, then test a read and save before reopening traffic. The current executable reads the backup as bounded data—it never includes or executes its PHP prefix—and can replace a corrupt old trailer while retaining the running trusted prefix. Rekey rotates the document generation and every record version and resets display revisions to a safe baseline. Raw overwrite while serving is unsupported and can both lose concurrent work and restore an old executable vulnerability.

Rekey after an intentional restore or suspected snapshot fork. Generation/version checks detect divergent histories, delete/recreate ABA, and most rollback cases. They cannot detect an exact rollback to the same generation and versions a client already loaded; that requires trusted state outside this one file, such as an append-only log or database.

## Change the appearance

Choose `Appearance` in the top bar. Every choice previews across the whole page immediately; `Cancel` restores the saved look, while `Restore defaults` previews the original design until you save. Palette, reading font, text size, and line length are stored in the PHP file so they travel with a downloaded snapshot. System/light/dark is kept on the current device and never rewrites the file by itself.

The advanced **Custom CSS** section is a complete override stylesheet, not a variables-only form. It accepts selectors, declarations, media queries, and custom properties up to 32 KiB. It comes after the built-in stylesheet, so it can restyle any part of the interface without copying the base theme.

Custom CSS can also hide or rearrange the editor. Add `?safe=1` to the page URL to load piplet with the stored stylesheet disabled; the CSS remains available in Appearance so it can be fixed or cleared. piplet assigns the stylesheet through a text node rather than HTML, and its Content Security Policy blocks remote styles, fonts, and image URLs. Scripts in CSS text do not become executable markup.

There are no remote fonts, icons, images, or CSS frameworks. The default theme is an editorial notebook rather than a generic dashboard: warm paper, ink, deep teal, serif reading type, hairline-separated notes, and a responsive story river.

The built-in presets are coordinated and contrast-aware; custom CSS is deliberately unconstrained and can override them. Manual source edits are also supported: keep the `__halt_compiler()` call and everything beneath it in place, and deploy source changes while no save is in flight. Stored appearance records tolerate newly added or retired settings and fall back to current defaults; older layout-token records are converted to equivalent CSS. After PHP recompiles the edited prefix, later saves preserve that new prefix exactly. If OPcache timestamp validation is disabled, invalidate OPcache once after changing application code. Ordinary saves do not require invalidation because the compiled prefix never changes.

## How a save works

1. Open this PHP file and attempt an exclusive advisory lock without blocking indefinitely. All inode retries share a two-second monotonic deadline; contention returns `503` with `Retry-After: 1`.
2. Compare the open descriptor's device/inode with the path's current device/inode. If another save replaced it while this process waited, retry on the current file.
3. Read the marked JSON trailer while holding the live-file lock. Bounded structural, duplicate-member, and lossless-number scans run before `json_decode`, followed by schema, cardinality, UTF-8, revision, generation, and record-version validation.
4. Check the supplied generation, revision, and record version, then apply one mutation. Format-1 data receives deterministic virtual identities on read and is materialized as format 2 on its first successful mutation.
5. Calculate the exact encoded size before creating a temporary file. Write the unchanged code prefix, format-2 marker, JSON, and newline in 64 KiB chunks to a private same-directory `.php` file; flush, `fsync`, preserve mode bits, and `fsync` again. Saving is disabled if PHP does not provide `fsync`.
6. Revalidate the target inode and atomically `rename()` the temporary snapshot over the original.

The inode retry is the small but important detail that safely updates one canonical filesystem path without a permanent lock sidecar. A naïve `flock(__FILE__)` is incorrect after `rename()`: a waiting writer may acquire a lock on the old, unlinked inode and overwrite a newer save.

Stored note text is never evaluated as PHP and is added to the page with DOM text nodes. Browser boot state is base64 inside an inert element, so attacker-controlled data cannot form an HTML end tag. Mutations require an exact JSON object plus a same-origin header matching a path-scoped, HttpOnly, `SameSite=Lax` CSRF cookie; the Lax policy also keeps a top-level link from rotating the token in an already-open editor. JSON responses are `no-store` and `nosniff`.

## Limits

- Supported deployment: 64-bit PHP 8.1+ on a local POSIX filesystem. NFS/SMB, multi-host writers, serverless/immutable filesystems, hard-linked aliases, and Windows are unsupported.
- The whole file is read and replaced on every save. The configured file ceiling is 8 MiB; request JSON is capped at 5 MiB, stored notes at 2,000, and tag references at 24,000. Structural/depth limits apply before JSON decoding.
- Rendering is bounded: the story keeps at most 20 open notes, rich rendering has per-note and aggregate character/node budgets, and the index shows the newest 40 matches. Search still reaches older notes.
- The containing directory is briefly home to one high-entropy temporary `.php` file during a save. It starts at mode `0600`, is removed on handled failure, and is renamed on success. A hard process kill at any point after temporary-file creation can leave a hidden orphan; after verifying the canonical piplet, that orphan can be deleted.
- Atomic rename prevents torn canonical reads and file `fsync` protects the temporary contents, but portable PHP cannot `fsync` the containing directory. A sudden power loss can lose the latest rename even though ordinary process crashes leave the canonical path as a complete old or new file.
- Atomic replacement can change ownership to the PHP process and does not preserve ACLs or extended attributes. Ordinary permission bits are preserved.
- Advisory locks coordinate only cooperating processes using the same local inode. A local administrator, deployment tool, backup restore, or other program can bypass them.
- Browser recovery is best-effort. Quota, privacy settings, crashes, extensions, or same-origin code can remove or deny it.
- HTML text editing uses the browser's standard newline model: CR and CRLF become LF when the affected title or body is edited.
- A web-writable PHP file is intentionally unusual. Keep backups and do not deploy it where policy or hardening rules forbid self-modifying code.

## Unsafe simple version

[`wiki-piplet-unsafe.php`](wiki-piplet-unsafe.php) keeps only the core trick: multiple reading pages, a separate editor with live preview, and JSON after `__halt_compiler()`. It rewrites itself directly. It has no authentication, CSRF protection, validation, atomic replacement, crash recovery, or conflict handling. Keep it on loopback, use a disposable copy, and keep a backup.

```sh
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/wiki-piplet-unsafe.php`.

## Test it

The dependency-free test runner always copies the application to a unique temporary directory before mutating it:

```sh
php tests/run.php
PIPLET_REQUIRE_CHROME=1 php tests/run.php
php tests/unsafe-run.php
```

It covers CRUD, idempotent creates, format-1 migration, format-2 generation/version conflicts, rekeyed browser recoveries, import/rekey, exact title/tag values, malformed Unicode, JSON/cardinality limits, exact byte ceilings, size projection, mandatory synchronization, private temporary files, crash checkpoints, a held-lock deadline, hostile Unicode/HTML/CSS, immutable-prefix preservation, HTTP authentication/CSRF/cookie behavior, multi-megabyte snapshots, and concurrent writers across inode replacements. It also verifies that the runner itself returns 404 under a web SAPI.

When Chrome or Chromium is available, the suite runs real browser regressions for held/double saves, lost responses, conflicts, random immutable recovery records, read-only exact-value recovery, unavailable browser storage, bounded story/index rendering, base64 boot transport, theme-only saves, full CSS previews, safe appearance mode, and mobile focus. Without Chrome, it reports that dynamic scenarios were skipped and performs static checks only; a skip is not evidence that the browser boundary passed. Set `PIPLET_REQUIRE_CHROME=1` to make a missing browser fail the run, as release and security validation should.

## License

piplet is available under the [MIT License](LICENSE).
