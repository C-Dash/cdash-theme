document.addEventListener('alpine:init', () => {

  const COLLAPSED_PX = 8;

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

  Alpine.data('paneResizer', () => ({
    orientation: 'wide',
    splitPct: 50,
    mapCollapsed: false,
    browseCollapsed: false,
    collapsedEdge: null,
    dragState: null,

    init() {
      // Capture the container element here, where Alpine's $el correctly
      // resolves to the x-data root. Handlers triggered by directives on
      // descendants (like the divider's x-on:pointerdown) get $el bound to
      // that descendant instead, so everything below uses these captured refs.
      //
      // containerEl (container-type:size host) is used for size/orientation
      // measurement. gridEl (the nested .cdash-pane-grid) is where
      // --cdash-split and .cdash-animating actually need to live, since the
      // grid's own template lives there -- see styles.css for why the grid
      // isn't on containerEl itself.
      this.containerEl = this.$el;
      this.gridEl = this.containerEl.querySelector('.cdash-pane-grid');
      this.gridEl.style.setProperty('--cdash-split', this.splitPct + '%');

      const onResize = () => {
        const isTall = this.containerEl.offsetHeight >= this.containerEl.offsetWidth;
        const next = isTall ? 'tall' : 'wide';
        if (next !== this.orientation) {
          if (this.dragState) {
            this.dragState = null;
          }
          this.orientation = next;
        }

        // A collapsed pane is pinned to a fixed COLLAPSED_PX, not a fixed
        // percentage -- since --cdash-split is a percentage, its equivalent
        // value drifts whenever the container resizes. Re-pin it here,
        // without animating, so the collapsed footprint stays exactly
        // COLLAPSED_PX regardless of container size.
        if (this.mapCollapsed) {
          this.setSplit(this.pxToPct(COLLAPSED_PX), false);
        } else if (this.browseCollapsed) {
          this.setSplit(100 - this.pxToPct(COLLAPSED_PX), false);
        }
      };

      onResize();
      new ResizeObserver(onResize).observe(this.containerEl);
    },

    axisSize() {
      return this.orientation === 'wide' ? this.containerEl.offsetWidth : this.containerEl.offsetHeight;
    },

    pxToPct(px) {
      return (px / this.axisSize()) * 100;
    },

    pointerAxisPos(e) {
      return this.orientation === 'wide' ? e.clientX : e.clientY;
    },

    setSplit(pct, animated) {
      this.splitPct = Math.max(0, Math.min(100, pct));
      this.gridEl.classList.toggle('cdash-animating', !!animated);
      this.gridEl.style.setProperty('--cdash-split', this.splitPct + '%');
      if (animated) {
        const clear = () => {
          this.gridEl.classList.remove('cdash-animating');
          this.gridEl.removeEventListener('transitionend', clear);
        };
        this.gridEl.addEventListener('transitionend', clear);
      }
    },

    onDividerPointerDown(e) {
      e.target.setPointerCapture(e.pointerId);
      this.dragState = {
        startPos: this.pointerAxisPos(e),
        startSplitPct: this.splitPct,
        axisSize: this.axisSize(),
      };

      const onMove = (moveEvent) => {
        if (!this.dragState) return;
        const delta = this.pointerAxisPos(moveEvent) - this.dragState.startPos;
        const deltaPct = (delta / this.dragState.axisSize) * 100;
        this.setSplit(this.dragState.startSplitPct + deltaPct, false);
      };

      const onUp = (upEvent) => {
        e.target.releasePointerCapture(upEvent.pointerId);
        e.target.removeEventListener('pointermove', onMove);
        e.target.removeEventListener('pointerup', onUp);
        this.finishDrag();
      };

      e.target.addEventListener('pointermove', onMove);
      e.target.addEventListener('pointerup', onUp);
    },

    finishDrag() {
      this.dragState = null;

      if (!this.mapCollapsed && !this.browseCollapsed) {
        if (this.splitPct < 15) {
          this.mapCollapsed = true;
          this.collapsedEdge = 'start';
          this.setSplit(this.pxToPct(COLLAPSED_PX), true);
        } else if (this.splitPct > 85) {
          this.browseCollapsed = true;
          this.collapsedEdge = 'end';
          this.setSplit(100 - this.pxToPct(COLLAPSED_PX), true);
        }
        return;
      }

      const pinnedPct = this.collapsedEdge === 'start' ? this.pxToPct(COLLAPSED_PX) : 100 - this.pxToPct(COLLAPSED_PX);
      if (Math.abs(this.splitPct - pinnedPct) > 20) {
        const releasedPct = this.splitPct;
        this.mapCollapsed = false;
        this.browseCollapsed = false;
        this.collapsedEdge = null;
        this.setSplit(releasedPct, true);
      } else {
        this.setSplit(pinnedPct, true);
      }
    },
  }));

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
      collapsed: true,
      parentCollapsed: false,
      dragState: null,

      init() {
        pulloutEl = document.querySelector(
          this.side === 'start' ? '.cdash-pullout-layers' : '.cdash-pullout-filters'
        );
        // paneEl (the parent pane) is the 100%-width reference for drag
        // math and initial sizing -- not pulloutEl, whose own width is
        // only ever a fraction of the pane's.
        paneEl = pulloutEl.parentElement;

        const onResize = () => {
          // Same reasoning as paneResizer's onResize: a collapsed drawer is
          // pinned to a fixed COLLAPSED_PX, not a fixed percentage, so the
          // equivalent percentage must be recomputed whenever the pane resizes.
          if (this.collapsed) {
            this.setOpen(this.pxToPct(COLLAPSED_PX), false);
          }
        };

        onResize();
        new ResizeObserver(onResize).observe(paneEl);

        this.$watch('parentCollapsed', (val) => {
          if (val && !this.collapsed) {
            this.collapsed = true;
            this.setOpen(this.pxToPct(COLLAPSED_PX), true);
          }
        });
      },

      axisSize() {
        return paneEl.offsetWidth;
      },

      pxToPct(px) {
        return (px / this.axisSize()) * 100;
      },

      setOpen(pct, animated) {
        const collapsedPct = this.pxToPct(COLLAPSED_PX);
        this.openPct = Math.max(collapsedPct, Math.min(95, pct));
        pulloutEl.classList.toggle('cdash-pullout-animating', !!animated);
        pulloutEl.style.setProperty('--cdash-pullout-w', this.openPct + '%');
        if (animated) {
          const clear = () => {
            pulloutEl.classList.remove('cdash-pullout-animating');
            pulloutEl.removeEventListener('transitionend', clear);
          };
          pulloutEl.addEventListener('transitionend', clear);
        }
      },

      onHandlePointerDown(e) {
        e.target.setPointerCapture(e.pointerId);
        this.dragState = {
          startPos: e.clientX,
          startOpenPct: this.openPct,
          axisSize: this.axisSize(),
        };
        // Dragging toward the divider always means "opening", regardless of
        // whether that's a +x or -x pointer motion -- flip the sign per side.
        const sign = this.side === 'start' ? 1 : -1;

        const onMove = (moveEvent) => {
          if (!this.dragState) return;
          const delta = sign * (moveEvent.clientX - this.dragState.startPos);
          const deltaPct = (delta / this.dragState.axisSize) * 100;
          this.setOpen(this.dragState.startOpenPct + deltaPct, false);
        };

        const onUp = (upEvent) => {
          e.target.releasePointerCapture(upEvent.pointerId);
          e.target.removeEventListener('pointermove', onMove);
          e.target.removeEventListener('pointerup', onUp);
          this.finishDrag();
        };

        e.target.addEventListener('pointermove', onMove);
        e.target.addEventListener('pointerup', onUp);
      },

      finishDrag() {
        this.dragState = null;
        const collapsedPct = this.pxToPct(COLLAPSED_PX);

        if (!this.collapsed) {
          if (this.openPct < 15) {
            this.collapsed = true;
            this.setOpen(collapsedPct, true);
          }
          return;
        }

        if (Math.abs(this.openPct - collapsedPct) > 20) {
          this.collapsed = false;
          this.setOpen(this.openPct, true);
        } else {
          this.setOpen(collapsedPct, true);
        }
      },
    };
  });

});
