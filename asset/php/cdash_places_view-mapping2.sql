/* Recreate cdash_places_view for Mapping 2.x. Run once, right after the
   Mapping module upgrade, on any database that has this view.

   The view (and cdash_docs_view, which selects from it) predates the
   temporary tables geosync.php and geoaudit.php use now; nothing in the
   theme reads it, but it is kept for ad hoc SQL. Its 1.x definition joins
   mapping_marker, which Mapping 2.x's upgrade drops. A view over a missing
   table is not just broken -- it makes mysqldump abort ("View ... references
   invalid table(s)", error 1356), so every later database backup fails.

   Same columns as before; lat/lng now come from the Place's point feature.
   DEFINER is kept as it was. Check with:
     SELECT * FROM cdash_docs_view LIMIT 1;   -- errors if either view is broken
*/

CREATE OR REPLACE DEFINER = `omeka`@`%` SQL SECURITY DEFINER VIEW cdash_places_view AS
SELECT resource.id AS place_id, resource.title AS title, value.value AS placetype,
  g.value AS streetName, d.value AS placeName, c.value AS streetAddress, h.value AS houseNum,
  CONCAT(g.value, '_', REPEAT('0', 8 - IFNULL(CHAR_LENGTH(REGEXP_SUBSTR(h.value, '^[0-9]+')), 0)), IFNULL(h.value, '')) AS streetSort,
  ST_Y(mapping_feature.geography) AS lat, ST_X(mapping_feature.geography) AS lng,
  CONCAT(IF(LENGTH(h.value) > 0, CONCAT(h.value, ' '), ''), g.value, IF(d.value IS NULL, '', CONCAT(' - ', d.value))) AS placeItem
FROM resource
LEFT JOIN mapping_feature
  ON resource.id = mapping_feature.item_id
  AND ST_GeometryType(mapping_feature.geography) = 'POINT'
LEFT JOIN value
  ON resource.id = value.resource_id
  AND value.property_id = (SELECT id FROM property WHERE local_name = 'placeType')
LEFT JOIN value d
  ON resource.id = d.resource_id
  AND d.property_id = (SELECT id FROM property WHERE local_name = 'placeName')
LEFT JOIN value c
  ON resource.id = c.resource_id
  AND c.property_id = (SELECT id FROM property WHERE local_name = 'streetAddress')
LEFT JOIN value g
  ON resource.id = g.resource_id
  AND g.property_id = (SELECT id FROM property WHERE local_name = 'streetName')
LEFT JOIN value h
  ON resource.id = h.resource_id
  AND h.property_id = (SELECT id FROM property WHERE local_name = 'houseNum')
WHERE resource.resource_template_id = (SELECT id FROM resource_template WHERE label = 'CDASH Place');
