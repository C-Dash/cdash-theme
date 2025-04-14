<?php

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

$sql = "SELECT item_id, resource.title, value.value as placetype, lat, lng 
FROM mapping_marker
JOIN resource
  ON mapping_marker.item_id = resource.id
  AND resource_template_id = (select id from resource_template where label = 'CDASH Place')
JOIN value
  on resource.id = resource_id
  AND property_id = (select id from property where local_name = 'placeType')";

//$sql = "SELECT item_id, r.title, lat, lng 
//        FROM mapping_marker, resource r
//        WHERE item_id = r.id 
//        AND r.resource_template_id = 13 ";

//if ( strlen($searchterm) > 1 ){
//  $searchterm=$_POST['parameter'];
//  //$sql .= " AND r.title LIKE '%Otis%';";
 // $sql .= " AND r.title LIKE '%" . $searchterm . "%';";
 // //if ( is_empty( $searchterm )) {
////  if ( "Foo" === "Foo") {
//} else {
////     $sql .= ";";
      
//    };

////    $output["sql"] = $sql;


//$sql = "SELECT username, faculty, role 
//FROM $databaseTableName WHERE username = '$user' ";

$result = $conn->query($sql);

if ( $result->num_rows > 0) 
{
     $output["success"] = true;

     // output data of each row
     while( $row = $result->fetch_assoc()) {
          //$output["resp"] += "\n" + $row;
          //$output[$row["item_id"]] = $row;
          //$output["resp"] = $row;
          array_push($output["resp"],$row);
          //break;
      }
    /* free result set */
    $result->free();
}
else {
      $output["success"] = false;
      $output["error"] = "No rows selected";
}
$conn->close();
echo json_encode($output);
?>
