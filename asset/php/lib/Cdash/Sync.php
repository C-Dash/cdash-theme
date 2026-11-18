<?php
namespace Cdash;

/**
 * GeoSync: make every Place's derived values, and every Document's copies of
 * its Place, true again.
 *
 *   Place     placeItem value and title, from houseNum, streetName, placeName;
 *             streetSort, from streetName and houseNum
 *   Document  point, from its Place's point;
 *             dcterms:title value and title, "<placeItem> - <dcterms:type>";
 *             streetSort, the Place's plus dcterms:type;
 *             every DOCUMENT_INHERITS property, copied from its Place
 *
 * Set-based SQL over the working tables, all in one transaction: a failure
 * part-way leaves nothing half-applied. (The old script deleted every copied
 * value and then re-inserted them, statement by statement, outside a
 * transaction.)
 *
 * Copies are synced by difference: a Document value is removed only when its
 * Place has no identical value, and added only when the Document lacks it.
 * Identical means the same type, value, language, URI and linked resource,
 * and a copy keeps the Place value's type and visibility. So an unchanged
 * collection reports no changes, and value IDs do not churn on every run.
 *
 * A derived value that comes out NULL -- a Place with no streetName, a
 * Document with no Place -- is left alone rather than written, where the old
 * script blanked the title.
 *
 * Scoped by Place IDs when given (see WorkingTables). Writes directly, so it
 * bypasses Omeka: the caller must re-index full-text search afterwards when
 * anything changed (see geosync.php).
 */
class Sync
{
    /** Properties every Document copies from its Place, by term. */
    public const DOCUMENT_INHERITS = [
        'cdash:Neighborhood' => 'Neighborhood',
        'cdash:chcDist' => 'Survey Area',
    ];

    /**
     * @param int[]|null $placeIds
     * @return array{places:int, documents:int, changes:array<string,int>, changed:int}
     */
    public static function run(Db $db, Schema $s, ?array $placeIds = null): array
    {
        WorkingTables::build($db, $s, $placeIds);
        $summary = [
            'places' => (int) $db->scalar('SELECT COUNT(*) FROM tmp_places'),
            'documents' => (int) $db->scalar('SELECT COUNT(*) FROM tmp_docs'),
        ];

        $changes = $db->transactional(function (Db $db) use ($s) {
            $changes = [];
            foreach (self::steps($s) as $label => $sql) {
                $changes[$label] = $db->execute($sql);
            }
            foreach (self::DOCUMENT_INHERITS as $term => $name) {
                $p = $s->property($term);
                $changes["$name values removed from Documents"] = $db->execute(self::removeStale($p));
                $changes["$name values copied to Documents"] = $db->execute(self::addMissing($p));
            }
            return $changes;
        });

        $summary['changes'] = $changes;
        $summary['changed'] = array_sum($changes);
        return $summary;
    }

    /** The derived-value updates, in order, keyed by what they report. */
    private static function steps(Schema $s): array
    {
        $placeItem = $s->property('cdash:placeItem');
        $streetSort = $s->property('cdash:streetSort');
        $title = $s->property('dcterms:title');
        return [
            // An unchanged point is byte-identical, so it does not count. A
            // Document with no point is not given one; GeoAudit reports it.
            'Document points moved to their Place' =>
                "UPDATE mapping_feature
                 JOIN tmp_docs ON mapping_feature.item_id = tmp_docs.doc_id
                   AND tmp_docs.lat IS NOT NULL AND tmp_docs.lng IS NOT NULL
                 SET mapping_feature.geography = POINT(tmp_docs.lng, tmp_docs.lat)
                 WHERE ST_GeometryType(mapping_feature.geography) = 'POINT'",
            'Place Item values updated on Places' =>
                "UPDATE value JOIN tmp_places ON value.resource_id = tmp_places.place_id
                 SET value.value = tmp_places.placeItem
                 WHERE value.property_id = $placeItem AND tmp_places.placeItem IS NOT NULL",
            'Place titles updated' =>
                "UPDATE resource JOIN tmp_places ON resource.id = tmp_places.place_id
                 SET resource.title = tmp_places.placeItem
                 WHERE tmp_places.placeItem IS NOT NULL",
            'Title values updated on Documents' =>
                "UPDATE value JOIN tmp_docs ON value.resource_id = tmp_docs.doc_id
                 SET value.value = tmp_docs.doctitle
                 WHERE value.property_id = $title AND tmp_docs.doctitle IS NOT NULL",
            'Document titles updated' =>
                "UPDATE resource JOIN tmp_docs ON resource.id = tmp_docs.doc_id
                 SET resource.title = tmp_docs.doctitle
                 WHERE tmp_docs.doctitle IS NOT NULL",
            'StreetSort updated on Places' =>
                "UPDATE value JOIN tmp_places ON value.resource_id = tmp_places.place_id
                 SET value.value = tmp_places.streetSort
                 WHERE value.property_id = $streetSort AND tmp_places.streetSort IS NOT NULL",
            'StreetSort updated on Documents' =>
                "UPDATE value JOIN tmp_docs ON value.resource_id = tmp_docs.doc_id
                 SET value.value = tmp_docs.streetSort
                 WHERE value.property_id = $streetSort AND tmp_docs.streetSort IS NOT NULL",
        ];
    }

    // A value "matches" another when every part Omeka stores of it is equal;
    // <=> is MySQL's NULL-safe equality, since lang and uri are usually NULL.
    private const MATCH = 'AND %1$s.type <=> %2$s.type AND %1$s.value <=> %2$s.value
        AND %1$s.lang <=> %2$s.lang AND %1$s.uri <=> %2$s.uri
        AND %1$s.value_resource_id <=> %2$s.value_resource_id';

    /**
     * Document values of property $p that its Place does not have. A Document
     * with no Place keeps none, as before. LEFT JOIN rather than NOT EXISTS:
     * MySQL refuses a subquery on the table a DELETE is deleting from.
     */
    private static function removeStale(int $p): string
    {
        return "DELETE dv FROM value dv
            JOIN tmp_docs ON tmp_docs.doc_id = dv.resource_id
            LEFT JOIN value pv ON pv.resource_id = tmp_docs.place_id AND pv.property_id = dv.property_id "
            . sprintf(self::MATCH, 'pv', 'dv') . "
            WHERE dv.property_id = $p AND pv.id IS NULL";
    }

    /** Place values of property $p that its Document lacks. */
    private static function addMissing(int $p): string
    {
        return "INSERT INTO value (resource_id, property_id, value_resource_id, type, lang, value, uri, is_public)
            SELECT DISTINCT docs.doc_id, pv.property_id, pv.value_resource_id, pv.type, pv.lang, pv.value, pv.uri, pv.is_public
            FROM (SELECT DISTINCT doc_id, place_id FROM tmp_docs WHERE place_id IS NOT NULL) docs
            JOIN value pv ON pv.resource_id = docs.place_id AND pv.property_id = $p
            LEFT JOIN value dv ON dv.resource_id = docs.doc_id AND dv.property_id = $p "
            . sprintf(self::MATCH, 'dv', 'pv') . "
            WHERE dv.id IS NULL";
    }
}
