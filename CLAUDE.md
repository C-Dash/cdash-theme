# CDASH 5 — Omeka S theme

Theme for the Cambridge Historical Commission Digital Architectural Survey and
History. Production runs the predecessor, `cdash_4.1.1`, at
cdash.cambridgema.gov. This is its replacement, developed on branch
**`v5-persistent-shell`**.

## What this theme is

A Leaflet map pane beside an Omeka browse pane. The defining property, and the
reason most of the code looks the way it does:

**The map is built once per session and never torn down.** Navigation swaps only
the browse pane's `#content`; the map, its 23 layers and its ~2000 clustered
markers survive untouched. 4.1.1 called `window.location.replace()` on every
marker click, rebuilding all of it to show a different item in the *other* pane.

Verified by access log, not by inspection: 19 navigations produce exactly one
`placemarkers.php` POST.

## How it fits together

| file | role |
|---|---|
| `view/layout/layout.phtml` | the shell: banner, nav, panes, drawers. Carries the `hx-*` attributes that drive everything |
| `view/cdash/layer-registry.php` | **single source of truth for map layers.** One entry per layer; feeds both menus *and* the Leaflet factory |
| `view/cdash/cdash-map-5.phtml` | the `#drawmap` element and a JSON config island. Nothing else |
| `asset/js/cdash-map.js` | the map program — state, layers, markers, featured marker, htmx wiring |
| `asset/js/cdash-layer-factory.js` | turns registry descriptors into Leaflet layers |
| `asset/js/cdash-layout.js` | Alpine components: pane splitter, banner collapse, drawers |
| `asset/css/cdash-shell.css` | hand-written shell CSS, loaded **after** `style.css` so its overrides win |
| `asset/css/style.css` | **generated** from `asset/sass/` by the VS Code Live Sass Compiler. Do not hand-edit |
| `asset/php/_require-admin.php` | admin guard for the geo tools |

No build step and no bundler. Alpine and htmx are vendored under
`asset/vendor/` and chosen precisely to avoid one.

## Decisions worth not relitigating

- **HTML fragments, not JSON.** htmx boosts links and selects `#content` out of
  Omeka's ordinary server-rendered responses. Using the REST API would mean
  reimplementing resource page blocks and media rendering in JavaScript.
- **Map state lives in the URL hash**, `#zoom/lat/lng/layerKeys`. Read once on
  load, written continuously. Makes views shareable; replaced all the
  sessionStorage machinery, which only existed to survive the old page reloads.
- **Marker clicks call `htmx.ajax()`**, passing `mapDiv` as `source` purely so
  htmx resolves the inherited `hx-push-url` and owns history. An earlier attempt
  used a hidden boosted anchor; that cannot work, because htmx reads a boosted
  anchor's href once at process time and closes over it.
- **`hx-history-elt` on `#showresult`.** Without it htmx snapshots
  `document.body`, so Back replaced the whole page and left Leaflet holding a
  detached node.
- **Marker links derive from `currentSite()->url()`.** 4.1.1 hardcoded
  `/s/cdash/`, which is why *it* only works on a site with that exact slug.

## The recurring hazard

Everything that used to happen once per page now happens **once per swap**.
That is the class of bug to expect here. Two instances have already been fixed:

- An inline `<script>` in `view/common/linked-resources.phtml` declared
  top-level `const`s. Correct in stock Omeka; fatal here, because htmx
  re-executes scripts in swapped content and `const` cannot be redeclared. It is
  wrapped in an IIFE now.
- `htmx:afterSwap` does **not** fire on Back/Forward — that is
  `htmx:historyRestore`. Both are handled.

Check this whenever adding a resource page block or template override, and after
any Omeka upgrade.

## Dev instance

Docker compose project `cdash-dev-docker`, config at
`../cdash-dev-docker/docker-compose.yml`, Omeka on port 80. Containers are
`restart: always`.

| site | slug | theme |
|---|---|---|
| 4 | `cdash5` | `cdash_5.0.0` — this one |
| 3 | `cdash` | `cdash_4.1.1` — unmodified by request |

**The slugs have swapped once and may again — check, do not assume.** 4.1.1
*requires* the slug `cdash`; this theme works under any slug.

This directory is bind-mounted **read-only** into the container, so edits are
served with no deploy step:

```yaml
- ../cdash_5.0.0:/var/www/html/persist/themes/cdash_5.0.0:ro
```

That line is the one unversioned artifact in the whole setup — its directory is
a git repo owned by a different Windows SID, so git refuses it.

### Two traps that have each cost time twice

- **Stale JavaScript.** Assets are cached at `?v=<theme version>`, which does
  *not* change when a file is edited. Hard reload (Ctrl+Shift+R) after touching
  anything in `asset/js/` or `asset/css/`. This has masqueraded as a map bug.
- **Empty item pool.** A new site — *or any site after items are re-imported* —
  can end up with zero rows in `item_site`. Item pages and the map still work,
  because markers come from direct SQL that bypasses site scoping, but every
  site-scoped listing renders empty until the pool is repopulated in admin.

## Verifying a change

The map's health is measurable, so measure it:

```bash
# hard reload, click ten markers, then:
docker logs cdash-dev-docker-omeka-1 --since 5m 2>&1 | grep -c placemarkers
# 1 = correct. ~10 = the map is being rebuilt; the swap is broken.
```

There is no JS engine on this machine and pulling one has failed repeatedly, so
`asset/js/*.js` is checked by the editor rather than by tooling here. PHP lints
via `docker exec cdash-dev-docker-omeka-1 php -l <path>`. No test suite exists.

## Still open

1. **Production `geosync.php`.** `cdash_4.1.1` ships an unauthenticated endpoint
   that rewrites the database on load — no auth, no request gating. Guarded in
   this theme by `_require-admin.php`; **production is not**. Highest priority.
2. **CSS split.** `cdash-shell.css` is hand-written while `style.css` is
   sass-generated. Decide whether the shell folds in as `_shell.scss` imported
   last, or stays standalone. This is the first fork in the styling work.
3. **Item-page centring.** A shared hash URL restores zoom and layers, but the
   featured-marker logic then pans to the item's own marker, so the framing is
   not reproduced. Deliberate; may want revisiting.
4. **Real-device phone check.** The `100dvh` fix addresses the collapsing mobile
   URL bar and is desktop-verified only.
5. **`asset/php/geosync copy.php`** — untracked leftover.
6. **13 Dependabot alerts** on the default branch; they clear when the
   `package-lock.json` deletion merges to `main`.
7. **No PR yet** — styling first, by decision.

## Longer-term

The four scripts in `asset/php/` open their own mysqli connection and issue raw
SQL against Omeka's EAV schema, bypassing its ACL and site scoping entirely.
That is why `is_public` had to be filtered by hand. They belong in an Omeka S
module with admin-only routes and a Doctrine handle. Separate project; do not
start it inside the styling work.

## Also

A fuller architecture review, with the reasoning behind the five findings above,
is at `~/.claude/plans/please-look-at-overview-prompt-md-proud-pretzel.md`.
