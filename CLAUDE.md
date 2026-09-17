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
| `asset/sass/` | **the stylesheets.** `style.scss` and `print.scss` are the two entry points; everything else is a partial. `_tokens` publishes the palette, `_shell`/`_panes`/`_drawers`/`_tall-case` are the application shell, `_screen`/`_desktop` are Omeka component styling |
| `asset/css/style.css`, `print.css` | **generated.** Do not hand-edit — see *Building the stylesheets* |
| `asset/php/_require-admin.php` | admin guard for the geo tools |

No bundler, and no build step for the JavaScript — Alpine and htmx are vendored
under `asset/vendor/` precisely to avoid one. Sass is the single exception, and
it is dev-time only: nothing the browser loads is bundled or transpiled.

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

PHP lints via `docker exec cdash-dev-docker-omeka-1 php -l <path>`. No test
suite exists.

`asset/js/*.js` can be syntax-checked, using the Node below — worth doing after
any edit, since nothing else catches a typo before the browser does:

```bash
ELECTRON_RUN_AS_NODE=1 "$LOCALAPPDATA/Programs/Microsoft VS Code/Code.exe" \
  --check asset/js/cdash-map.js
```

There *is* a JS engine on this machine, contrary to what this file used to say:
VS Code's Electron runs as Node 24, via

```bash
ELECTRON_RUN_AS_NODE=1 "$LOCALAPPDATA/Programs/Microsoft VS Code/Code.exe" -e "…"
```

Standalone `node`/`npm` are still absent, and installing them has failed
repeatedly — but the above works if a Node script is ever genuinely needed.

### Building the stylesheets

`asset/css/style.css` (media=`screen`) and `asset/css/print.css` (media=`print`)
are generated from `asset/sass/`. They are the only stylesheets the theme owns;
there is no hand-written CSS left. Two things compile them and they must agree:

- the VS Code **Live Sass Compiler** extension (`glenn2223.live-sass`), which
  fires only on a save *inside the editor* — it uses `onDidSaveTextDocument`
  and registers no filesystem watcher, so files written by anything else,
  Claude included, never trigger it;
- **Dart Sass standalone 1.97.3**, vendored at `../tools/dart-sass/` — outside
  this repo on purpose, since the theme is published and a 4 MB Windows binary
  does not belong in it:

```bash
../tools/dart-sass/sass.bat asset/sass/style.scss asset/css/style.css --style=expanded
../tools/dart-sass/sass.bat asset/sass/print.scss asset/css/print.css --style=expanded
```

They agree because **autoprefixer is turned off**, in the committed
`.vscode/settings.json` alongside the output format. Left on, its default
browserslist (`"defaults"`) resolves against a caniuse-lite bundled *inside the
extension*, so the emitted prefixes change silently when the extension updates —
and it both adds prefixes and strips hand-written ones. The prefixes that matter
are spelled out in the `.scss` sources instead. If `.vscode/settings.json` does
not take effect, check whether VS Code's workspace root is this directory or its
parent.

One cosmetic difference survives: the two disagree only on how they emit the
trailing `sourceMappingURL` comment — postcss joins it to the preceding `*/`
with no final newline, dart-sass puts it on its own line. So `style.css` can
show a 3-line diff at its tail depending on which compiler ran last. That is
expected noise, not a real change; the 1,268 lines of actual CSS above it are
identical either way.

The build emits no deprecation warnings. It used to emit 14, from `@import`
and the `darken()`/`lighten()` global builtins; the module migration settled
both.

## Still open

1. **Production `geosync.php`.** `cdash_4.1.1` ships an unauthenticated endpoint
   that rewrites the database on load — no auth, no request gating. Guarded in
   this theme by `_require-admin.php`; **production is not**. Highest priority.
2. **CSS split — done.** The theme builds two generated stylesheets,
   `style.css` (media=`screen`) and `print.css` (media=`print`), and owns no
   hand-written CSS. Each of `#banner`, `#navmenu`, `#drawmap`, `#showresult`,
   `#content`, `#overlay-menu` and `#basemap-menu` is declared once: box model
   in the shell partials, colour and typography in `_screen`/`_desktop`.
   `_overrides.scss` is gone.

   The method is worth reusing, because the obvious one is wrong. Commenting
   out an override does not show whether it is needed — it just reveals the
   losing declaration underneath, so everything looks load-bearing. What works
   is deleting the *loser* and verifying the **effective cascade**: for every
   exact selector string, the last value declared for each property. That map
   was identical across both steps (772 → 768 pairs when 23 losing
   declarations went, zero changed values; then 768 → 768, a pure move).
   Watch for two traps it has: a group selector like
   `#overlay-menu, #basemap-menu` is a *different* selector string with the
   same specificity, and a shorthand can silently supply a longhand you
   deleted.

   Three things that were quietly false and are not worth re-deriving:
   `gutter()` had been undefined since susy was dropped, so eleven
   declarations shipped as `padding: 0 gutter()` and were discarded;
   `.property value-content` in the old print CSS matched `value-content` as
   an element, when the markup is `<span class="value-content">`; and
   `#content` takes `max-width` plus auto side margins from `base.container`
   while the shell's `margin: 0` cancels the centring, so it is width-capped
   and left-aligned. The last one is still live — a decision, not a bug.

   `sass-migrator` 2.6.1 is vendored beside dart-sass at
   `../tools/sass-migrator/`.

3. **Item-page centring.** A shared hash URL restores zoom and layers, but the
   featured-marker logic then pans to the item's own marker, so the framing is
   not reproduced. Deliberate; may want revisiting.
4. **Real-device phone check.** The `100dvh` fix addresses the collapsing mobile
   URL bar and is desktop-verified only.
5. **Slide-collapse-restore — redesigned.** Panes and
   drawers now collapse to zero (no sliver), show a grip on the open edge and
   a tab on the collapsed one; a tab click reopens to the last size, kept in
   `localStorage` under `cdash.layout`. Snapping is in pixels (`SNAP_PX = 64`
   in `cdash-layout.js`, deliberately above the map's 50px floor). The trap
   below still applies, so the floor stays and the map now has
   `trackResize: false`.

   `camBase` is the only `esriVector` layer in the registry, so the only
   basemap drawn through a WebGL canvas. maplibre sizes its drawing buffer as
   `floor(pixelRatio * width)`, clamping that ratio against a cached
   `_maxCanvasSize`. Resizing the map into a collapsed sliver makes its painter
   report `overLimit`, whereupon maplibre **overwrites that cache from the
   starved context** — `this._maxCanvasSize = [gl.drawingBufferWidth,
   gl.drawingBufferHeight]` — pinning the cap at roughly `[0.6, 0.9]`. Every
   later resize then clamps the ratio to ~0.001 and floors the buffer to 0×0.

   The layer then draws nothing while its CSS box, its transform and its GL
   context all still look correct, which is what makes it confusing: the pane
   is not blank, because the raster `massGIS` ground layer keeps drawing. Only
   `removeLayer` + `addLayer` recovers it, by rebuilding the painter —
   `setPixelRatio`, setting `painter.pixelRatio`, `resize` and `triggerRepaint`
   were each tested and none take. A vendor upgrade does not help either: that
   line is still in maplibre's current `main`, Leaflet plays no part, and
   maplibre is baked into the prebuilt `esri-leaflet-vector` bundle rather than
   separately upgradable.

   Guarded in `asset/js/cdash-map.js` by not propagating a map size below
   `COLLAPSED_MAP_FLOOR_PX` to Leaflet at all. That is **prevention, not
   recovery**. The one other known route — a window resize while the pane
   sits collapsed, via Leaflet's own resize handler — is closed by
   `trackResize: false`; the ResizeObserver covers window resizes instead.
   A canvas poisoned some other way still needs a reload. Collapse-to-zero
   depends on that floor: do not remove it.
6. **`asset/php/geosync copy.php`** — untracked leftover.
7. **13 Dependabot alerts** on the default branch; they clear when the
   `package-lock.json` deletion merges to `main`.
8. **No PR yet** — styling first, by decision.

## Longer-term

The four scripts in `asset/php/` open their own mysqli connection and issue raw
SQL against Omeka's EAV schema, bypassing its ACL and site scoping entirely.
That is why `is_public` had to be filtered by hand. They belong in an Omeka S
module with admin-only routes and a Doctrine handle. Separate project; do not
start it inside the styling work.

## Also

A fuller architecture review, with the reasoning behind the five findings above,
is at `~/.claude/plans/please-look-at-overview-prompt-md-proud-pretzel.md`.
