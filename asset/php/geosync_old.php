<?php
// This little program updates the coordinates for each document item with the coordinates 
// of each document's  associated place_item.

echo "<head><title>GeoSync</title></head>";
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



// Create Place and Doc Views
// The omeka's object-relational data model uses a single table to store the values for all item 
// properties.  This is difficult to deal with.  To begin with, we compile this information into 
// two views that operate like simple tables.  These can then be used for using ordinary structured 
// query language to relate places and document items and mapping markers. 

// CDASH_Place_View is a view that has one row per place item.  This view has the property values 
// we want to exchange with document items.

///*replace(*/CONCAT(g.value, '_', REPEAT('0',(7 - LENGTH(h.value))),h.value)/*,'-','_')*/ as streetSort


echo "<b>Item Summary:</b> Creating summary tables for Places and Documents. <br><br>";

$makeplace_view_sql = "CREATE OR REPLACE VIEW cdash_places_view as 
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

$places = $conn->query("SELECT count(*) as rowcount from cdash_places_view");

if ($places) {
    // Fetch the result as an associative array
    $row = $places->fetch_assoc();
    // Get the row count
    $place_count = $row['rowcount']; // Access the count using the alias 'row_count'
    mysqli_commit($conn);
    echo $place_count . " Place Items: <br>";
} else {
    echo "Error getting count of places: " . $conn->error . "<br>";
}

// cdash_docs_view is a view that has a row for each document item.  This view uses the Places view 
// to get the current coordinates, place name and streetsort key so that in this view we build the 
// derived title and streetsort keys that we want to sync with the document items.  These values
// will need to be written back to the values table. 
 

$make_doc_view_sql = "CREATE OR REPLACE VIEW cdash_docs_view as 
select resource.id as doc_id,  value.value_resource_id as place_id, cdash_places_view.placeName as placeName, concat(cdash_places_view.title, ' - ', vtype.value) as doctitle, concat(cdash_places_view.streetsort,vtype.value) as streetSort, cdash_places_view.lat,  cdash_places_view.lng
from resource
left outer join value on value.resource_id = resource.id and value.property_id = (select id from property where local_name = 'placeItem')
left outer join value vtype on vtype.resource_id = resource.id 
  AND vtype.property_id = (select id from property where local_name = 'type')
left outer JOIN cdash_places_view on cdash_places_view.place_id = value.value_resource_id
WHERE resource.resource_template_id = (select resource_template.id from resource_template where label = 'CDASH Document');";


$conn->query($make_doc_view_sql);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "<br>");
}

$count_docs_view = $conn->query("SELECT count(*) as rowcount from cdash_docs_view");
if ($count_docs_view) {
    // Fetch the result as an associative array
    $row = $count_docs_view->fetch_assoc();
    // Get the row count
    $doc_count = $row['rowcount']; // Access the count using the alias 'row_count'
    echo $doc_count . " Document items: <br>";
} else {
    echo "Error getting count of doc items: " . $conn->error . "<br>";
}

    echo $place_count + $doc_count . " Total items: <br>";





echo "<br><b>Updates:</b> if there has been any update activity it will be listed below. <br>";
// The mapping_Markers table is updated for documents.  Based on a join with the cdsah doc view, 
// the old mapping_markers.Lat .Lon with the values from the Docs View. The COALESCE function 
// prevents updates from happening where the coordinates in the cdash_places_view are null. 

$update_docmarkers_sql = "update mapping_marker 
                          inner join cdash_docs_view on item_id = doc_id and cdash_docs_view.lat IS NOT NULL
                          set mapping_marker.lat = coalesce(cdash_docs_view.lat,mapping_marker.lat), mapping_marker.lng = coalesce(cdash_docs_view.lng,mapping_marker.lng);";
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
inner JOIN cdash_places_view ON resource_id = place_id
set value.value = cdash_places_view.placeItem 
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
inner JOIN cdash_places_view ON id = place_id
set resource.title = cdash_places_view.placeItem;";

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
inner JOIN cdash_docs_view ON resource_id = doc_id
set value.value = cdash_docs_view.doctitle 
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
inner JOIN cdash_docs_view ON id = doc_id
set resource.title = cdash_docs_view.doctitle ;";

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
inner JOIN cdash_places_view ON resource_id = place_id
set value.value = cdash_places_view.streetSort 
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
inner JOIN cdash_docs_view ON resource_id = doc_id
set value.value = cdash_docs_view.streetSort 
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


// update Document CHC Neighborhood properties (potential 1-to-many
// Step 1: remove existing neighborhood values from all documents
  
$delete_chcnhoods_sql =  "DELETE v FROM value v
INNER JOIN cdash_docs_view ON v.resource_id = cdash_docs_view.doc_id
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

// Step 2: Step 2: insert current neighborhood(s) from linked Place
$insert_cddneighborhood_sql = "INSERT INTO value (resource_id, property_id, type, value, is_public)
SELECT 
    cdash_docs_view.doc_id,
    (SELECT id FROM property WHERE local_name = 'Neighborhood'),
    'literal',
    neighborhood_val.value, 1
FROM cdash_docs_view
INNER JOIN value neighborhood_val 
    ON neighborhood_val.resource_id = cdash_docs_view.place_id
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


  // update Document CHC Research District properties (potential 1-to-many
// Step 1: remove existing neighborhood values from all documents
  
$delete_chcdistrict_sql =  "DELETE v FROM value v
INNER JOIN cdash_docs_view ON v.resource_id = cdash_docs_view.doc_id
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

// Step 2: Step 2: insert current research districts from linked Place
$insert_chcdist_sql = "INSERT INTO value (resource_id, property_id, type, value, is_public)
SELECT 
    cdash_docs_view.doc_id,
    (SELECT id FROM property WHERE local_name = 'chcDist'),
    'literal',
    district_val.value, 1
FROM cdash_docs_view
INNER JOIN value district_val 
    ON district_val.resource_id = cdash_docs_view.place_id
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
