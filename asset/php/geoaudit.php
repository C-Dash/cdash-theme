<?php
// This little program updates the coordinates for each document item with the coordinates
// of each document's  associated place_item.
//
// geoaudit_fast.php: uses temporary tables instead of views so the expensive
// multi-join SELECT against the value table runs once, not once per query.

// Must come first: the guard needs to set a response code before any output.
// This script only reads, but it reports across the whole collection and has no
// business answering anonymous requests.
require __DIR__ . '/_require-admin.php';

echo "<head><title>GeoAudit</title></head>";

echo "<h1>GeoAudit: Check Integrity of CDASH Schema</h1>";

$host = $_SERVER['HTTP_HOST'];

echo " <head><style> table, th, td {
  /*border: 1px solid black;*/
  border-collapse: collapse;
  padding: 5px;
} </style></head>";



// Get database connection parameters
$ini_array = parse_ini_file("/var/www/html/persist/config/database.ini");
$servername = $ini_array['host'];
$username = $ini_array['user'];
$password = $ini_array['password'];
$dbname = $ini_array['dbname'];


$output = array( 'success' => false, 'error' => null,
                 'resp' => array(), 'sql' => null, 'searchterm' => null);
// Create connection
$conn = new mysqli($servername, $username,
              $password, $dbname);

// Check connection
if ($conn->connect_error) {
    $output["error"] = "Unable to connect with server: " . $conn->connect_error . "<br>";
die("Connection failed: " . $conn->connect_error . "<br>");
}

// Materialize tmp_places and tmp_docs once so all audit queries below share the result
// rather than re-evaluating the view joins on every reference.

$makeplace_sql = "CREATE TEMPORARY TABLE tmp_places AS
SELECT resource.id as place_id, resource.title, value.value as placetype, g.value as streetName, d.value as placeName, c.value as streetAddress, h.value as houseNum,
CONCAT(g.value,'_', REPEAT('0',8 - IFNULL(char_length(REGEXP_SUBSTR(h.value,'^[0-9]+')), 0)),IFNULL(h.value, '')) as streetSort,
lat, lng, CONCAT(IF(LENGTH(h.value) > 0, CONCAT(h.value, ' '), ''), g.value, IF(d.value IS NULL,'',CONCAT(' - ',d.value))) as placeItem
FROM resource
LEFT OUTER JOIN mapping_marker
  ON resource.id = mapping_marker.item_id
LEFT OUTER JOIN value
  on resource.id = resource_id
  AND property_id = (select id from property where local_name = 'placeType')
LEFT OUTER JOIN value d
  on resource.id = d.resource_id
  AND d.property_id = (select id from property where local_name = 'placeName')
LEFT OUTER JOIN value c
  on resource.id = c.resource_id
  AND c.property_id = (select id from property where local_name = 'streetAddress')
LEFT OUTER JOIN value g
  on resource.id = g.resource_id
  AND g.property_id = (select id from property where local_name = 'streetName')
LEFT OUTER JOIN value h
  on resource.id = h.resource_id
  AND h.property_id = (select id from property where local_name = 'houseNum')
WHERE resource_template_id = (select id from resource_template where label = 'CDASH Place');";

if (!$conn->query($makeplace_sql)) {
    die("<span style='color:red'>Error creating tmp_places: " . $conn->error . "<br></span>");
}
$conn->query("ALTER TABLE tmp_places ADD INDEX (place_id)");

$make_doc_sql = "CREATE TEMPORARY TABLE tmp_docs AS
select resource.id as doc_id, value.value_resource_id as place_id, tmp_places.placeName as placeName, concat(tmp_places.title, ' - ', vtype.value) as doctitle, concat(tmp_places.streetSort,vtype.value) as streetSort, tmp_places.lat, tmp_places.lng
from resource
left outer join value on value.resource_id = resource.id and value.property_id = (select id from property where local_name = 'placeItem')
left outer join value vtype on vtype.resource_id = resource.id
  AND vtype.property_id = (select id from property where local_name = 'type')
left outer JOIN tmp_places on tmp_places.place_id = value.value_resource_id
WHERE resource.resource_template_id = (select resource_template.id from resource_template where label = 'CDASH Document');";

if (!$conn->query($make_doc_sql)) {
    die("<span style='color:red'>Error creating tmp_docs: " . $conn->error . "<br></span>");
}
$conn->query("ALTER TABLE tmp_docs ADD INDEX (doc_id)");
$conn->query("ALTER TABLE tmp_docs ADD INDEX (place_id)");

// // Count folders
$folder_set = $conn->query("SELECT count(*) as rowcount FROM item_set;");
if ($folder_set) {
    $row = $folder_set->fetch_assoc();
    $foldercount = $row['rowcount'];
} else {
    echo "<span style='color:red'>Error getting count of folders: " . $conn->error . "<br></span>";
}


// Count Places
$count_places = $conn->query("SELECT count(*) as rowcount from tmp_places");
if ($count_places) {
    $count_places_result = $count_places->fetch_assoc();
    $place_count = $count_places_result['rowcount'];
    mysqli_commit($conn);
} else {
    echo "<span style='color:red'>Error getting count of places with markers: " . $conn->error . "<br></span>";
}


// count_documents
$count_docs = $conn->query("SELECT count(*) as rowcount from tmp_docs");
if ($count_docs) {
    $row = $count_docs->fetch_assoc();
    $doccount = $row['rowcount'];
} else {
    echo "<span style='color:red'>Error getting count of documents: " . $conn->error . "Try running GeoSync first. <br></span>";
}

$count_items = $conn->query("SELECT count(*) as rowcount from item");
if ($count_items) {
    $row = $count_items->fetch_assoc();
    $itemcount = $row['rowcount'];
} else {
    echo "<span style='color:red'>Error getting count of documents: " . $conn->error . "Try running GeoSync first. <br></span>";
}




echo "<hr><b>Integrity Audit:</b> CDASH-Specific Integrity Issues will be listed below. <br>";


// Count Places without documents
$count_places_wno_docs_sql = "select resource.title as Title, resource.id as resource_id, tmp_docs.doc_id as doc_id from resource
left outer join tmp_docs on resource.id = tmp_docs.place_id
WHERE resource_template_id = (select id from resource_template where label = 'CDASH Place') and tmp_docs.place_id IS NULL;";

$result = $conn->query($count_places_wno_docs_sql);
if ($result) {
    $placenodoc = $result->num_rows;
    echo "<br>   " . $placenodoc . " Place/s with no associated documents.";
    if ($placenodoc > 0){
        echo "<div style='margin-left: 20px;'>Place items may end up with no related Document items for various reasons -- but this is often a problem.
        Empty Place Items often result if duplicate places were created by accident.  When this happens you should look for
        opportunities to disambiguate Place Item names and look for documents that may have been associated
        associated with another place that may have had a duplicate name at some point.   ";

        echo "<table>";
       while ($row = $result->fetch_assoc()) {
           printf('<tr><td>%1$s</td><td><a href="http://%2$s/s/cdash/item/%3$s">%2$s/s/cdash/item/%3$s</a></td>', $row["Title"], $host, $row["resource_id"]);
         }
        echo"</table></div>";
        } else { echo "<br>"; }
} else {
       echo "<br><span style='color:red'>Error getting count of places having no documents: " . $conn->error .  "<br></span>";
}


// Count Place Items without Markers
$count_Items_without_markers_sql = "select resource.title as Title, resource.id as resource_id from resource
left outer join mapping_marker on resource.id = mapping_marker.item_id
WHERE resource_template_id = (select id from resource_template where label = 'CDASH Place') and mapping_marker.lat IS NULL;";

$result = $conn->query($count_Items_without_markers_sql);
if ($result) {
    $placenomark = $result->num_rows;
    echo "<br>   " . $placenomark . " Place item/s with no marker. <br>";
    if ($placenomark > 0){
    echo "<div style='margin-left: 20px;'>Every Place item requires a marker.  The marker must be created with the item.
             Create a markers for the following places. ";

    echo "<table>";
       while ($row = $result->fetch_assoc()) {
          printf('<tr><td>%1$s</td><td><a href="http://%2$s/admin/item/%3$s">%2$s/admin/item/%3$s</a></td>', $row["Title"], $host, $row["resource_id"]);
        }
        echo"</table></div>";
    }
} else {
       echo "<br><span style='color:red'>Error getting count of place items with no marker: " . $conn->error .  "<br></span>";
}


// Count Document Items with no Place Item
$count_DocItems_without_place_sql = "select resource.title as Title, resource.id as resource_id from resource
left outer join tmp_docs on resource.id = tmp_docs.doc_id
WHERE resource_template_id = (select id from resource_template where label = 'CDASH Document') and tmp_docs.place_id IS NULL;";

$result = $conn->query($count_DocItems_without_place_sql);
if ($result) {
    $docnoplace = $result->num_rows;
    echo "<br>   " . $docnoplace . " Document item/s with no place <br>";
    if ($docnoplace > 0){
        echo "<div style='margin-left: 20px;'>Every document item requires an associated place.  The following items have no associated place.";
       echo "<table>";
       while ($row = $result->fetch_assoc()) {
          printf('<tr><td>%1$s</td><td><a href="http://%2$s/s/cdash/item/%3$s">%2$s/cdash/item/%3$s</a></td>', $row["Title"], $host, $row["resource_id"]);
         }
        echo"</table></div>";
     }
   }else {
       echo "<span style='color:red'><br>Error getting count of document items with no place: " . $conn->error .  "<br></span>";
   }


$count_documents_with_multiple_places = "select doc_id, resource.title Title, count(doc_id)
from tmp_docs
 left outer join resource on doc_id = id
group by doc_id
having count(doc_id) > 1;";
$result = $conn->query($count_documents_with_multiple_places);
if ($result) {
    $docmanyplace = $result->num_rows;
    echo "<br>   " . $docmanyplace . " Documents reference more than one place item  <br>";
    if ($docmanyplace > 0){
       echo "<div style='margin-left: 20px;'>Documents with more than one place signify a serious problem that will lead to ver confusing lost data situations.  These should be addressed immediately";
      echo "<table>";
       while ($row = $result->fetch_assoc()) {
          printf('<tr><td>%1$s</td><td><a href="http://%2$s/admin/item-set/%3$s">%2$s/admin/item-set/%3$s</a></td>', $row["Title"], $host, $row["doc_id"]);
         }
        echo"</table></div>";
     }
   }else {
       echo "<span style='color:red'><br>Error getting count of documents with multiple places: " . $conn->error .  "<br></span>";
   }


// Count Document Items without Markers
$count_DocItems_without_markers_sql = "select resource.title as Title, resource.id as resource_id from resource
left outer join mapping_marker on resource.id = mapping_marker.item_id
WHERE resource_template_id = (select id from resource_template where label = 'CDASH Document') and mapping_marker.lat IS NULL;";

$result = $conn->query($count_DocItems_without_markers_sql);
if ($result) {
    $docnomark = $result->num_rows;
    echo "<br>   " . $docnomark . " Document item/s with no markers <br>";
    if ($docnomark > 0){
        echo "<div style='margin-left: 20px;'>Every document item requires a marker.  The marker must be created with the item.
             Create a markers for the following documents.  The position of the marker does not matter, since the location
             of the marker will be synced with the location of the related Place item.";
       echo "<table>";
       while ($row = $result->fetch_assoc()) {
          printf('<tr><td>%1$s</td><td><a href="http://%2$s/admin/item/%3$s">%2$s/admin/item/%3$s</a></td>', $row["Title"], $host, $row["resource_id"]);
         }
        echo"</table></div>";
     }
   }else {
       echo "<span style='color:red'><br>Error getting count of document items with no marker: " . $conn->error .  "<br></span>";
   }



// Find Empty Folders

$count_folders_with_no_items_sql = "select resource.title as Title, item_set.id as resource_id
 from item_set
     left outer join item_item_set on item_set.id = item_item_set.item_set_id
     left outer join resource on item_set.id = resource.id
where item_item_set.item_id IS NULL;";
$result = $conn->query($count_folders_with_no_items_sql);
if ($result) {
    $emptyfolders = $result->num_rows;
    echo "<br>   " . $emptyfolders . " Folder/s with no items <br>";
    if ($emptyfolders > 0){
       echo "<div style='margin-left: 20px;'>Empty folders cause clutter or may indicate data entry problems.  Investigate the following folder/s
             and delete if appropriate.";
      echo "<table>";
       while ($row = $result->fetch_assoc()) {
          printf('<tr><td>%1$s</td><td><a href="http://%2$s/admin/item-set/%3$s">%2$s/admin/item-set/%3$s</a></td>', $row["Title"], $host, $row["resource_id"]);
         }
        echo"</table></div>";
     }
   }else {
       echo "<span style='color:red'><br>Error getting count of folders with no items: " . $conn->error .  "<br></span>";
   }

$count_folders_with_duplicate_names = " select resource.title as Title, count(resource.title) as title_count
from resource where resource_template_id = 8
group by resource.title
having count(resource.title) > 1;";
$result = $conn->query($count_folders_with_duplicate_names);
if ($result) {
    $dupfolders = $result->num_rows;
    echo "<br>   " . $dupfolders . " Folder name is applied to multiple folders <br>";
    if ($dupfolders > 0){
       echo "<div style='margin-left: 20px;'>Folders with duplicate names often represent folders that should be distinquished by research district./ Investigate the following folder/s
             distinquish their names";
      echo "<table>";
       while ($row = $result->fetch_assoc()) {
          printf('<tr><td>%1$s</td>,<td>%2$s</td></tr>', $row["Title"], $row["title_count"]);
         }
        echo"</table></div>";
     }
   }else {
       echo "<span style='color:red'><br>Error getting count of folders with duplicate titles: " . $conn->error .  "<br></span>";
   }


$count_media = "select count(*) as count from media;";
$result = $conn->query($count_media);
if ($result) {
    $num_rows = $result->num_rows;
    $row = $result->fetch_assoc();
    $media_count = $row["count"];
    echo "<br>   " . $media_count . " rows in Omeka Media table. <br>";
    if ($media_count > 0){
       echo "<div style='margin-left: 20px;'>Omeka's media count reflects media files associated with items.  Occaisionally, you may want to use the Azure File Exporter to count the number of files in CHCPersist/files/original.</div>";
     }
   }else {
       echo "<span style='color:red'><br>Error getting count of media: " . $conn->error .  "<br></span>";
   }

   echo "<p><b>Geo Audit Summary:</b></p>";

   echo "<b>Counts: " . date('l, F j, Y H:i:s') . "</b>";
    echo "<table><tr>";
    echo "<tr><td>" . $foldercount . "</td><td>Folders</td></tr>";
    echo "<tr><td>" . $doccount . "</td><td>Document Items </td></tr>" ;
    echo "<tr><td>" . $place_count . "</td><td>Place Items</td></tr>" ;
    echo "<tr><td>" . $media_count . "</td><td>Omeka Media Count</td></tr>" ;
    echo "</table>" ;
if (($doccount + $place_count) != $itemcount) {
echo "<span style='color:red'><p> Total items: " . $itemcount . " not equal to: " . $doccount + $place_count . " Total Places + Total Documents.</p></span>" ;
}
  echo "<p><b>Integrity Issues: " . date('l, F j, Y H:i:s') . " </b><br><table><tr>";

    echo "<tr><td>" . $placenodoc . "</td><td> Place/s with no associated documents.</td></tr>";
    echo "<tr><td>" . $placenomark . "</td><td> Place item/s with no marker. </td></tr>";
    echo "<tr><td>" . $docnoplace . "</td><td> Document item/s with no place </td></tr>";
    echo "<tr><td>" . $docmanyplace . "</td><td> Documents reference more than one place item  </td></tr>";
    echo "<tr><td>" . $docnomark . "</td><td> Document item/s with no markers </td></tr>";
    echo "<tr><td>" . $emptyfolders . "</td><td> Folder/s with no items <br>";
    echo "<tr><td>" . $dupfolders . "</td><td> Folder name is applied to multiple folders</td></tr>";
    echo "</table>" ;


// // Documents without Markers
// select * from tmp_docs where place_lng is null;

// // Places without Markers
// select * from tmp_places where lng is null;

// // Documents without Streetsort
// select doc_id from tmp_docs where streetsort is null;

// // Places without Streetsort
// select item_id from tmp_places where streetsort is null;

// // select folders that have no documents
// $foldercout_sql select doc_id from tmp_docs
// left outer join tmp_places on tmp_places.item_id = tmp_docs.place_id
// where tmp_places.item_id IS NULL

// // Documents not associated with Places
// select doc_id, doctitle from tmp_docs
// left outer join tmp_places on tmp_places.item_id = tmp_docs.place_id
// where tmp_places.item_id IS NULL

// // Places that have no Documents
// select place_id, placeItem from tmp_places
// left outer join tmp_docs on tmp_places.item_id = tmp_docs.place_id
// where tmp_docs.place_id IS NULL



  $conn->close();


?>
