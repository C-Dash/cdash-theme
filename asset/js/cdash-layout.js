document.addEventListener('alpine:init', () => {

  // Below this many pixels an open pane or drawer is not worth keeping: a
  // drag released there snaps it fully shut. Pixels rather than a percentage,
  // so the gesture feels the same on a phone and a wide monitor.
  //
  // Kept above COLLAPSED_MAP_FLOOR_PX (50) in cdash-map.js on purpose: an
  // open map pane is then always big enough for Leaflet to be told its size.
  const SNAP_PX = 64;

  // How far a pointer may wander before a press on a tab counts as a drag
  // rather than a click.
  const CLICK_SLOP_PX = 4;

  const KEY_STEP_PCT = 2;
  const KEY_BIG_STEP_PCT = 10;
  const DEFAULT_OPEN_PCT = 50;
  const DRAWER_MAX_PCT = 95;

  // Last open sizes, so a tab click brings back what the user last chose.
  // Per-viewer layout, deliberately not in the URL hash, which holds shareable
  // map state. Storage can be missing or throw (private windows, blocked site
  // data); the layout then just falls back to DEFAULT_OPEN_PCT.
  const STORAGE_KEY = 'cdash.layout';

  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

  function loadLayout() {
    try {
      const v = JSON.parse(localStorage.getItem(STORAGE_KEY));
      return v && typeof v === 'object' ? v : {};
    } catch (err) {
      return {};
    }
  }

  function saveLayout(key, pct) {
    try {
      const v = loadLayout();
      v[key] = Math.round(pct * 10) / 10;
      localStorage.setItem(STORAGE_KEY, JSON.stringify(v));
    } catch (err) {
      // Not persisted; the in-memory value still serves this page.
    }
  }

  function storedPct(key) {
    const v = loadLayout()[key];
    return typeof v === 'number' && isFinite(v) ? v : DEFAULT_OPEN_PCT;
  }

  // One pointer gesture on el, reported as a signed distance along one axis.
  //
  // Listeners go on currentTarget, not target: the divider and edges contain a
  // grip <span>, and a press landing on it must still drive the element that
  // owns the handler. Pointer capture keeps the gesture alive when the pointer
  // leaves that element, which on a thin divider is immediately.
  //
  // onEnd(moved, cancelled): moved is false for a press that stayed within
  // CLICK_SLOP_PX, which is how a tab tells a click from a drag.
  function trackDrag(downEvent, axisPos, onMove, onEnd) {
    const el = downEvent.currentTarget;
    const start = axisPos(downEvent);
    let moved = false;

    el.setPointerCapture(downEvent.pointerId);
    el.classList.add('cdash-dragging');

    const move = (e) => {
      const delta = axisPos(e) - start;
      if (Math.abs(delta) > CLICK_SLOP_PX) moved = true;
      if (moved) onMove(delta);
    };

    const end = (e) => {
      if (el.hasPointerCapture(e.pointerId)) el.releasePointerCapture(e.pointerId);
      el.removeEventListener('pointermove', move);
      el.removeEventListener('pointerup', end);
      el.removeEventListener('pointercancel', end);
      el.classList.remove('cdash-dragging');
      onEnd(moved, e.type === 'pointercancel');
    };

    el.addEventListener('pointermove', move);
    el.addEventListener('pointerup', end);
    el.addEventListener('pointercancel', end);
  }

  // Animates a custom property to its new value, then drops the class that
  // enables the transition so live drags stay unanimated.
  function applyPct(el, prop, animClass, pct, animated) {
    el.classList.toggle(animClass, !!animated);
    el.style.setProperty(prop, pct + '%');
    if (animated) {
      const clear = () => {
        el.classList.remove(animClass);
        el.removeEventListener('transitionend', clear);
      };
      el.addEventListener('transitionend', clear);
    }
  }

  // A tab disappears when its pane opens and the divider when a pane
  // collapses. If keyboard focus was on the one going away, hand it to the
  // one that replaces it rather than dropping it on <body>.
  function handOffFocus(component, fromEl, toSelector) {
    if (document.activeElement !== fromEl) return;
    component.$nextTick(() => {
      const to = document.querySelector(toSelector);
      if (to) to.focus();
    });
  }

  Alpine.data('appShell', () => ({
    bannerCollapsed: false,

    init() {
      let pointerDownAt = null;

      const collapseBanner = () => {
        this.bannerCollapsed = true;
      };

      window.addEventListener('pointerdown', (e) => {
        pointerDownAt = { x: e.clientX, y: e.clientY, t: performance.now() };
      });

      window.addEventListener('pointerup', (e) => {
        if (!pointerDownAt) return;
        const dx = e.clientX - pointerDownAt.x;
        const dy = e.clientY - pointerDownAt.y;
        const dist = Math.hypot(dx, dy);
        const dt = performance.now() - pointerDownAt.t;
        pointerDownAt = null;
        if (dist > 6 || dt > 350) {
          collapseBanner();
        }
      });

      window.addEventListener('wheel', collapseBanner, { passive: true });
      window.addEventListener('scroll', collapseBanner, { passive: true });
      window.addEventListener('keydown', collapseBanner);
    },
  }));

  // The nav bar's page links, collapsed into the "CDASH" pull-down when they no
  // longer fit beside the search box -- see the comment in layout.phtml.
  //
  // The row is measured, not given a breakpoint, because how wide it is
  // depends on the site's page labels. ul.navigation stays in the layout tree
  // while collapsed (hidden, absolutely positioned -- see _shell.scss), so its
  // full width can be read in either state and the answer never flickers: it
  // is "does the whole row fit", asked the same way whether or not it is shown.
  //
  // DOM refs are closure variables rather than reactive properties, as in
  // pullOut below.
  Alpine.data('navCollapse', () => {
    // A few pixels of slack, so the row collapses just before it touches the
    // search box rather than just after.
    const SLACK_PX = 8;

    let navEl = null;
    let listEl = null;
    let searchEl = null;
    // The search box's full width, read only while the row is expanded: once
    // collapsed it may shrink to fit a phone (_shell.scss), and measuring that
    // shrunken width would make the row look as though it fits again --
    // expanding it, which unshrinks the box, which collapses it, and so on.
    let searchW = 0;

    return {
      collapsed: false,
      open: false,

      init() {
        navEl = this.$el;
        listEl = navEl.querySelector(':scope > ul.navigation');
        searchEl = navEl.querySelector(':scope > #search');
        if (!listEl) return;

        new ResizeObserver(() => this.measure()).observe(navEl);
        // Labels change width when the web font arrives.
        if (document.fonts) document.fonts.ready.then(() => this.measure());
      },

      measure() {
        if (searchEl && !this.collapsed) searchW = searchEl.offsetWidth;
        const collapse = listEl.scrollWidth + SLACK_PX > navEl.clientWidth - searchW;
        if (collapse === this.collapsed) return;
        this.collapsed = collapse;
        if (!collapse) this.open = false;
      },

      close(refocus) {
        if (!this.open) return;
        this.open = false;
        if (refocus) this.$refs.toggle.focus();
      },
    };
  });

  // The Map | Browse split. --cdash-split is the map pane's share of the
  // container's axis: 0% is map collapsed, 100% is browse collapsed.
  Alpine.data('paneResizer', () => {
    // DOM refs kept out of Alpine's reactive wrapper -- see pullOut below.
    // containerEl (container-type:size host) is used for size/orientation
    // measurement. gridEl (the nested .cdash-pane-grid) is where --cdash-split
    // and .cdash-animating live, since the grid's own template lives there --
    // see _panes.scss for why the grid isn't on containerEl itself.
    let containerEl = null;
    let gridEl = null;

    return {
      orientation: 'wide',
      splitPct: DEFAULT_OPEN_PCT,
      lastOpenPct: DEFAULT_OPEN_PCT,
      mapCollapsed: false,
      browseCollapsed: false,

      init() {
        containerEl = this.$el;
        gridEl = containerEl.querySelector('.cdash-pane-grid');

        const onResize = () => {
          const isTall = containerEl.offsetHeight >= containerEl.offsetWidth;
          this.orientation = isTall ? 'tall' : 'wide';
        };
        onResize();
        new ResizeObserver(onResize).observe(containerEl);

        // A reload always starts with both panes open, at the remembered split.
        this.lastOpenPct = this.clampOpen(storedPct('split'));
        this.setSplit(this.lastOpenPct, false);

        // Content is about to arrive in the browse pane, and a collapsed pane
        // cannot show it. Every route to that content -- a nav link, the
        // search form, the Filters form, the breadcrumb, and a marker click,
        // which issues htmx.ajax with the map div as its source -- fires this
        // one event, and all of them are inside the shell, so it reaches here.
        // That is why the map needs no part in this: it does not have to know
        // the layout exists.
        //
        // open() is the method the tab calls, reused rather than reproduced,
        // so "the same as clicking the tab" holds by construction -- same
        // animation, same remembered size. beforeRequest rather than
        // afterSwap, so the pane starts opening on the click and the 300ms
        // animation overlaps the request instead of following it.
        //
        // Only the browse pane. A collapsed MAP pane is left as the user left
        // it: nothing being loaded here needs the map.
        document.body.addEventListener('htmx:beforeRequest', () => {
          if (this.browseCollapsed) this.open();
        });
      },

      axisSize() {
        return this.orientation === 'wide' ? containerEl.offsetWidth : containerEl.offsetHeight;
      },

      // Keeps both panes at least SNAP_PX, so a split remembered on a big
      // screen cannot open a pane past usefulness on a small one.
      clampOpen(pct) {
        const min = (SNAP_PX / this.axisSize()) * 100;
        return min >= 50 ? 50 : clamp(pct, min, 100 - min);
      },

      setSplit(pct, animated) {
        this.splitPct = clamp(pct, 0, 100);
        applyPct(gridEl, '--cdash-split', 'cdash-animating', this.splitPct, animated);
      },

      collapse(which) {
        this.mapCollapsed = which === 'map';
        this.browseCollapsed = which === 'browse';
        this.setSplit(which === 'map' ? 0 : 100, true);
        handOffFocus(this, containerEl.querySelector('.cdash-pane-divider'), '.cdash-pane-tab-' + which);
      },

      open() {
        const tab = containerEl.querySelector(this.mapCollapsed ? '.cdash-pane-tab-map' : '.cdash-pane-tab-browse');
        this.mapCollapsed = false;
        this.browseCollapsed = false;
        this.setSplit(this.clampOpen(this.lastOpenPct), true);
        handOffFocus(this, tab, '.cdash-pane-divider');
      },

      // Where a drag or key press comes to rest: shut if either pane ended up
      // under SNAP_PX, otherwise open here, and remember it.
      settle(pct) {
        pct = clamp(pct, 0, 100);
        const size = this.axisSize();
        if ((pct / 100) * size < SNAP_PX) return this.collapse('map');
        if (((100 - pct) / 100) * size < SNAP_PX) return this.collapse('browse');

        this.mapCollapsed = false;
        this.browseCollapsed = false;
        this.lastOpenPct = pct;
        saveLayout('split', pct);
        this.setSplit(pct, false);
      },

      // Shared by the divider and both pane tabs: all of them move the same
      // edge, from wherever it currently is.
      startDrag(e, onClick) {
        if (e.button !== 0) return;
        const startPct = this.splitPct;
        const size = this.axisSize();
        const axisPos = this.orientation === 'wide' ? (ev) => ev.clientX : (ev) => ev.clientY;

        trackDrag(e, axisPos,
          (delta) => this.setSplit(startPct + (delta / size) * 100, false),
          (moved, cancelled) => {
            if (moved) this.settle(this.splitPct);
            else if (!cancelled && onClick) onClick();
          });
      },

      onDividerPointerDown(e) {
        this.startDrag(e, null);
      },

      onTabPointerDown(e) {
        this.startDrag(e, () => this.open());
      },

      onDividerKeydown(e) {
        const dec = this.orientation === 'wide' ? 'ArrowLeft' : 'ArrowUp';
        const inc = this.orientation === 'wide' ? 'ArrowRight' : 'ArrowDown';
        if (e.key !== dec && e.key !== inc) return;
        e.preventDefault();
        const step = e.shiftKey ? KEY_BIG_STEP_PCT : KEY_STEP_PCT;
        this.settle(this.splitPct + (e.key === inc ? step : -step));
      },
    };
  });

  // side: 'start' (Layers, anchored to the Map pane's left/outer edge) or
  // 'end' (Filters, anchored to the Browse pane's right/outer edge). Each
  // drawer always opens/closes along its pane's WIDTH regardless of
  // wide/tall orientation -- per spec the pull-outs are horizontal-only in
  // both cases, so unlike paneResizer this component never switches axis.
  Alpine.data('pullOut', (side) => {
    // pulloutEl/paneEl are deliberately plain closure variables, not
    // `this.` properties on the returned reactive data object. With two
    // sibling instances of this same factory-based component (Layers +
    // Filters), storing DOM element references as reactive properties was
    // observed to corrupt: this.pulloutEl on the first-initialized instance
    // would silently get overwritten to point at the second instance's
    // element sometime shortly after init() (confirmed by comparing against
    // an untouched local variable holding the same original reference,
    // which stayed correct throughout) -- while plain data properties like
    // `side` never showed this. Keeping DOM refs outside Alpine's reactive
    // wrapping entirely sidesteps it, and is arguably the right call anyway
    // since DOM nodes don't need to be reactive.
    let pulloutEl = null;
    let paneEl = null;

    return {
      side,
      openPct: 0,
      lastOpenPct: DEFAULT_OPEN_PCT,
      collapsed: true,
      parentCollapsed: false,

      init() {
        pulloutEl = document.querySelector(
          this.side === 'start' ? '.cdash-pullout-layers' : '.cdash-pullout-filters'
        );
        // paneEl (the parent pane) is the 100%-width reference for drag
        // math and initial sizing -- not pulloutEl, whose own width is
        // only ever a fraction of the pane's.
        paneEl = pulloutEl.parentElement;

        this.lastOpenPct = storedPct(this.storageKey());
        this.setOpen(0, false);

        this.$watch('parentCollapsed', (val) => {
          if (val && !this.collapsed) this.collapse();
        });
      },

      storageKey() {
        return this.side === 'start' ? 'layers' : 'filters';
      },

      axisSize() {
        return paneEl.offsetWidth;
      },

      clampOpen(pct) {
        const min = (SNAP_PX / this.axisSize()) * 100;
        return min >= DRAWER_MAX_PCT ? DRAWER_MAX_PCT : clamp(pct, min, DRAWER_MAX_PCT);
      },

      setOpen(pct, animated) {
        this.openPct = clamp(pct, 0, DRAWER_MAX_PCT);
        applyPct(pulloutEl, '--cdash-pullout-w', 'cdash-pullout-animating', this.openPct, animated);
      },

      collapse() {
        this.collapsed = true;
        this.setOpen(0, true);
        handOffFocus(this, pulloutEl.querySelector('.cdash-pullout-edge'), '#' + pulloutEl.id + ' .cdash-pullout-handle');
      },

      open() {
        this.collapsed = false;
        this.setOpen(this.clampOpen(this.lastOpenPct), true);
        handOffFocus(this, pulloutEl.querySelector('.cdash-pullout-handle'), '#' + pulloutEl.id + ' .cdash-pullout-edge');
      },

      settle(pct) {
        pct = clamp(pct, 0, DRAWER_MAX_PCT);
        if ((pct / 100) * this.axisSize() < SNAP_PX) return this.collapse();

        this.collapsed = false;
        this.lastOpenPct = pct;
        saveLayout(this.storageKey(), pct);
        this.setOpen(pct, false);
      },

      startDrag(e, onClick) {
        if (e.button !== 0) return;
        const startPct = this.openPct;
        const size = this.axisSize();
        // Dragging toward the divider always means "opening", regardless of
        // whether that's a +x or -x pointer motion -- flip the sign per side.
        const sign = this.side === 'start' ? 1 : -1;

        trackDrag(e, (ev) => ev.clientX,
          (delta) => this.setOpen(startPct + ((sign * delta) / size) * 100, false),
          (moved, cancelled) => {
            if (moved) this.settle(this.openPct);
            else if (!cancelled && onClick) onClick();
          });
      },

      onEdgePointerDown(e) {
        this.startDrag(e, null);
      },

      onTabPointerDown(e) {
        this.startDrag(e, () => this.open());
      },

      onEdgeKeydown(e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        const step = e.shiftKey ? KEY_BIG_STEP_PCT : KEY_STEP_PCT;
        const opening = (e.key === 'ArrowRight') === (this.side === 'start');
        this.settle(this.openPct + (opening ? step : -step));
      },
    };
  });

});

// Which page a URL names, as one comparable string. Shared by the breadcrumb
// and the scroll memory below.
//
// Comparing URLs directly does not work here, for two reasons that both bite.
// The Place crumb's href is a PATH ("/s/cdash5/item/13333") while
// what is stored is an absolute href, so string equality is false even for
// the same page. And every URL in the browse pane carries the map hash, which
// cdash-map.js rewrites continuously as the map moves -- so two hrefs for one
// page rarely match either. Path and query identify the page; the hash is the
// map's business, not the breadcrumb's.
function pageKey(url) {
  try {
    const u = new URL(url, window.location.href);
    return u.pathname + u.search;
  } catch (err) {
    return String(url);
  }
}

// ---------------------------------------------------------------------------
// Breadcrumbs in the sticky page header.
//
// Outside alpine:init on purpose: this is not an Alpine component, just a few
// listeners, and it must run whether or not Alpine has booted.
//
// The crumb row above the title holds up to two links, answering different
// questions:
//
//   Place: <title>      what this Document belongs to. A fact about the item,
//                       server-rendered by cdash-page-header.phtml, so it holds
//                       on a shared link and with JS off. Documents only.
//   Return to: <...>    where the visitor came from. Session state, so only
//                       known here, and inserted here.
//
// Each page offers a return only to certain kinds of page:
//
//   on a Document        a Folder or Search Results
//   on a Place           a Folder or Search Results
//   on a Folder          a Place, a Document or Search Results
//   on Search Results    a Place, a Document or a Folder
//
// The return target is the most recent page of an offered kind -- not simply
// the previous page -- so a Document reached from a Folder by way of its Place
// still says "Return to: <that folder>". A Document never offers its own Place
// (the Place crumb already does), so the two links can never name one page.
//
// The server cannot render the return link. Omeka builds each page on its own,
// and Referer is missing or stale on history navigation.
// ---------------------------------------------------------------------------
(function () {
  const RECENT_KEY = 'cdash.recent';
  const MAX_RECENT = 10;

  const OFFERS = {
    document: ['folder', 'search'],
    place: ['folder', 'search'],
    folder: ['place', 'document', 'search'],
    search: ['place', 'document', 'folder'],
  };

  function readRecent() {
    try {
      const v = JSON.parse(sessionStorage.getItem(RECENT_KEY));
      return Array.isArray(v) ? v.filter((e) => e && e.url && e.label && e.kind) : [];
    } catch (err) {
      return [];
    }
  }

  function writeRecent(list) {
    try {
      sessionStorage.setItem(RECENT_KEY, JSON.stringify(list));
    } catch (err) {
      // No breadcrumb this session; everything else still works.
    }
  }

  // What is on screen right now, read from the rendered page rather than
  // re-derived from the URL alone -- the page itself is the one source of
  // truth about what kind of page it is.
  function currentPage() {
    const path = window.location.pathname;
    const header = document.querySelector('.cdash-page-header');
    const title = header ? header.querySelector('h2') : null;
    const name = title ? title.textContent.trim() : '';

    // A folder is remembered by its own name -- "Return to: Harvard Square
    // Survey" says where that is; "Return to: Folder Listing" does not.
    if (/\/item-set\/\d+/.test(path)) {
      return { url: window.location.href, label: name || 'Folder Listing', kind: 'folder' };
    }
    // /item is the theme's search results; /search is AdvancedSearch's page.
    if (/\/(item|search)\/?$/.test(path)) {
      return { url: window.location.href, label: 'Search Results', kind: 'search' };
    }
    if (header && name) {
      // The meta line already says which this is.
      const meta = header.querySelector('.cdash-page-meta');
      const kind = meta && /^\s*Place\b/.test(meta.textContent) ? 'place'
        : meta && /^\s*Document\b/.test(meta.textContent) ? 'document'
        : null;
      if (kind) return { url: window.location.href, label: name, kind: kind };
    }
    return null;
  }

  function render() {
    document.querySelectorAll('.cdash-breadcrumb, .cdash-crumb-sep')
      .forEach((el) => el.remove());

    // The crumbs go in the sticky header, which the item page and both
    // listings render. A site page or the home page has none and gets none.
    const header = document.querySelector('.cdash-page-header');
    const page = currentPage();
    if (!header || !page) return;

    const offers = OFFERS[page.kind] || [];
    const here = pageKey(window.location.href);
    const place = header.querySelector('.cdash-crumb-place');
    const placeKey = place ? pageKey(place.getAttribute('href')) : null;
    const entry = readRecent().find((e) =>
      offers.includes(e.kind) && pageKey(e.url) !== here && pageKey(e.url) !== placeKey);
    if (!entry) return;

    const link = document.createElement('a');
    link.className = 'cdash-breadcrumb';
    link.href = entry.url;
    link.textContent = 'Return to: ' + entry.label;
    // Returning means returning to where the visitor was, not to the top:
    // no scroll modifier here, and the scroll memory below restores depth.
    link.setAttribute('hx-swap', 'outerHTML');
    link.dataset.cdashRestoreScroll = '';

    if (place) {
      const sep = document.createElement('span');
      sep.className = 'cdash-crumb-sep';
      sep.setAttribute('aria-hidden', 'true');
      sep.textContent = '◆';
      place.after(sep);
      sep.after(link);
    } else {
      header.prepend(link);
    }
    // Boost it. On a history restore htmx has already processed the restored
    // pane by the time this runs, so an unprocessed link here would be a plain
    // link -- a full reload that rebuilds the map.
    if (window.htmx) htmx.process(link);
  }

  // Record the page being left, while it is still the page. Most recent
  // first, one entry per page.
  document.body.addEventListener('htmx:beforeRequest', function () {
    const page = currentPage();
    if (!page) return;
    const key = pageKey(page.url);
    const list = readRecent().filter((e) => pageKey(e.url) !== key);
    list.unshift(page);
    writeRecent(list.slice(0, MAX_RECENT));
  });

  // afterSwap covers forward navigation; historyRestore covers Back and
  // Forward, which do NOT fire afterSwap -- the recurring hazard in this
  // theme, where everything that once happened per page now happens per swap.
  document.body.addEventListener('htmx:afterSwap', render);
  document.body.addEventListener('htmx:historyRestore', render);
  render();
})();

// ---------------------------------------------------------------------------
// Scroll memory: going back returns to where the visitor was.
//
// Going FORWARD opens at the top, told to by `scroll:#showresult:top` in the
// swap spec (layout.phtml, and the marker click in cdash-map.js). Unchanged.
//
// Going BACK -- the Back/Forward buttons, or the "Return to ..." breadcrumb --
// opens at the depth the visitor left that page at. htmx 2.0.4 keeps no
// scroll in its history cache, so it is kept here, per pageKey(), in
// sessionStorage. A page with nothing saved opens at the top.
//
// A history restore takes no swap spec: htmx rewrites #showresult's innerHTML
// and leaves its scrollTop as it was, so without this the restored page
// inherited the departing page's offset (clamped to its end when shorter).
//
// Recording: currentKey names the page on screen and is null while a page is
// being replaced -- from beforeRequest or popstate until the new page has
// settled. The departing depth is taken exactly at that moment. Scroll events
// are dispatched at the next frame, after that, so neither the clamp from
// htmx's innerHTML rewrite nor the swap's own scroll to the top can be saved
// under the page being left.
//
// Applying: the position is set at once, again a frame later, and again as
// thumbnails load -- until they have their height the page is too short and
// the browser clamps the position.
// ---------------------------------------------------------------------------
(function () {
  const SCROLL_KEY = 'cdash.scroll';
  const MAX_ENTRIES = 50;
  const SETTLE_MS = 1500;

  let positions = {};
  try {
    positions = JSON.parse(sessionStorage.getItem(SCROLL_KEY)) || {};
  } catch (err) {
    positions = {};
  }
  let currentKey = pageKey(window.location.href);
  let pending = false;
  let saveTimer = null;

  const pane = () => document.getElementById('showresult');

  function persist() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      try {
        sessionStorage.setItem(SCROLL_KEY, JSON.stringify(positions));
      } catch (err) {
        // No scroll memory this session; navigation still works.
      }
    }, 250);
  }

  function remember(key, top) {
    if (!key) return;
    // Re-insert so key order is recency order and the oldest go first.
    delete positions[key];
    positions[key] = Math.round(top);
    const keys = Object.keys(positions);
    for (let i = 0; i < keys.length - MAX_ENTRIES; i++) delete positions[keys[i]];
    persist();
  }

  // The page on screen is about to be replaced: take its depth now.
  function leaving() {
    const p = pane();
    if (p && currentKey) remember(currentKey, p.scrollTop);
    currentKey = null;
  }

  function restore() {
    const p = pane();
    if (!p) return;
    const key = pageKey(window.location.href);
    const top = positions[key] || 0;
    const apply = () => { p.scrollTop = top; };
    apply();
    currentKey = key;
    if (!top) return;
    requestAnimationFrame(apply);
    // Re-apply as images arrive, but stop as soon as the visitor scrolls --
    // or after SETTLE_MS -- so nothing ever pulls the pane out from under them.
    const imgs = [...p.querySelectorAll('img')].filter((img) => !img.complete);
    if (!imgs.length) return;
    const stop = () => {
      imgs.forEach((img) => img.removeEventListener('load', apply));
      p.removeEventListener('wheel', stop);
      p.removeEventListener('touchstart', stop);
      p.removeEventListener('keydown', stop);
    };
    imgs.forEach((img) => img.addEventListener('load', apply));
    p.addEventListener('wheel', stop, { passive: true, once: true });
    p.addEventListener('touchstart', stop, { passive: true, once: true });
    p.addEventListener('keydown', stop, { once: true });
    setTimeout(stop, SETTLE_MS);
  }

  // #showresult is part of the shell and never swapped, so one listener lasts
  // the session.
  const p = pane();
  if (p) {
    p.addEventListener('scroll', function () {
      if (pending) return;
      pending = true;
      requestAnimationFrame(function () {
        pending = false;
        const el = pane();
        if (el && currentKey) remember(currentKey, el.scrollTop);
      });
    }, { passive: true });
  }

  document.body.addEventListener('htmx:beforeRequest', leaving);
  window.addEventListener('popstate', leaving);

  document.body.addEventListener('htmx:historyRestore', restore);

  // A request that fails never settles; the old page is still on screen.
  ['htmx:responseError', 'htmx:sendError', 'htmx:timeout'].forEach(function (name) {
    document.body.addEventListener(name, function () {
      if (!currentKey) currentKey = pageKey(window.location.href);
    });
  });

  document.body.addEventListener('htmx:afterSettle', function (evt) {
    const cfg = evt.detail && evt.detail.requestConfig;
    const src = cfg && cfg.elt;
    if (src && src.matches && src.matches('[data-cdash-restore-scroll]')) {
      restore();
    } else {
      currentKey = pageKey(window.location.href);
    }
  });
})();

// ---------------------------------------------------------------------------
// The map hash is not an anchor: keep it out of htmx request paths.
//
// A form with no action, or action="", submits to the page's own URL. htmx
// fixes a boosted form's path when it processes the form, as
// document.location.href -- and on any page that arrived by a swap, that URL
// already carries the map hash (#zoom/lat/lng/layers, rewritten continuously
// by cdash-map.js). htmx 2.0.4 then treats the hash as an anchor and, after
// the swap, runs querySelector('#13.00/42.37/-71.10/...'): a SyntaxError,
// thrown in the settle step, which also skips `scroll:#showresult:top`. So
// re-sorting a listing threw and left the pane scrolled where it was; the
// AdvancedSearch facets form (<form id="facets-form">) hit the same thing.
//
// htmx splits the anchor off *after* htmx:configRequest, so trimming the path
// here is enough. Only a fragment shaped like the map hash (it contains "/")
// is removed; a real #anchor in a link still scrolls. The map does not need
// the hash in the request: the swap never touches it, and cdash-map.js keeps
// writing it to the address bar.
// ---------------------------------------------------------------------------
document.body.addEventListener('htmx:configRequest', function (evt) {
  const path = evt.detail.path;
  const i = path.indexOf('#');
  if (i >= 0 && path.indexOf('/', i) > i) {
    evt.detail.path = path.slice(0, i);
  }
});
