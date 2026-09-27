/**
 * Builds Leaflet layers from the descriptors in view/cdash/layer-registry.php.
 *
 * cdash-map-5.phtml JSON-encodes the registry into window.CDASH_LAYERS, then calls
 * cdashBuildLayers() to get back a { key: leafletLayer } dictionary -- the same
 * `overlayMaps` object the map code has always used, just no longer hand-written.
 *
 * The one layer this file does not build is 'markerCluster' (allMarkers). Its
 * contents come from an async query against placemarkers.php, so cdash-map-5.phtml
 * constructs it and passes it in as a prebuilt layer.
 */

(function (window) {
  'use strict';

  function buildIcon(iconDef) {
    return L.icon({
      iconUrl: iconDef.url,
      iconSize: iconDef.size,
      iconAnchor: iconDef.anchor,
    });
  }

  /**
   * Leaflet's own attribution option is not picked up reliably by
   * L.GeoJSON.AJAX, so those layers get the same getAttribution override the
   * hand-written 4.1.1 code used.
   */
  function attributionHtml(def) {
    if (!def.attribution) return null;
    return def.attributionUrl
      ? '<a href="' + def.attributionUrl + '">' + def.attribution + '</a>'
      : def.attribution;
  }

  /**
   * onEachFeature handler covering both things the old per-layer closures did:
   * bind a templated popup, and swap in a custom icon for point layers.
   */
  function featureHandler(def) {
    var icon = def.icon ? buildIcon(def.icon) : null;
    return function (feature, layer) {
      if (def.popup) {
        layer.bindPopup(
          L.Util.template(def.popup.template, feature.properties),
          def.popup.options || {}
        );
      }
      if (icon && layer.setIcon) {
        layer.setIcon(icon);
      }
    };
  }

  function buildLayer(def) {
    var attribution = attributionHtml(def);
    var layer;

    switch (def.type) {
      case 'esriDynamic':
        layer = L.esri.dynamicMapLayer({
          url: def.url,
          layers: def.layers || undefined,
          // Retained from 4.1.1: the Cambridge ArcGIS servers predate CORS.
          useCors: false,
          attribution: attribution,
        });
        break;

      case 'esriTiled':
        layer = L.esri.tiledMapLayer({
          url: def.url,
          maxZoom: def.maxZoom,
          attribution: attribution,
        });
        break;

      case 'esriVector':
        layer = L.esri.Vector.vectorTileLayer(def.url);
        break;

      case 'esriFeature':
        layer = L.esri.featureLayer({
          url: def.url,
          style: def.style,
        });
        if (def.popup) {
          layer.bindPopup(function (l) {
            return L.Util.template(def.popup.template, l.feature.properties);
          });
        }
        break;

      case 'tileLayer':
        layer = L.tileLayer(def.url, {
          maxZoom: def.maxZoom,
          attribution: attribution,
        });
        break;

      case 'geoJsonAjax':
        layer = new L.GeoJSON.AJAX(def.url, {
          onEachFeature: featureHandler(def),
          style: def.style,
        });
        if (attribution) {
          layer.getAttribution = function () { return attribution; };
        }
        break;

      case 'markerCluster':
        // Built by the caller -- see the note at the top of this file.
        return null;

      default:
        console.error('CDASH: unknown layer type "' + def.type + '" for key "' + def.key + '"');
        return null;
    }

    return layer;
  }

  /**
   * @param {Array}  registry   window.CDASH_LAYERS
   * @param {Object} prebuilt   { key: layer } for layers the caller made itself
   * @returns {Object}          { key: leafletLayer }
   */
  function cdashBuildLayers(registry, prebuilt) {
    var layers = {};
    prebuilt = prebuilt || {};

    registry.forEach(function (def) {
      if (prebuilt[def.key]) {
        layers[def.key] = prebuilt[def.key];
        return;
      }
      var layer = buildLayer(def);
      if (layer) {
        layers[def.key] = layer;
      }
    });

    return layers;
  }

  window.cdashBuildLayers = cdashBuildLayers;
})(window);
