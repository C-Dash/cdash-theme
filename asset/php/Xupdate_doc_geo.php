<?php
    echo "<h1>Place Document Information eXchange</h1>";
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

// The following SQL query grabs coordinates and pop-up info for each place item
// You have to use your MySQL Client to get the following values
// resource_template_id corresponding with the CDASH Places template
// property_id corresponding with a value of placeType 

$docplace_sql = "create or replace view cdash_docgeo as 
select a.value_resource_id place_id, a.resource_id doc_id, lat place_lat, lng place_lng, b.value neighborhood, c.value chcDist
from value a
inner join mapping_marker on value_resource_id = item_id and property_id = (select id from property where local_name = 'placeItem')
inner join value b on b.resource_id = item_id and b.property_id = (select id from property where local_name = 'spatial')
inner join value c on c.resource_id = item_id and c.property_id = (select id from property where local_name = 'chcDist');
";

if ($conn->query($docplace_sql) === TRUE) {
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
