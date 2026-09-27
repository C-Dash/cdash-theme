# CDASH 5 — Omeka S theme

Theme for the Cambridge Historical Commission Digital Architectural Survey and
History. Production runs the predecessor, `cdash_4.1.1`, at
cdash.cambridgema.gov. This is its replacement, on `main` since PR #1 and
developed on branch **`v5-persistent-shell`**.

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
| `asset/js/cdash-layout.js` | Alpine components: pane splitter, banner collapse, drawers. Also the breadcrumb, which is not an Alpine component and sits outside `alpine:init` |
| `view/common/cdash-card.phtml` | **one card, rendered by both card grids.** The Document/Place split, the marker variants and the caption rules live here and nowhere else |
| `asset/sass/` | **the stylesheets.** `style.scss` and `print.scss` are the two entry points; everything else is a partial. `_tokens` publishes the palette, `_shell`/`_panes`/`_drawers`/`_tall-case` are the application shell, `_screen`/`_desktop` are Omeka component styling, `_cards` holds the card-grid mixins and `_resource-list` applies them |
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
- **The admin user bar is swapped out of band**, via `hx-select-oob="#user-bar"`
  on the shell, emitted only when there is a bar. Omeka builds its View/Edit
  links from the route it renders, so the swap response already holds a correct
  bar; without this the links kept pointing at the last full page load.
  Rewriting hrefs in JavaScript was the alternative and is worse — it would
  have to reimplement item vs item set vs media vs page, the labels, and
  per-resource edit permission. Anything added to the bar must be
  server-rendered, in `view/common/user-bar.phtml`, or the first swap drops it.
- **`scrollIntoViewOnBoost` is off** (`htmx-config` meta in `layout.phtml`).
  Scrolling is the swap spec's job, `scroll:#showresult:top`. htmx's implicit
  `show:top` for boosted links targets the *first* swapped element, and
  `hx-select-oob` swaps run first — so logged in, it scrolled `#user-bar`, at
  the foot of the pane, into view, and every nav click opened at the bottom.
  Invisible when anonymous. **Back/Forward** take no swap spec at all:
  `#showresult` keeps the old page's `scrollTop`, so a `historyRestore`
  handler in `cdash-layout.js` resets it.

## The browse pane's furniture

Four things were added to the browse pane after the shell settled. They share
one responsive lever and one rule about where state lives.

- **`@container browse (max-width: 500px)` is the theme's responsive lever**,
  not a media query. The container is declared on
  `.cdash-browse-pane .cdash-pane-content` in `_panes.scss`; the pane is
  resizable, so a phone and a divider dragged halfway across are the same
  problem, and a viewport query answers only the first. Used by the card grids,
  the property list and the sticky header. Reach for this, not `@media`.
- **The sticky page header**, `.cdash-page-header`, is one partial,
  `view/common/cdash-page-header.phtml`, rendered by the item page and both
  listings: breadcrumbs, title, then "Place · 6 documents", "Document · 3
  pp.", "Folder · 42 items", or just "120 items" under "Search Results". Callers
  compute the words and the partial lays them out. A `div`, never a `<header>` —
  `print.scss` hides that element for the banner and would take the title off
  paper with it. Every pixel of it is page that cannot be scrolled to, hence
  the tight type, and why pagination and the filter chips stay out of it.
- **Two links above the title, from different places on purpose.**
  `Visit [Place]` is a fact about the item (`cdash:placeItem`), so
  `show.phtml` renders it — correct on a shared link and with JS off.
  `Return to …` is session state, so `cdash-layout.js` inserts it. JS hides the
  first when both would name the same Place; it *hides* rather than removes,
  because htmx caches the pane for Back/Forward and a snapshot taken after a
  removal would lose a server-rendered element for good.
- **What the breadcrumb may offer depends on the page offering it**: an item
  page will not offer a Document (only Back between two Documents could put one
  there, and "Return to Exterior View" is noise), while a listing will. URLs are
  compared through `pageKey()`, path and query only — every URL here carries the
  map hash, which `cdash-map.js` rewrites continuously, so raw string equality
  silently never matches.
- **The card grid is one card.** `view/common/cdash-card.phtml` renders both
  grids; `_cards.scss` holds the mixins both stylesheets include. Neither grid
  renders `ul.resource-list`: inherited rules for that class out-specify any
  class-only rule, so restyling it in place cannot win. The card link is
  `cdash-card-link`, never `resource-link`, for the same reason.

## The recurring hazard

Everything that used to happen once per page now happens **once per swap**.
That is the class of bug to expect here. Three instances have already been fixed:

- An inline `<script>` in `view/common/linked-resources.phtml` declared
  top-level `const`s. Correct in stock Omeka; fatal here, because htmx
  re-executes scripts in swapped content and `const` cannot be redeclared. It is
  wrapped in an IIFE now.
- `htmx:afterSwap` does **not** fire on Back/Forward — that is
  `htmx:historyRestore`. Both are handled.
- **`<body>`'s class is the last full load's, not the current page's.**
  Omeka templates append `item resource show` and the like to `<body>`, but
  only a full load renders `<body>`. Rules keyed to `body.resource` made the
  sticky item header work or not depending on where the session *started* — a
  reload on an item page fixed it, so it looked intermittent. `layout.phtml`
  now copies those classes onto `#content` as `data-cdash-page`; key page-type
  CSS to `#content[data-cdash-page~="…"]`, never to a body class.

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

### Traps that have each cost time

- **Stale JavaScript.** Assets are cached at `?v=<theme version>`, which does
  *not* change when a file is edited. Hard reload (Ctrl+Shift+R) after touching
  anything in `asset/js/` or `asset/css/`. This has masqueraded as a map bug.
- **Empty item pool.** A new site — *or any site after items are re-imported* —
  can end up with zero rows in `item_site`. Item pages and the map still work,
  because markers come from direct SQL that bypasses site scoping, but every
  site-scoped listing renders empty until the pool is repopulated in admin.
- **A console error that is not ours.** Clicking a marker in Chrome throws
  `Cannot read properties of undefined (reading 'startTime')`, with a stack
  entirely in `VM…` frames and `reportAllChanges` at the top. That is Chrome
  DevTools' own Live Metrics instrumentation — a web-vitals bundle it injects
  into any page it is attached to — tripping over this app's soft navigations
  (htmx `pushState` per swap, plus continuous `replaceState` for the map hash).
  Ruled out properly: `reportAllChanges` appears nowhere in the theme, its
  vendored libraries, or anywhere under `/var/www/html`; it reproduces with
  extensions disabled; it does not reproduce in Firefox. Nothing to fix. **A
  stack made only of `VM…` frames is injected code, not page code** — worth
  weighting that tell before reaching for extensions, as happened here.

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

3. **Item-page centring — resolved.** The featured-marker logic used to pan on
   every navigation, which made the hash near useless: the hash is written from
   `moveend`, so the URL recorded where the *item* was rather than where the
   visitor had put the map, and a shared link could not reproduce its own
   framing — the hash was applied on load and then panned away from.

   Panning is now conditional, in `updateFeaturedMarker()`:

   - **first pass with a hash present** → no pan. The shared view wins, and the
     red circle sits wherever in that frame the item falls.
   - **marker outside the map pane's bounds** → pan to it, or the circle is
     somewhere unseen with nothing to say where. The bounds are the *pane's*,
     so a narrow pane counts less as visible, which is the right answer.
   - **marker already in view** → no pan. This is what keeps the visitor's own
     extent in the URL.

   A bare item URL still centres on its item: no hash means no shared view to
   honour. The marker itself is always drawn at the item's coordinates —
   drawing it and moving the map are separate questions, and conflating them
   was the bug.

   `cdashFirstRefresh` is cleared in `refreshFromBrowsePane()`, not in
   `updateFeaturedMarker()`, which returns early on a page with no
   coordinates — otherwise a first load on a site page leaves the flag standing
   and the next item looks like a fresh arrival.
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
6. **Two card blocks, one card — resolved.** The card grid appears twice:
   Linked Resources on a Place page, and search results/folder contents. Both
   now render `view/common/cdash-card.phtml`, so the markup, the Document /
   Place split and the caption rules exist once. `asset/sass/_resource-list.scss`
   styles both; `_linked-resources.scss` and its `<table>` re-flow are gone.

   This was held open for a while in favour of staying close to stock. What
   settled it was a caption: "Exterior View, 3 pp." cannot come from
   `linkPretty()`, which only prints a title, so the template had to build its
   own link — the divergence itself — and the caption logic would otherwise
   have been written twice.

   What is still stock in `linked-resources.phtml` is what actually changes
   between Omeka versions: the pagination setup, the locale filtering, the
   property select, and the `$subjectValues` loop — values *grouped by the
   property pointing at this item*, which is why it iterates groups. Only the
   markup inside that loop is ours.

   Neither template renders `ul.resource-list`, and that is not a style
   choice. `ul.resource-list .resource img` (0,2,2) and
   `#content .resource-link img` (1,1,1) out-specify any class-only rule, so
   restyling that markup in place cannot win regardless of source order. Theme
   class names sidestep all of it — note the card link is `cdash-card-link`,
   never `resource-link`, for exactly that reason. Lists elsewhere that still
   render `ul.resource-list` (site page preview blocks, the item-set index)
   keep the inherited styling untouched.

   One asymmetry in the data, worth knowing before hunting a bug that is not
   there: every resource link points *at* a Place, so only Place pages have a
   Linked Resources block. Document pages have none.
7. **Original TIFs are unlinked, not protected.** Page thumbnails no longer
   link to the original: the theme's copy of
   `view/common/resource-page-block-layout/media-embeds.phtml` passes
   `['link' => null]` to `$media->render()`, and Omeka's `ThumbnailRenderer`
   then returns the bare `<img>`. That replaced a JS fixup which stripped the
   `href` after every swap, so the anchor is now absent from the markup rather
   than removed from it.

   **The file is still public**, and that is the part left undone:

   - `/files/original/<hash>.tif` returns 200 to anyone who asks;
   - the REST API hands out the path — `/api/media/<id>` includes
     `o:original_url` — so the link being gone from the page hides nothing
     from anything that reads the API.

   Blocking the path at the web server is not the fix it looks like: Apache
   cannot tell a logged-in editor from a visitor, so a deny rule takes staff
   downloads in the admin UI with it. (For the dev instance that rule would go
   in the persist volume at `config/apache2/.htaccess_dev`, which is symlinked
   as the docroot `.htaccess`.) The module that does this properly is
   **Access** (Daniel-KM), which serves files through a permission-checking
   controller. Deferred deliberately, pending appetite for another module.

   Any other media block enabled later — `media-list`, the lightbox pair —
   calls `$media->render()` the same way and will link originals again until
   given the same option.
8. **The admin bar — links now follow the page, presentation does not.**
   `e2cb3e5` fixed the stale View/Edit links and that part is confirmed
   working; `a7b2064`, the URL keeping the visitor's map extent rather than the
   item's, is confirmed too. What is left on the bar is undecided:

   - **Styling.** Not yet judged. The theme owns
     `view/common/user-bar.phtml` now, so the markup is ours to change, and the
     two CDASH tools carry `class="admin cdash-tool"` if they want
     distinguishing. The bar sits in `#showresult`'s auto row — see the sticky
     footer comment in `_panes.scss` — so its height comes out of the browse
     pane.
   - **Whether admin links should open a new tab.** Following Edit in the same
     tab leaves the public site, which means the persistent shell, the map and
     ~2000 markers are all torn down and rebuilt on return — the one thing this
     theme is built to avoid. GeoSync and GeoAudit already open named windows
     (`target="geosync"`, `target="geoaudit"`). Giving the bar's own links a
     target is a one-line change in that override, passing `['target' => …]` to
     the `$hyperlink` call. Decide deliberately: a new tab per edit accumulates
     tabs, a single named admin window does not.
9. **Breadcrumb scroll depth — deferred, and not free.** "Return to …" returns
   to the right page at the top, not to where the visitor was. htmx 2.0.4 keeps
   no scroll positions in its history cache, and the swap spec forces
   `scroll:#showresult:top` in two places — `layout.phtml` and the marker click
   in `cdash-map.js`. Doing it means taking scrolling over entirely: both
   modifiers go, positions are saved per URL, and top becomes a default rather
   than a rule. That is why it was split off rather than shipped with the
   breadcrumb.
10. **The backlog is in three notes, two still untracked.**
    `browse_pane_dynamics.md` is committed; `browse_grid_brainstorming.md` and
    `cdash5_layout_dynamics.md` are not. They hold the outstanding wishes: a
    Print button in the breadcrumb bar that applies `print.css` to the browse
    pane alone, an unfinished "Search Results" section, and the remaining
    layout-dynamics items. The untracked two are invisible to a fresh clone —
    commit them or fold them into this file.
11. **`asset/php/geosync copy.php`** — untracked leftover.
12. **Dependabot — resolved.** All 15 alerts on `main` came from
    `package-lock.json`; the merge deleted it and none remain open. Nothing in
    the theme is installed from npm any more, so a new lockfile appearing is
    worth questioning.
13. **Merged to `main`** as PR #1 (`b7a7e00`). Development continues on
    `v5-persistent-shell`. Production is untouched by the merge — it still
    runs `cdash_4.1.1`, which is why #1 above stands.

## Longer-term

The four scripts in `asset/php/` open their own mysqli connection and issue raw
SQL against Omeka's EAV schema, bypassing its ACL and site scoping entirely.
That is why `is_public` had to be filtered by hand. They belong in an Omeka S
module with admin-only routes and a Doctrine handle. Separate project; do not
start it inside the styling work.

## Also

A fuller architecture review, with the reasoning behind the five findings above,
is at `~/.claude/plans/please-look-at-overview-prompt-md-proud-pretzel.md`.
