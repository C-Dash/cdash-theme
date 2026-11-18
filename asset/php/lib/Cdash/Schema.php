<?php
namespace Cdash;

/**
 * The CDASH schema's database IDs, resolved once by name.
 *
 * Properties are found by term -- vocabulary prefix and local name -- never by
 * local name alone. Local names are not unique: this install has both
 * dcterms:title and foaf:title, and one more vocabulary with a "type" or a
 * "Neighborhood" would turn every `(SELECT id FROM property WHERE local_name =
 * ...)` subquery into "Subquery returns more than 1 row". Templates are found
 * by label, which Omeka keeps unique.
 *
 * Anything missing is an exception that names it, rather than SQL that quietly
 * matches nothing.
 */
class Schema
{
    public const PROPERTIES = [
        'cdash:placeType', 'cdash:placeItem', 'cdash:placeName', 'cdash:streetAddress',
        'cdash:streetName', 'cdash:houseNum', 'cdash:streetSort', 'cdash:Neighborhood',
        'cdash:chcDist', 'dcterms:title', 'dcterms:type',
    ];

    public const TEMPLATES = [
        'place' => 'CDASH Place',
        'document' => 'CDASH Document',
        'folder' => 'CDASH Folder',
    ];

    /** @var array<string,int> */
    private array $properties = [];

    /** @var array<string,int> */
    private array $templates = [];

    public function __construct(Db $db)
    {
        $marks = implode(',', array_fill(0, count(self::PROPERTIES), '?'));
        foreach ($db->query(
            "SELECT CONCAT(vocabulary.prefix, ':', property.local_name) AS term, property.id
             FROM property JOIN vocabulary ON vocabulary.id = property.vocabulary_id
             WHERE CONCAT(vocabulary.prefix, ':', property.local_name) IN ($marks)",
            self::PROPERTIES
        ) as $row) {
            $this->properties[$row['term']] = (int) $row['id'];
        }
        $missing = array_diff(self::PROPERTIES, array_keys($this->properties));
        if ($missing) {
            throw new \RuntimeException('Properties not found: ' . implode(', ', $missing));
        }

        $labels = array_values(self::TEMPLATES);
        $marks = implode(',', array_fill(0, count($labels), '?'));
        $byLabel = [];
        foreach ($db->query("SELECT label, id FROM resource_template WHERE label IN ($marks)", $labels) as $row) {
            $byLabel[$row['label']] = (int) $row['id'];
        }
        foreach (self::TEMPLATES as $key => $label) {
            if (!isset($byLabel[$label])) {
                throw new \RuntimeException('Resource template not found: ' . $label);
            }
            $this->templates[$key] = $byLabel[$label];
        }
    }

    /** A property's ID, by term ("cdash:placeType"). */
    public function property(string $term): int
    {
        if (!isset($this->properties[$term])) {
            throw new \InvalidArgumentException('Property not in Schema::PROPERTIES: ' . $term);
        }
        return $this->properties[$term];
    }

    /** A template's ID, by key: "place", "document" or "folder". */
    public function template(string $key): int
    {
        if (!isset($this->templates[$key])) {
            throw new \InvalidArgumentException('Template not in Schema::TEMPLATES: ' . $key);
        }
        return $this->templates[$key];
    }
}
