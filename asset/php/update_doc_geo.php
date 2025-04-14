<?php
// This little program updates the coordinates for each document item with the coordinates 
// of each document's  associated place_item.
//

echo "<h1>Place Document Information eXchange</h1>";

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
    $output["error"] = "Unable to connect with server: " . $conn->connect_error;
die("Connection failed: " . $conn->connect_error);
}

// CDASH_DocGeo is a view that has a row for each document item.  
// For each document item we get the ID of the linked place.  Then we get the coordinates for that place.  
// This view provides an easy lookup for the place coordinates for each document.   
// The fields of CDASH Doc Geo are: 
// Doc_id, Place_id, Place_lat, Place_lon


// In the value table,  the column where the property ID is 290 (PlaceItem) "value_resource_id" has a 
// value = to the resource_id for the placeItem associated with the resource.    

$make_docgeo_sql = "select a.resource_id doc_id, a.value_resource_id place_id, lat place_lat, lng place_lng 
from value a 
inner join mapping_marker on a.value_resource_id = item_id
and a.property_id = (select id from property where local_name = 'placeItem');";



if ($conn->query($make_docgeo_sql) == TRUE) {
    echo "Place data assembled!";
  } else {
    echo "Error creating cdash_docgeo view: " . $conn->error;
  }


$update_coords_sql = "update mapping_marker
inner join cdash_docgeo on item_id = doc_id
set lat = place_lat, lng = place_lng;";


if ($conn->query($update_coords_sql) === TRUE) {
    echo "<br>Coordinates updated successfully";
  } else {
    echo "<br>Error transferrng coordinates: " . $conn->error;
  }


  $conn->close();

  echo "<br><br><b>Close this tab and refresh to check results.</b> "

?>

