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
