<?php
namespace Cdash;

/**
 * GeoAudit: counts, and the integrity checks of the CDASH schema. Reads only.
 *
 * Each check is data -- a key, a summary line, an explanation, its SQL and
 * what its rows link to -- so that whatever renders them (geoaudit.php now, a
 * module view later) loops over one list. Each check's SQL returns `id` and
 * `title` columns (or, for a check with no link, whatever it shows), and a
 * check's count is its number of rows.
 */
class Audit
{
    /**
     * @return array{counts:array<string,int>, checks:array<int,array>}
     */
    public static function run(Db $db, Schema $s): array
    {
        WorkingTables::build($db, $s);

        $counts = [
            'Folders' => (int) $db->scalar('SELECT COUNT(*) FROM item_set'),
            'Document Items' => (int) $db->scalar('SELECT COUNT(*) FROM tmp_docs'),
            'Place Items' => (int) $db->scalar('SELECT COUNT(*) FROM tmp_places'),
            'Omeka Media Count' => (int) $db->scalar('SELECT COUNT(*) FROM media'),
            'Items' => (int) $db->scalar('SELECT COUNT(*) FROM item'),
        ];

        $checks = [];
        foreach (self::checks($s) as $check) {
            $check['rows'] = $db->query($check['sql']);
            $check['count'] = count($check['rows']);
            unset($check['sql']);
            $checks[] = $check;
        }
        return ['counts' => $counts, 'checks' => $checks];
    }

    /** The checks, in the order they are reported. */
    public static function checks(Schema $s): array
    {
        $place = $s->template('place');
        $document = $s->template('document');
        $folder = $s->template('folder');
        return [
            [
                'key' => 'places_without_documents',
                'label' => 'Place/s with no associated documents.',
                'explain' => 'Place items may end up with no related Document items for various reasons -- but this is often a problem. Empty Place Items often result if duplicate places were created by accident. When this happens you should look for opportunities to disambiguate Place Item names and look for documents that may have been associated with another place that may have had a duplicate name at some point.',
                'link' => 'item',
                'sql' => "SELECT resource.id, resource.title FROM resource
                    LEFT OUTER JOIN tmp_docs ON resource.id = tmp_docs.place_id
                    WHERE resource.resource_template_id = $place AND tmp_docs.place_id IS NULL",
            ],
            [
                // A marker is a Mapping 2.x point feature; an item whose only
                // features are polygons or lines has no marker on the map.
                'key' => 'places_without_marker',
                'label' => 'Place item/s with no marker.',
                'explain' => 'Every Place item requires a marker. The marker must be created with the item. Create markers for the following places.',
                'link' => 'item',
                'sql' => "SELECT resource.id, resource.title FROM resource
                    LEFT OUTER JOIN mapping_feature ON resource.id = mapping_feature.item_id
                      AND ST_GeometryType(mapping_feature.geography) = 'POINT'
                    WHERE resource.resource_template_id = $place AND mapping_feature.id IS NULL",
            ],
            [
                'key' => 'documents_without_place',
                'label' => 'Document item/s with no place',
                'explain' => 'Every document item requires an associated place. The following items have no associated place.',
                'link' => 'item',
                'sql' => "SELECT resource.id, resource.title FROM resource
                    LEFT OUTER JOIN tmp_docs ON resource.id = tmp_docs.doc_id
                    WHERE resource.resource_template_id = $document AND tmp_docs.place_id IS NULL",
            ],
            [
                // tmp_docs has a row per Place Item value, so a Document with
                // two Places appears twice.
                'key' => 'documents_with_multiple_places',
                'label' => 'Documents reference more than one place item',
                'explain' => 'Documents with more than one place signify a serious problem that will lead to very confusing lost data situations. These should be addressed immediately.',
                'link' => 'item',
                'sql' => "SELECT tmp_docs.doc_id AS id, resource.title FROM tmp_docs
                    LEFT OUTER JOIN resource ON tmp_docs.doc_id = resource.id
                    GROUP BY tmp_docs.doc_id, resource.title
                    HAVING COUNT(DISTINCT tmp_docs.place_id) > 1",
            ],
            [
                'key' => 'documents_without_marker',
                'label' => 'Document item/s with no markers',
                'explain' => 'Every document item requires a marker. The marker must be created with the item. Create markers for the following documents. The position of the marker does not matter, since the location of the marker will be synced with the location of the related Place item.',
                'link' => 'item',
                'sql' => "SELECT resource.id, resource.title FROM resource
                    LEFT OUTER JOIN mapping_feature ON resource.id = mapping_feature.item_id
                      AND ST_GeometryType(mapping_feature.geography) = 'POINT'
                    WHERE resource.resource_template_id = $document AND mapping_feature.id IS NULL",
            ],
            [
                'key' => 'empty_folders',
                'label' => 'Folder/s with no items',
                'explain' => 'Empty folders cause clutter or may indicate data entry problems. Investigate the following folder/s and delete if appropriate.',
                'link' => 'item-set',
                'sql' => "SELECT item_set.id, resource.title FROM item_set
                    LEFT OUTER JOIN item_item_set ON item_set.id = item_item_set.item_set_id
                    LEFT OUTER JOIN resource ON item_set.id = resource.id
                    WHERE item_item_set.item_id IS NULL",
            ],
            [
                'key' => 'duplicate_folder_names',
                'label' => 'Folder name is applied to multiple folders',
                'explain' => 'Folders with duplicate names often represent folders that should be distinguished by research district. Investigate the following folder/s and distinguish their names.',
                'link' => null,
                'sql' => "SELECT resource.title, COUNT(*) AS folders FROM resource
                    WHERE resource.resource_template_id = $folder
                    GROUP BY resource.title HAVING COUNT(*) > 1",
            ],
        ];
    }
}
