<?php
namespace Cdash;

/**
 * The two working tables GeoSync and GeoAudit share: one row per Place and
 * one per Document, with the values both tools derive -- the Place Item
 * label, the street sort key, the Document title, the point.
 *
 * Omeka stores every property of every item in the one `value` table, which
 * plain SQL cannot relate directly. These temporary tables flatten what the
 * tools need, once per run, so every later statement is an ordinary join.
 * They live for the connection, which is the request.
 *
 * This is the ONE definition. GeoSync and GeoAudit each used to build their
 * own, and the copies had drifted: geosync made Document titles from
 * tmp_places.placeItem, geoaudit from tmp_places.title.
 *
 * Optionally scoped to some Places: only those Places, and only the
 * Documents linked to them. The scripts always build the whole collection;
 * the planned module will rebuild just the Place being saved.
 *
 *   tmp_places  place_id, title, placetype, streetName, placeName,
 *               streetAddress, houseNum, streetSort, lat, lng, placeItem
 *   tmp_docs    doc_id, place_id, doctype, placeName, doctitle, streetSort,
 *               lat, lng
 *
 * A Place with no point has NULL lat/lng; a Document with no Place has a NULL
 * place_id (unscoped builds only), and so NULL derived values. Mapping 2.x
 * stores points as GEOMETRY, SRID 0, x = longitude, y = latitude.
 */
class WorkingTables
{
    /** @param int[]|null $placeIds */
    public static function build(Db $db, Schema $s, ?array $placeIds = null): void
    {
        $placeScope = '';
        $docScope = '';
        if ($placeIds !== null) {
            $ids = implode(',', array_map('intval', $placeIds ?: [0]));
            $placeScope = "AND resource.id IN ($ids)";
            $docScope = "AND value.value_resource_id IN ($ids)";
        }

        // IDs are integers from Schema, so they are written into the SQL.
        $db->execute('DROP TEMPORARY TABLE IF EXISTS tmp_docs');
        $db->execute('DROP TEMPORARY TABLE IF EXISTS tmp_places');

        $db->execute(sprintf(
            "CREATE TEMPORARY TABLE tmp_places AS
            SELECT resource.id AS place_id, resource.title, value.value AS placetype,
              g.value AS streetName, d.value AS placeName, c.value AS streetAddress, h.value AS houseNum,
              CONCAT(g.value, '_', REPEAT('0', 8 - IFNULL(CHAR_LENGTH(REGEXP_SUBSTR(h.value, '^[0-9]+')), 0)), IFNULL(h.value, '')) AS streetSort,
              ST_Y(mapping_feature.geography) AS lat, ST_X(mapping_feature.geography) AS lng,
              CONCAT(IF(LENGTH(h.value) > 0, CONCAT(h.value, ' '), ''), g.value, IF(d.value IS NULL, '', CONCAT(' - ', d.value))) AS placeItem
            FROM resource
            LEFT OUTER JOIN mapping_feature
              ON resource.id = mapping_feature.item_id
              AND ST_GeometryType(mapping_feature.geography) = 'POINT'
            LEFT OUTER JOIN value ON resource.id = value.resource_id AND value.property_id = %d
            LEFT OUTER JOIN value d ON resource.id = d.resource_id AND d.property_id = %d
            LEFT OUTER JOIN value c ON resource.id = c.resource_id AND c.property_id = %d
            LEFT OUTER JOIN value g ON resource.id = g.resource_id AND g.property_id = %d
            LEFT OUTER JOIN value h ON resource.id = h.resource_id AND h.property_id = %d
            WHERE resource.resource_template_id = %d %s",
            $s->property('cdash:placeType'), $s->property('cdash:placeName'),
            $s->property('cdash:streetAddress'), $s->property('cdash:streetName'),
            $s->property('cdash:houseNum'), $s->template('place'), $placeScope
        ));
        $db->execute('ALTER TABLE tmp_places ADD INDEX (place_id)');

        $db->execute(sprintf(
            "CREATE TEMPORARY TABLE tmp_docs AS
            SELECT resource.id AS doc_id, value.value_resource_id AS place_id, vtype.value AS doctype,
              tmp_places.placeName,
              CONCAT(tmp_places.placeItem, ' - ', vtype.value) AS doctitle,
              CONCAT(tmp_places.streetSort, vtype.value) AS streetSort,
              tmp_places.lat, tmp_places.lng
            FROM resource
            LEFT OUTER JOIN value ON value.resource_id = resource.id AND value.property_id = %d
            LEFT OUTER JOIN value vtype ON vtype.resource_id = resource.id AND vtype.property_id = %d
            LEFT OUTER JOIN tmp_places ON tmp_places.place_id = value.value_resource_id
            WHERE resource.resource_template_id = %d %s",
            $s->property('cdash:placeItem'), $s->property('dcterms:type'),
            $s->template('document'), $docScope
        ));
        $db->execute('ALTER TABLE tmp_docs ADD INDEX (doc_id)');
        $db->execute('ALTER TABLE tmp_docs ADD INDEX (place_id)');
    }
}
