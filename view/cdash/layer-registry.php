<?php
/**
 * CDASH map layer registry -- the single source of truth for every layer on the map.
 *
 * This file is `require`d from a .phtml, so $this is the view and assetUrl() works.
 * It returns an ordered array of layer descriptors consumed in two places:
 *
 *   1. map-tools/overlay-menu.phtml and map-tools/basemap-menu.phtml render their
 *      controls by looping this array.
 *   2. cdash-map-5.phtml JSON-encodes it and asset/js/cdash-layer-factory.js turns
 *      each descriptor into a Leaflet layer.
 *
 * Adding a layer is therefore a single entry here, not an edit in three places.
 *
 * IMPORTANT: 'key' is a hard contract. It is the DOM id of the layer's checkbox or
 * radio, and it is what gets written into the URL hash for shareable map views. Do
 * not rename an existing key -- old links would break.
 *
 * Descriptor fields:
 *   key            required. Unique; used as DOM id and hash token.
 *   group          'basemap' (radio, mutually exclusive) or 'overlay' (checkbox).
 *   label          Control label text.
 *   type           Dispatches in cdash-layer-factory.js. One of:
 *                  esriDynamic, esriTiled, esriVector, esriFeature,
 *                  tileLayer, geoJsonAjax, markerCluster.
 *   url            Service or tile-template URL.
 *   layers         esriDynamic only: array of service layer indices.
 *   attribution    Leaflet attribution link text; attributionUrl is its href.
 *   source         Tooltip credit text; sourceUrl is its href. Omit to skip the
 *                  infodot tooltip entirely.
 *   defaultOn      true to draw at first load when the URL hash says nothing.
 *   hidden         true to build the layer but render no control for it.
 *   style          Vector layers: passed straight to Leaflet as path options.
 *   icon           { url, size: [w,h], anchor: [x,y] } for point layers.
 *   popup          { template, options }. template uses L.Util.template syntax,
 *                  so {PropName} interpolates feature.properties.PropName.
 *   maxZoom        Tile layers.
 */

$cdashMapServer = 'https://gisserver.cambridgema.gov/arcgis/rest/services/CDASH/MapServer';
$cambridgeGis   = 'Cambridge GIS';

/**
 * Most basemaps are the same Esri dynamic service at a different layer index.
 * This keeps those entries to one line each instead of a repeated seven-line block.
 */
$camDynamic = function ($key, $label, $layerIndex, $defaultOn = false)
    use ($cdashMapServer, $cambridgeGis) {
    return [
        'key'            => $key,
        'group'          => 'basemap',
        'label'          => $label,
        'type'           => 'esriDynamic',
        'url'            => $cdashMapServer,
        'layers'         => [$layerIndex],
        'attribution'    => $cambridgeGis,
        'attributionUrl' => $cdashMapServer . '/' . $layerIndex,
        'source'         => $cambridgeGis,
        'sourceUrl'      => $cdashMapServer,
        'defaultOn'      => $defaultOn,
    ];
};

return [

    // ---------------------------------------------------------------- overlays

    [
        'key'       => 'allMarkers',
        'group'     => 'overlay',
        'label'     => 'All CDASH locations',
        'type'      => 'markerCluster',
        'defaultOn' => true,
        // Markers are fetched from asset/php/placemarkers.php and clustered; see
        // queryMarkers() in cdash-map-5.phtml. No url field -- the factory wires
        // this one up specially.
    ],
    [
        'key'       => 'histMarkers',
        'group'     => 'overlay',
        'label'     => 'Historical Markers',
        'type'      => 'geoJsonAjax',
        'url'       => 'https://raw.githubusercontent.com/cambridgegis/cambridgegis_data/master/Historical/Historical_Markers/HISTORICAL_HistoricalMarkers.geojson',
        'icon'      => [
            'url'    => $this->assetUrl('img/blue_oval.png'),
            'size'   => [26, 20],
            'anchor' => [13, 10],
        ],
        'popup'          => ['template' => 'Cambridge Historic Marker:<br>{Name}'],
        'attribution'    => $cambridgeGis,
        'attributionUrl' => 'https://www.cambridgema.gov/GIS/gisdatadictionary/Historical/HISTORICAL_HistoricalMarkers',
        'source'         => $cambridgeGis,
        'sourceUrl'      => 'https://www.cambridgema.gov/GIS/gisdatadictionary/Historical/HISTORICAL_NationalRegisterHistoricPlaces',
    ],
    [
        'key'    => 'parcels',
        'group'  => 'overlay',
        'label'  => 'Property Parcels',
        'type'   => 'esriFeature',
        'url'    => $cdashMapServer . '/13',
        'style'  => [
            'fillColor'   => 'blue',
            'weight'      => 1,
            'opacity'     => 0.5,
            'color'       => 'black',
            'fillOpacity' => 0,
            'zIndex'      => 500,
        ],
        'popup'     => [
            'template' => 'Map-lot <a href="https://www.cambridgema.gov/assess/PropertyDatabase/{ML}" target="_parcel"> {ML}</a>',
        ],
        'source'    => $cambridgeGis,
        'sourceUrl' => 'https://data.cambridgema.gov/Assessing/Assessing-Parcels/rst6-227j',
    ],
    [
        'key'   => 'macrisPoints',
        'group' => 'overlay',
        'label' => 'MACRIS Points',
        'type'  => 'geoJsonAjax',
        'url'   => $this->assetUrl('json/cam_macris_pts.geojson'),
        'icon'  => [
            'url'    => $this->assetUrl('img/reddisk_15px.png'),
            'size'   => [8, 8],
            'anchor' => [4, 4],
        ],
        'popup' => [
            'template' => "{HISTORIC_N}<br><a href='http://mhc-macris.net/details?mhcid={MHCN}' target='_macris'>View MACRIS Record</a>",
        ],
        'attribution'    => 'MA Historical Commission',
        'attributionUrl' => 'https://docs.digital.mass.gov/dataset/massgis-data-mhc-historic-inventory',
        'source'         => 'Massachusetts Cultural Resources Information System',
        'sourceUrl'      => 'https://docs.digital.mass.gov/dataset/massgis-data-mhc-historic-inventory',
    ],
    [
        'key'   => 'natRegister',
        'group' => 'overlay',
        'label' => 'Nat. Register of Historic Places',
        'type'  => 'geoJsonAjax',
        'url'   => 'https://raw.githubusercontent.com/cambridgegis/cambridgegis_data/master/Historical/National_Register_of_Historic_Places/HISTORICAL_NationalRegisterHistoricPlaces.geojson',
        'popup' => [
            'template' => 'Nationally Registered Historic Place: <br>{LabelName}',
            'options'  => ['minWidth' => 500, 'maxWidth' => 700],
        ],
        'attribution'    => $cambridgeGis,
        'attributionUrl' => 'https://www.cambridgema.gov/GIS/gisdatadictionary/Historical/HISTORICAL_NationalRegisterHistoricPlaces',
        'source'         => $cambridgeGis,
        'sourceUrl'      => 'https://www.cambridgema.gov/GIS/gisdatadictionary/Historical/HISTORICAL_HistoricalMarkers',
    ],
    [
        'key'   => 'neighborhoods',
        'group' => 'overlay',
        'label' => 'CDD Neighborhoods',
        'type'  => 'geoJsonAjax',
        'url'   => 'https://data.cambridgema.gov/api/geospatial/k3pi-9823?date=20231108&accessType=DOWNLOAD&method=export&format=GeoJSON',
        'style' => ['color' => 'green'],
        'popup' => ['template' => 'CDD Neighborhood: <br><b>{name}</b>'],
        'source'    => $cambridgeGis,
        'sourceUrl' => 'https://www.cambridgema.gov/GIS/gisdatadictionary/Boundary/BOUNDARY_CDDNeighborhoods',
    ],
    [
        'key'   => 'chcNeighborhoods',
        'group' => 'overlay',
        'label' => 'CHC Survey Areas',
        'type'  => 'geoJsonAjax',
        'url'   => $this->assetUrl('json/chcNeighborhoods.geojson'),
        'style' => ['color' => 'orange'],
        'popup' => ['template' => 'CHC Survey Area:<br><b>{Name}</b>'],
        'source' => 'Created by CDASH Project',
    ],

    // --------------------------------------------------------------- basemaps

    [
        'key'            => 'camBase',
        'group'          => 'basemap',
        'label'          => 'Cambridge Basemap',
        'type'           => 'esriVector',
        'url'            => 'https://tiles.arcgis.com/tiles/WnzC35krSYGuYov4/arcgis/rest/services/CDDVectorBasemap/VectorTileServer',
        'defaultOn'      => true,
        'source'         => $cambridgeGis,
        'sourceUrl'      => 'https://gisserver.cambridgema.gov/arcgis/rest/services/CDDBasemap/MapServer/',
    ],

    $camDynamic('bluesky2023', 'Cambridge 2023 Photo', 12),
    $camDynamic('cam2021',     'Cambridge 2021 Photo', 11),
    $camDynamic('cam2018',     'Cambridge 2018 Photo', 10),
    $camDynamic('cam2010',     'Cambridge 2010 Photo', 7),
    $camDynamic('cam1995',     'Cambridge 1995 Photo', 5),
    $camDynamic('cam1978',     'Cambridge 1978 Photo', 2),
    $camDynamic('cam1969',     'Cambridge 1969 Photo', 3),
    $camDynamic('cam1947',     'Cambridge 1947 Photo', 4),

    [
        'key'            => 'cam1930',
        'group'          => 'basemap',
        'label'          => '1930 Bromley',
        'type'           => 'tileLayer',
        'url'            => 'https://mapwarper.net/mosaics/tile/546/{z}/{x}/{y}.png',
        'maxZoom'        => 20,
        'attribution'    => 'MapWarper.org',
        'attributionUrl' => 'https://mapwarper.net/layers/546',
        'source'         => 'MapWarper.org',
        'sourceUrl'      => 'https://mapwarper.net/layers/546',
    ],

    $camDynamic('bromley1916', '1916 Bromley', 1),

    [
        'key'            => 'bromley1894',
        'group'          => 'basemap',
        'label'          => '1894 Bromley',
        'type'           => 'tileLayer',
        'url'            => 'https://mapwarper.net/mosaics/tile/920/{z}/{x}/{y}.png',
        'maxZoom'        => 20,
        'attribution'    => 'MapJunction / MapWarper',
        'attributionUrl' => 'https://mapwarper.net/layers/920',
        'source'         => 'MapJunction',
        'sourceUrl'      => 'https://mapjunction.com/VIEWER/17810',
    ],
    [
        'key'            => 'hopkins1873',
        'group'          => 'basemap',
        'label'          => '1873 Hopkins',
        'type'           => 'tileLayer',
        'url'            => 'https://s3.us-east-2.wasabisys.com/urbanatlases/39999059015550/tiles/{z}/{x}/{y}.png',
        'maxZoom'        => 20,
        'attribution'    => 'Leventhal Map Center',
        'attributionUrl' => 'https://collections.leventhalmap.org/search/commonwealth:tt44pw432',
        'source'         => 'Leventhal Map and Education Center at Boston Public Library',
        'sourceUrl'      => 'https://collections.leventhalmap.org/search/commonwealth:tt44pw432',
    ],

    $camDynamic('chase1865', '1865 J.G. Chase', 0),

    // ------------------------------------------------- built, but no control

    [
        // The always-on ground layer, pinned to z-index 0 beneath everything else.
        'key'            => 'massGIS',
        'group'          => 'basemap',
        'label'          => 'MassGIS Detailed Features',
        'type'           => 'esriTiled',
        'url'            => 'https://tiles.arcgis.com/tiles/hGdibHYSPO59RG1h/arcgis/rest/services/MassGIS_Basemap_Detailed_Features/MapServer',
        'maxZoom'        => 20,
        'attribution'    => 'MassGIS',
        'attributionUrl' => 'https://www.mass.gov/service-details/massgis-base-map',
        'hidden'         => true,
        'alwaysOn'       => true,
    ],
    [
        // Carried over from 4.1.1, which built this layer and named it as the
        // default basemap but never rendered a control for it and never actually
        // added it to the map. Kept so the key stays resolvable; still no control.
        'key'            => 'camAddBase',
        'group'          => 'basemap',
        'label'          => 'Cambridge Address Basemap',
        'type'           => 'esriDynamic',
        'url'            => 'https://gisserver.cambridgema.gov/arcgis/rest/services/gpvAddress/MapServer',
        'attribution'    => $cambridgeGis,
        'attributionUrl' => 'https://gisserver.cambridgema.gov/arcgis/rest/services/gpvAddress/MapServer',
        'hidden'         => true,
    ],
];
