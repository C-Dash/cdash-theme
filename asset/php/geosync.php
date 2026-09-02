<?php
// This little program updates the coordinates for each document item with the coordinates
// of each document's  associated place_item.
//
// geosync_fast.php: uses temporary tables instead of views so the expensive
// multi-join SELECT against the value table runs once per sync, not once per query.

// Must come first: this script writes to the database as soon as it runs, and
// the guard needs to set a response code before any output.
require __DIR__ . '/_require-admin.php';

echo "<head><title>GeoSync Fast</title></head>";
echo "<h1>GeoSync: Syncronize Place updates to Documents</h1>";

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



// Create Place and Doc temporary tables
// The omeka's object-relational data model uses a single table to store the values for all item
// properties.  This is difficult to deal with.  To begin with, we compile this information into
// two temporary tables that operate like simple tables.  These can then be used for using ordinary
// structured query language to relate places and document items and mapping markers.
// Unlike views, temporary tables are materialized once at the start of the sync and reused for all
// subsequent queries, avoiding repeated expensive joins against the value table.

// tmp_places has one row per place item with the property values we want to exchange with documents.

echo "<b>Item Summary:</b> Creating summary tables for Places and Documents. <br><br>";

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
    die("Error creating tmp_places: " . $conn->error . "<br>");
}
$conn->query("ALTER TABLE tmp_places ADD INDEX (place_id)");

$places = $conn->query("SELECT count(*) as rowcount from tmp_places");

if ($places) {
    $row = $places->fetch_assoc();
    $place_count = $row['rowcount'];
    mysqli_commit($conn);
    echo $place_count . " Place Items: <br>";
} else {
    echo "Error getting count of places: " . $conn->error . "<br>";
}

// tmp_docs has a row for each document item.  It joins tmp_places to get current coordinates,
// place name and streetsort key so that derived title and streetsort can be synced back.

$make_doc_sql = "CREATE TEMPORARY TABLE tmp_docs AS
select resource.id as doc_id, value.value_resource_id as place_id, tmp_places.placeName as placeName, concat(tmp_places.title, ' - ', vtype.value) as doctitle, concat(tmp_places.streetSort,vtype.value) as streetSort, tmp_places.lat, tmp_places.lng
from resource
left outer join value on value.resource_id = resource.id and value.property_id = (select id from property where local_name = 'placeItem')
left outer join value vtype on vtype.resource_id = resource.id
  AND vtype.property_id = (select id from property where local_name = 'type')
left outer JOIN tmp_places on tmp_places.place_id = value.value_resource_id
WHERE resource.resource_template_id = (select resource_template.id from resource_template where label = 'CDASH Document');";

if (!$conn->query($make_doc_sql)) {
    die("Error creating tmp_docs: " . $conn->error . "<br>");
}
$conn->query("ALTER TABLE tmp_docs ADD INDEX (doc_id)");
$conn->query("ALTER TABLE tmp_docs ADD INDEX (place_id)");

$count_docs = $conn->query("SELECT count(*) as rowcount from tmp_docs");
if ($count_docs) {
    $row = $count_docs->fetch_assoc();
    $doc_count = $row['rowcount'];
    echo $doc_count . " Document items: <br>";
} else {
    echo "Error getting count of doc items: " . $conn->error . "<br>";
}

    echo $place_count + $doc_count . " Total items: <br>";



echo "<br><b>Updates:</b> if there has been any update activity it will be listed below. <br>";

// Update mapping_marker coordinates for documents.
$update_docmarkers_sql = "update mapping_marker
                          inner join tmp_docs on item_id = doc_id and tmp_docs.lat IS NOT NULL
                          set mapping_marker.lat = coalesce(tmp_docs.lat,mapping_marker.lat), mapping_marker.lng = coalesce(tmp_docs.lng,mapping_marker.lng);";
$markers_updated = $conn->query($update_docmarkers_sql);
$rows_affected = mysqli_affected_rows($conn);
if ($markers_updated) {
   if ($rows_affected > 0) {
    echo "<br>Coordinates updated for " . mysqli_affected_rows($conn) . " items.<br>";
   }
  } else {
    echo "<br>Error transferring coordinates: " . $conn->error . "<br>";
  }


// update Place placeItem

$update_place_place_item_sql =  "update value
inner JOIN tmp_places ON resource_id = place_id
set value.value = tmp_places.placeItem
where property_id = (select id from property where local_name = 'placeItem');";

$placeitem_updated = $conn->query($update_place_place_item_sql);
if ($placeitem_updated) {
   $rows_affected = mysqli_affected_rows($conn);
   if ($rows_affected > 0) {
     echo "<br>Place Item (Title) property updated for " . mysqli_affected_rows($conn) . " Place items.    ";
    }
} else {
    echo "<br>Error updating place item property for places.: " . $conn->error . "<br>";
}


// Update Resource.title for Place items

$update_placetitles_sql =  "update resource
inner JOIN tmp_places ON id = place_id
set resource.title = tmp_places.placeItem;";

$place_titles_updated = $conn->query($update_placetitles_sql);
if ($place_titles_updated) {
     $rows_affected = mysqli_affected_rows($conn);
     if ($rows_affected > 0) {
       echo "    Resource title updated for " . mysqli_affected_rows($conn) . " Place items.<br>";
     }
  } else {
    echo "<br>Error updating place resource titles: " . $conn->error . "<br>";
  }


//update docs title property
$update_doc_title_prop_sql =  "update value
inner JOIN tmp_docs ON resource_id = doc_id
set value.value = tmp_docs.doctitle
where property_id = (select id from property where label = 'Title' AND comment = 	'A name given to the resource.');";

$doc_title_prop_updated = $conn->query($update_doc_title_prop_sql);
if ($doc_title_prop_updated) {
     $rows_affected = mysqli_affected_rows($conn);
     if ($rows_affected > 0) {
    echo "<br>Document  Title property updated for " . mysqli_affected_rows($conn) . " Document items.     ";
     }
  } else {
    echo "<br>Error updating title property for docments.: " . $conn->error . "<br>";
  }


// Update resource.title for Document Items

$update_doctitles_sql =  "update resource
inner JOIN tmp_docs ON id = doc_id
set resource.title = tmp_docs.doctitle ;";

$doc_titles_updated = $conn->query($update_doctitles_sql);
if ($doc_titles_updated) {
     $rows_affected = mysqli_affected_rows($conn);
       if ($rows_affected > 0) {
    echo "     Resource titles updated for " . mysqli_affected_rows($conn) . " Document items.<br>";
    mysqli_commit($conn);
    }
  } else {
    echo "<br>Error updating document resource titles: " . $conn->error . "<br>";
  }


// update Place streetsort key

$update_placesort_sql =  "update value
inner JOIN tmp_places ON resource_id = place_id
set value.value = tmp_places.streetSort
where property_id = (select id from property where local_name = 'streetSort');";

$placesort_updated = $conn->query($update_placesort_sql);
if ($placesort_updated) {
     $rows_affected = mysqli_affected_rows($conn);
     if ($rows_affected > 0) {
        echo "<br>StreetSort updated for " . mysqli_affected_rows($conn) . " Place items.<br>";
     }
  } else {
    echo "<br>Error updating streetsort: " . $conn->error . "<br>";
  }


// update Document streetsort key

$update_docsort_sql =  "UPDATE value
inner JOIN tmp_docs ON resource_id = doc_id
set value.value = tmp_docs.streetSort
where property_id = (select id from property where local_name = 'streetSort');";

$docsort_updated = $conn->query($update_docsort_sql);
if ($docsort_updated) {
     $rows_affected = mysqli_affected_rows($conn);
   if ($rows_affected > 0) {
    echo "<br>StreetSort updated for " . mysqli_affected_rows($conn) . " Document items.<br>";
   }
  } else {
    echo "<br>Error updating streetsort: " . $conn->error . "<br>";
  }


// update Document CHC Neighborhood properties (potential 1-to-many)
// Step 1: remove existing neighborhood values from all documents

$delete_chcnhoods_sql =  "DELETE v FROM value v
INNER JOIN tmp_docs ON v.resource_id = tmp_docs.doc_id
WHERE v.property_id = (SELECT id FROM property WHERE local_name = 'Neighborhood');";

$chcnhoods_deleted = $conn->query($delete_chcnhoods_sql);
if ($chcnhoods_deleted) {
     $rows_affected = mysqli_affected_rows($conn);
   if ($rows_affected > 0) {
    echo "<br>Drop existing values for CHCNeighborhood " . mysqli_affected_rows($conn) . " Values.<br>";
   }
  } else {
    echo "<br>Error dropping chc neighborhood values: " . $conn->error . "<br>";
  }

// Step 2: insert current neighborhood(s) from linked Place
$insert_cddneighborhood_sql = "INSERT INTO value (resource_id, property_id, type, value, is_public)
SELECT
    tmp_docs.doc_id,
    (SELECT id FROM property WHERE local_name = 'Neighborhood'),
    'literal',
    neighborhood_val.value, 1
FROM tmp_docs
INNER JOIN value neighborhood_val
    ON neighborhood_val.resource_id = tmp_docs.place_id
    AND neighborhood_val.property_id = (SELECT id FROM property WHERE local_name = 'Neighborhood');";

$cddnhoods_inserted = $conn->query($insert_cddneighborhood_sql);
if ($cddnhoods_inserted) {
     $rows_affected = mysqli_affected_rows($conn);
   if ($rows_affected > 0) {
    echo "<br>Inserted values for CDDNeighborhood " . mysqli_affected_rows($conn) . " Values.<br>";
   }
  } else {
    echo "<br>Error inserting cdd neighborhood values: " . $conn->error . "<br>";
  }


// update Document CHC Research District properties (potential 1-to-many)
// Step 1: remove existing district values from all documents

$delete_chcdistrict_sql =  "DELETE v FROM value v
INNER JOIN tmp_docs ON v.resource_id = tmp_docs.doc_id
WHERE v.property_id = (SELECT id FROM property WHERE local_name = 'chcDist');";

$chcdist_deleted = $conn->query($delete_chcdistrict_sql);
if ($chcdist_deleted) {
     $rows_affected = mysqli_affected_rows($conn);
   if ($rows_affected > 0) {
    echo "<br>Drop existing values for CHCDistrict " . mysqli_affected_rows($conn) . " Values.<br>";
   }
  } else {
    echo "<br>Error dropping chc district values: " . $conn->error . "<br>";
  }

// Step 2: insert current research districts from linked Place
$insert_chcdist_sql = "INSERT INTO value (resource_id, property_id, type, value, is_public)
SELECT
    tmp_docs.doc_id,
    (SELECT id FROM property WHERE local_name = 'chcDist'),
    'literal',
    district_val.value, 1
FROM tmp_docs
INNER JOIN value district_val
    ON district_val.resource_id = tmp_docs.place_id
    AND district_val.property_id = (SELECT id FROM property WHERE local_name = 'chcDist');";

$chcdist_inserted = $conn->query($insert_chcdist_sql);
if ($chcdist_inserted) {
     $rows_affected = mysqli_affected_rows($conn);
   if ($rows_affected > 0) {
    echo "<br>Inserted values for CHCDist " . mysqli_affected_rows($conn) . " Values.<br>";
   }
  } else {
    echo "<br>Error inserting chc neighborhood districts: " . $conn->error . "<br>";
  }




  $conn->close();

  echo "<br><br><b>Close this tab and refresh to check results.</b> ";




?>
