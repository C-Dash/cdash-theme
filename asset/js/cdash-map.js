/**
 * The CDASH map.
 *
 * Builds the Leaflet map once per session and keeps it alive for the life of
 * the page: navigation swaps only the browse pane, so nothing here is ever torn
 * down and rebuilt. Responsibilities, in order:
 *
 *   - read map state from the URL hash and write it back as the map moves
 *   - build every layer from the registry via cdash-layer-factory.js
 *   - fetch the marker feed once and cluster it
 *   - turn a marker click into an htmx swap of the browse pane
 *   - keep the featured marker in step with whatever the browse pane shows
 *
 * Everything the server has to supply comes from the JSON island rendered by
 * view/cdash/cdash-map-5.phtml; see CDASH_CONFIG below. This file was inline in
 * that template until it reached 480 lines, which meant it could not be linted,
 * could not be cached, and had to be checked by extracting rendered output and
 * counting delimiters. A real marker-click bug survived two review passes that
 * way.
 *
 * Loaded with defer from layout.phtml, after Leaflet, its plugins and
 * cdash-layer-factory.js, all of which this calls into.
 */

function isIE() {
  ua = navigator.userAgent;
  /* MSIE used to detect old browsers and Trident used to newer ones*/
  var is_ie = ua.indexOf("MSIE ") > -1 || ua.indexOf("Trident/") > -1;
  
  return is_ie; 
}
/* Create an alert to show if the browser is IE or not */
if (isIE()){
    alert('CDASH Map will not display in Internet Explorer (latest update in 2013).  Please use a modern browser.');
}else{
    //alert('It is NOT InternetExplorer');
}

// Provides contnt for the CDASH Split map  form. 
$(document).ready(function(){

// Everything PHP has to supply arrives through one JSON island rendered by
// view/cdash/cdash-map-5.phtml: layer definitions, the site URL, asset paths
// for the marker icons, and the marker feed endpoint. Nothing else in this file
// depends on the server.
const CDASH_CONFIG = JSON.parse(
  document.getElementById('cdash-map-config').textContent
);

// Layer definitions live in view/cdash/layer-registry.php -- one entry per layer,
// shared with the overlay and basemap menus so the two can never drift apart.
// asset/js/cdash-layer-factory.js turns each descriptor into a Leaflet layer.
const CDASH_LAYERS = CDASH_CONFIG.layers;

// Marker links are built from whichever site is actually rendering, rather than
// a hardcoded "/s/cdash". 4.1.1 hardcoded the slug, so renaming a site silently
// pointed its markers at a different site.
const CDASH_SITE_URL = CDASH_CONFIG.siteUrl;

// The marker cluster is the one layer the factory cannot build by itself: its
// contents arrive asynchronously from placemarkers.php, so it is constructed
// here and handed to the factory below as a prebuilt layer.
  let allMarkers = L.markerClusterGroup({
    showCoverageOnHover: false,
    maxClusterRadius: function (mapZoom) {
              if (mapZoom > 17) {
                 return 18;
               } else {
                 return 32;
               }
            },
            iconCreateFunction: function(cluster) {
                var clusterSize = "small";
                var iconsize = 35
                if (cluster.getChildCount() >= 3) {
                    clusterSize = "medium";
                    iconsize = 40
                  }
                return new L.DivIcon({
                    html: '<div><span>' + cluster.getChildCount() + '</span></div>',
                    className: 'marker-cluster marker-cluster-' + clusterSize,
                    iconSize: new L.Point(iconsize, iconsize)
                });
            }
  });
    queryMarkers(allMarkers);

// Every other layer is built from the registry.
  let overlayMaps = cdashBuildLayers(CDASH_LAYERS, { allMarkers: allMarkers });

  

// ===========================================================================
// Map state lives in the URL hash:  #zoom/lat/lng/layerKey,layerKey
//
// 4.1.1 kept zoom, centre and layer checkboxes in sessionStorage, but only
// because every click reloaded the document and the map had to be reassembled
// to look like the one just destroyed. Nothing is destroyed now, so that
// storage has no job left -- and putting the state in the URL instead buys
// something sessionStorage never could: a map view you can bookmark, share or
// send to a colleague. "Here is this block in 1947" becomes a link.
//
// Read once, on load. Written continuously thereafter.
//
// Deliberately NOT re-read on Back/Forward: after a history restore the
// featured-marker logic already pans to whatever item was restored, and
// applying an older hash on top of that would mean two things fighting over
// where the map should sit. The restore pans to the item, the resulting
// moveend rewrites the hash, and the URL stays truthful.
// ===========================================================================

const CDASH_DEFAULT_CENTER = [42.377, -71.11];   // Full Cambridge
const CDASH_DEFAULT_ZOOM   = 13.45;

// Only keys the registry actually defines may come out of a hash. A pasted or
// hand-edited URL is untrusted input; an unknown key would otherwise reach
// overlayMaps[key] as undefined and throw inside Leaflet.
const CDASH_LAYER_KEYS  = CDASH_LAYERS.map(function (d) { return d.key; });
const CDASH_BASEMAP_KEYS = CDASH_LAYERS
      .filter(function (d) { return d.group === 'basemap'; })
      .map(function (d) { return d.key; });

function cdashDefaultLayerKeys() {
  return CDASH_LAYERS
    .filter(function (d) { return d.defaultOn; })
    .map(function (d) { return d.key; });
}

function parseMapHash() {
  var raw = window.location.hash.replace(/^#/, '');
  if (!raw) return null;

  var parts = raw.split('/');
  if (parts.length < 3) return null;

  var zoom = parseFloat(parts[0]);
  var lat  = parseFloat(parts[1]);
  var lng  = parseFloat(parts[2]);
  if (!isFinite(zoom) || !isFinite(lat) || !isFinite(lng)) return null;
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;

  // A missing fourth segment means "no opinion about layers" -> use defaults.
  // A present but empty one means "explicitly no layers" -> honour it.
  var layers;
  if (parts.length < 4) {
    layers = cdashDefaultLayerKeys();
  } else {
    layers = parts[3].split(',').filter(function (k) {
      return k && CDASH_LAYER_KEYS.indexOf(k) !== -1;
    });
  }

  return { zoom: zoom, center: [lat, lng], layers: layers };
}

var cdashInitialState = parseMapHash();
var mapCenter    = cdashInitialState ? cdashInitialState.center : CDASH_DEFAULT_CENTER;
var mapZoom      = cdashInitialState ? cdashInitialState.zoom   : CDASH_DEFAULT_ZOOM;
var initialLayers = cdashInitialState ? cdashInitialState.layers : cdashDefaultLayerKeys();

const mapDiv = document.getElementById("drawmap");
// trackResize:false because Leaflet's own window-resize handler calls
// invalidateSize directly, bypassing the COLLAPSED_MAP_FLOOR_PX guard below --
// a window resize while the map pane was collapsed was the one remaining way
// to break camBase. Nothing is lost: the ResizeObserver below sees every
// window resize too, since the pane resizes with the window.
const map = L.map(mapDiv, { center: mapCenter, zoom: mapZoom, maxZoom: 19, trackResize: false });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

// Everything in this file lives inside a $(document).ready closure, so none of
// it is reachable from the browser console. Publishing the map and the state it
// was built from makes the console useful for diagnosis:
//
//     CDASH_MAP.getZoom()        what the map actually did
//     CDASH_MAP_INIT             what the hash asked for
//
// Read-only convenience; nothing here depends on these.
window.CDASH_MAP = map;
window.CDASH_MAP_INIT = {
  hash: window.location.hash,
  parsed: cdashInitialState,
  requestedZoom: mapZoom,
  requestedCenter: mapCenter,
};

/**
 * Keeps Leaflet's idea of the map size in step with the pane.
 *
 * The floor is not an optimisation. Below it the pane is collapsed -- to 0, or
 * mid-drag on its way there -- and telling Leaflet the map is that small is
 * what breaks the camBase basemap: the one esriVector layer in the registry,
 * and so the only one rendering through a WebGL canvas. An open pane never
 * sits below it, because SNAP_PX in cdash-layout.js is larger.
 *
 * maplibre sizes its drawing buffer as floor(pixelRatio * width), where that
 * ratio is clamped against a cached _maxCanvasSize. Resizing into the
 * collapsed sliver makes its painter report overLimit, and maplibre then
 * *overwrites* that cache from the starved context:
 *
 *     this._maxCanvasSize = [gl.drawingBufferWidth, gl.drawingBufferHeight];
 *
 * which pins the cap at roughly [0.6, 0.9]. Every later resize then clamps the
 * ratio to ~0.001 and floors the buffer to 0x0, so the layer draws nothing
 * while its CSS box, its transform and its GL context all still look correct.
 * Nothing recovers it -- not setPixelRatio, not resize, not triggerRepaint;
 * only removing and re-adding the layer, which rebuilds the painter. That line
 * is still in maplibre's current main, so a vendor upgrade would not help.
 *
 * Leaflet caches _size and re-reads it only when invalidateSize sets
 * _sizeChanged, so skipping the call keeps the last good size rather than
 * deferring the problem. Both axes are guarded because the tall-case layout
 * collapses the pane by height rather than width.
 *
 * This is prevention, not recovery: a poisoned canvas still needs a reload.
 * The collapse redesign kept it rather than detect-and-rebuild -- a pane that
 * collapses to 0 needs it more, not less, so do not delete it.
 */
const COLLAPSED_MAP_FLOOR_PX = 50;

const resizeObserver = new ResizeObserver(() => {
  if (mapDiv.clientWidth < COLLAPSED_MAP_FLOOR_PX ||
      mapDiv.clientHeight < COLLAPSED_MAP_FLOOR_PX) {
    return;
  }
  map.invalidateSize();
});
resizeObserver.observe(mapDiv);

// Always-on ground layer, pinned beneath everything else. Not a hash token --
// it is never switchable, so there is nothing to record.
map.addLayer(overlayMaps.massGIS);
overlayMaps.massGIS.setZIndex(0);

let $layerPickers = $("#overlay-menu :checkbox, #basemap-menu :radio");

function activeLayerKeys() {
  var keys = [];
  $layerPickers.each(function () {
    if (this.checked) keys.push(this.id);
  });
  return keys;
}

/**
 * Drives both the controls and the map from a list of keys.
 *
 * Leaflet's addLayer/removeLayer are idempotent, so this can be applied
 * wholesale without diffing against the previous state the way 4.1.1 did --
 * which is also what makes basemap switching fall out for free: the browser
 * unchecks the sibling radio, and the now-unchecked layer gets removed on the
 * same pass that adds the newly checked one.
 */
function applyLayerState(keys) {
  // Guard against a hand-edited URL naming two basemaps. The radios can only
  // represent one, so honour the first and drop the rest -- otherwise the map
  // would show two while the controls showed one.
  var seenBasemap = false;
  var wanted = keys.filter(function (k) {
    if (CDASH_BASEMAP_KEYS.indexOf(k) === -1) return true;
    if (seenBasemap) return false;
    seenBasemap = true;
    return true;
  });

  $layerPickers.each(function () {
    var on = wanted.indexOf(this.id) !== -1;
    this.checked = on;

    var layer = overlayMaps[this.id];
    if (!layer) return;
    if (on) {
      map.addLayer(layer);
    } else {
      map.removeLayer(layer);
    }
  });
}

function writeMapHash() {
  var c = map.getCenter();
  var hash = '#' + map.getZoom().toFixed(2)
           + '/' + c.lat.toFixed(5)
           + '/' + c.lng.toFixed(5)
           + '/' + activeLayerKeys().join(',');

  // replaceState, not pushState: panning the map must not pile up history
  // entries. Back should step through items, not through every mouse drag.
  //
  // history.state is passed through untouched -- htmx keeps its restore
  // information there, and dropping it would break Back after a swap.
  history.replaceState(
    history.state,
    '',
    window.location.pathname + window.location.search + hash
  );
}

/**
 * Coalesces bursts of hash writes.
 *
 * Dragging the pane divider resizes #drawmap, the ResizeObserver calls
 * invalidateSize on every frame, and Leaflet fires moveend from every
 * invalidateSize where the size actually changed -- so a two-second drag would
 * otherwise issue well over a hundred replaceState calls. Safari and Firefox
 * both rate-limit the history API and start dropping or throwing at that
 * volume. Only the settled position is worth recording, so wait for the burst
 * to stop before writing once.
 */
var cdashHashTimer = null;
function scheduleMapHashWrite() {
  if (cdashHashTimer) clearTimeout(cdashHashTimer);
  cdashHashTimer = setTimeout(function () {
    cdashHashTimer = null;
    writeMapHash();
  }, 250);
}

applyLayerState(initialLayers);

$layerPickers.on("change", function () {
  applyLayerState(activeLayerKeys());
  writeMapHash();
});

// Keep the URL in step with the map. moveend also fires after a programmatic
// panTo, so the featured-marker pan updates the hash too, with no extra wiring.
// Debounced -- these arrive in bursts during a drag; see scheduleMapHashWrite.
map.on('moveend', scheduleMapHashWrite);
map.on('zoomend', scheduleMapHashWrite);

// htmx rewrites the URL on every swap, and it pushes the request path only --
// no fragment -- so the map hash would be dropped on each navigation. Put it
// back once htmx has finished writing history. Written immediately rather than
// debounced: this fires once per navigation, and leaving the URL fragment-less
// for even a moment means a copied link loses the map view.
document.body.addEventListener('htmx:pushedIntoHistory', writeMapHash);
document.body.addEventListener('htmx:replacedInHistory', writeMapHash);


// ---------------------------------------------------------------------------
// Find my location.
//
// A page cannot tell whether a device's GPS is switched on, so "show it when
// GPS is engaged" becomes the nearest thing that can be known: a touch device,
// on a secure page (geolocation is refused elsewhere), whose location
// permission has not been denied. A GPS that turns out to be off surfaces as a
// locationerror on tap, and gets a message rather than a hidden button.
//
// Touch only, on purpose: desktop positions come from Wi-Fi and are often
// hundreds of metres out -- misleading at survey zoom.
//
// One tap starts watching and centres on the first fix. The dot then follows
// the device but the map does not, so the user can browse around it. Tap again
// to re-centre; tap while already centred to stop -- the Google/Apple Maps
// idiom. Nothing is stored and there is no hash token; centring fires moveend,
// which writes the hash like any other move.
// ---------------------------------------------------------------------------
var CDASH_LOCATE_MAX_ZOOM = 18;

var cdashCanLocate = 'geolocation' in navigator
  && window.isSecureContext
  && window.matchMedia('(pointer: coarse)').matches;

if (cdashCanLocate) {
  var LocateControl = L.Control.extend({
    options: { position: 'topleft' },

    onAdd: function (map) {
      var container = L.DomUtil.create('div', 'leaflet-bar cdash-locate');
      var button = L.DomUtil.create('button', 'cdash-locate-button', container);
      button.type = 'button';
      button.title = 'Show my location';
      button.setAttribute('aria-label', 'Show my location');
      button.setAttribute('aria-pressed', 'false');

      var message = L.DomUtil.create('div', 'cdash-locate-message', container);
      message.setAttribute('role', 'status');
      message.hidden = true;

      L.DomEvent.disableClickPropagation(container);

      var dot = L.circleMarker([0, 0], {
        radius: 7, weight: 2, color: '#fff', fillColor: '#1a73e8', fillOpacity: 1,
        interactive: false,
      });
      var accuracy = L.circle([0, 0], {
        radius: 0, weight: 1, color: '#1a73e8', fillColor: '#1a73e8', fillOpacity: 0.12,
        interactive: false,
      });
      var marks = L.layerGroup([accuracy, dot]);

      var active = false;     // watching the device position
      var following = false;  // map is centred on the latest fix
      var lastFix = null;
      var messageTimer = null;

      function setState() {
        L.DomUtil[active ? 'addClass' : 'removeClass'](button, 'is-active');
        L.DomUtil[following ? 'addClass' : 'removeClass'](button, 'is-following');
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      }

      function showMessage(text) {
        message.textContent = text;
        message.hidden = false;
        clearTimeout(messageTimer);
        messageTimer = setTimeout(function () { message.hidden = true; }, 6000);
      }

      function centre() {
        if (!lastFix) return;
        // Fit the accuracy circle, but never closer than the cap: a precise
        // fix should not dive to maxZoom.
        var zoom = Math.min(CDASH_LOCATE_MAX_ZOOM, map.getBoundsZoom(lastFix.bounds));
        following = true;
        map.setView(lastFix.latlng, zoom);
        setState();
      }

      function start() {
        active = true;
        following = false;
        lastFix = null;
        setState();
        map.locate({ watch: true, setView: false, enableHighAccuracy: true });
      }

      function stop() {
        map.stopLocate();
        map.removeLayer(marks);
        active = false;
        following = false;
        lastFix = null;
        setState();
      }

      L.DomEvent.on(button, 'click', function () {
        message.hidden = true;
        if (!active) start();
        else if (!following) centre();
        else stop();
      });

      L.DomEvent.on(message, 'click', function () { message.hidden = true; });

      map.on('locationfound', function (e) {
        if (!active) return;
        var first = !lastFix;
        lastFix = e;
        dot.setLatLng(e.latlng);
        accuracy.setLatLng(e.latlng).setRadius(e.accuracy);
        if (!map.hasLayer(marks)) map.addLayer(marks);
        if (first) centre();
      });

      map.on('locationerror', function (e) {
        stop();
        // e.code follows GeolocationPositionError: 1 denied, 2 unavailable,
        // 3 timeout.
        if (e.code === 1) {
          showMessage('Location access is blocked for this site.');
        } else {
          showMessage('Your location is not available. Check that location services are on.');
        }
      });

      // The user moving the map themselves ends "following", so the next tap
      // re-centres instead of stopping.
      map.on('dragstart', function () {
        if (following) {
          following = false;
          setState();
        }
      });

      // Hide the control once permission is denied, and bring it back if it is
      // granted again from the browser's settings. Not every browser supports
      // querying geolocation, so failure just leaves the button showing.
      if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then(function (status) {
          var apply = function () {
            container.hidden = status.state === 'denied';
            if (status.state === 'denied' && active) stop();
          };
          apply();
          status.addEventListener('change', apply);
        }).catch(function () {});
      }

      return container;
    },
  });

  map.addControl(new LocateControl());
}


// ---------------------------------------------------------------------------
// Featured marker: the red dot for whatever the browse pane is showing.
//
// The Mapping module writes coordinates into the item's HTML as a
// .mapping-marker-popup-content div. In 4.1.1 this ran once, at page load,
// because every marker click reloaded the document. The browse pane is now
// swapped in place, so there is no reload to hang it on -- this is a function
// called both on first load and after every swap.
// ---------------------------------------------------------------------------

  var featuredIcon = L.icon({
          iconUrl:  CDASH_CONFIG.icons.featured,
           iconSize: [22,22],
           iconAnchor: [11,11]
        });

  let featuredMarkers = new L.FeatureGroup();
  map.addLayer(featuredMarkers);

function updateFeaturedMarker() {
  var $popups = $('#content .mapping-marker-popup-content');

  if ($popups.length === 0) {
    // A site page, or an item with no location. Leave the previous marker
    // alone. 4.1.1 needed mkrLat/mkrLon in sessionStorage to rebuild it after
    // the reload wiped the map; nothing wipes it now, so the marker simply
    // stays where it is and those two keys are gone.
    console.log("No coordinates in this page. Keeping the previous featured marker.");
    return;
  }

  featuredMarkers.clearLayers();
  var markerCenter = null;

  $popups.each(function() {
      var popup = $(this).clone().show();
      var lat = popup.data('marker-lat');
      var lng = popup.data('marker-lng');
      markerCenter = [lat, lng];

      var featuredMarker = new L.Marker(new L.LatLng(lat, lng));
      featuredMarker.bindPopup(popup[0]);
      featuredMarker.setIcon(featuredIcon);
      featuredMarker.setZIndexOffset(2000);
      featuredMarkers.addLayer(featuredMarker);
  });

  if (markerCenter) {
    // 4.1.1 called panTo(center, mapZoom). panTo's second argument is an
    // options object, not a zoom level, so the zoom was never actually
    // applied -- panning at the current zoom is what it always did.
    map.panTo(markerCenter);
  }

  featuredMarkers.bringToFront();
}

// ---------------------------------------------------------------------------
// Fixups applied to swapped-in content.
//
// This lived as an inline <script> at the bottom of item/show.phtml. htmx does
// execute scripts inside swapped content, so it would probably still have
// fired -- but relying on that is relying on swap semantics for something that
// is really just a DOM fixup. Calling it explicitly makes the order certain
// and puts every post-swap concern in one place.
// ---------------------------------------------------------------------------
function applyContentFixups() {
  // The media-embed block wraps each image in a link to the original TIFF.
  // Removing the href leaves the right-click menu usable for saving or opening
  // the thumbnail, which is what CDASH wants.
  var images = document.querySelectorAll("#content .media-render.file a, #content .media-render a");
  images.forEach(function (anchor) {
    anchor.removeAttribute("href");
  });
}

function refreshFromBrowsePane() {
  updateFeaturedMarker();
  applyContentFixups();
}

refreshFromBrowsePane();

// A forward navigation -- marker click, boosted link, search -- fires afterSwap.
document.body.addEventListener('htmx:afterSwap', function (evt) {
  refreshFromBrowsePane();
});

// Back/Forward does NOT. htmx restores from its own history cache and fires
// historyRestore instead, so the comment that used to sit above afterSwap
// claiming it "catches all of them" was wrong: the featured marker stayed on
// whatever item was showing before the restore.
document.body.addEventListener('htmx:historyRestore', function (evt) {
  refreshFromBrowsePane();
});


//This function uses a call to a php script that queries omeka's mysql database.
//The result is a list of all of the CDASH Location items with their titles, 
//coordinates and Item ID.  Maybe this should be done through the Omeka API or 
//Doctrine or something.  
function queryMarkers(markerGroup) {
  $.ajax({
         url : CDASH_CONFIG.markersUrl,
         type: "POST",
         dataType: 'json',
         //data: form.serialize(),
        success:function(response) {
        if(response.success == true ) {
            console.log("Query succeeded.  SQL: " + response.sql + "<br> Searchterm: " + response.searchterm );
            //printTable(response.resp)

            pushMarkers(response.resp, markerGroup);
            return (response.resp);
          } 
        //if(response.success == false ) {
        //    msg.html("Query failed with:");
        //   }
        if(response.error == true ) {
            console.log("Query failed SQL:" + response.sql + "<br> Searchterm: " + response.searchterm );
          }else{console.log("No apparent error with markerquery: " + this.url);};
        
        }, //Ajax options

        error:function(xhr,textStatus,errorThrown){
            var str = "ERROR : SERVER error " + xhr + " " + 
                       textStatus + " " + errorThrown ;
             console.log(str);
             //console.log("Help im a rock");
          } // error callback function block
        }); // ajax call ends
} //closes querymarkers function


var sepiaBalloon = L.icon({
  iconUrl: CDASH_CONFIG.icons.marker,
   iconSize: [30,30],
   iconAnchor: [14,26]
});

var sepiaBalloon_halo_y = L.icon({
  iconUrl: CDASH_CONFIG.icons.markerHighlight,
   iconSize: [28,28],
   iconAnchor: [14,26]
});


// Takes the result from the SQL query and makes a marker for each.
function pushMarkers(json_response, markerGroup){
  var cambMarkerList = [];
  for (i = 0; i < json_response.length; i++) {
    var row = json_response[i];
    var title = row['title'] ;
    var item_id =  row['item_id'];
    var marker = L.marker(L.latLng(row['lat'], row['lng']), { title: title });
    marker.id = row['item_id'];
    marker.item_url = CDASH_SITE_URL + "/item/" + item_id;
    marker.title = row['title'];
    marker.setIcon(sepiaBalloon);
    if (row['placetype'] != "Address") {
      marker.setIcon(sepiaBalloon_halo_y);
    }
    //marker.setZIndexOffset(1000);

  // 'click' the new marker to call onMarkerClick function (Loads page )
    marker.on('click', onMarkerClick);
    cambMarkerList.push(marker);
  }
    //console.log('start clustering: ' + window.performance.now());
    markerGroup.addLayers(cambMarkerList);
    console.log('pushed cambridge markers')
} // closes pushmarkers function




// This function is what the whole persistent-map change turns on. 4.1.1 called
// window.location.replace(), which tore down the document and rebuilt the map
// -- every layer constructor re-run and the full marker query re-issued -- just
// to show a different item in the other pane. Requesting the swap directly
// replaces only #content, and the map is never touched.
//
// An earlier attempt routed this through a hidden boosted anchor, setting its
// href before clicking it. That cannot work: htmx reads a boosted anchor's href
// once, when it first processes the DOM, and closes over that value --
//
//     if (t.tagName === "A") { r = "get"; o = ee(t, "href") }
//
// -- so the request always went wherever the href pointed at page load,
// whatever the href said by the time it was clicked.
//
// htmx.ajax takes the path as an argument, so there is nothing to go stale.
// `source` is not where the request goes; it is the element htmx resolves
// inherited hx-* attributes from. Passing an element inside the shell is what
// picks up hx-push-url="true" and hands history management to htmx, which is
// the whole reason the anchor existed. mapDiv is genuinely the origin of the
// click, since the marker lives on the map.
function onMarkerClick(e) {
  var url = e.target.item_url;

  if (!window.htmx) {   // htmx missing or failed to load -- navigate the old way.
    window.location.assign(url);
    return;
  }

  // Mirrors the hx-* attributes on .cdash-app-shell in layout.phtml. Keep the
  // swap spec in step with it, including the scroll modifier that returns the
  // browse pane to the top on each new item.
  htmx.ajax('GET', url, {
    source: mapDiv,
    target: '#content',
    select: '#content',
    swap: 'outerHTML scroll:#showresult:top'
  });
}

}); // $(document).ready(function() ends
