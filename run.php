<?php
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

//https://github.com/geocoder-php/Geocoder

use GuzzleHttp\Client;
use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;

$file = __DIR__ . '/data/test.csv';

// create BIG associative array of CSV fields (should work until it doesn't)
$csv = array_map('str_getcsv', file($file, FILE_SKIP_EMPTY_LINES));
$keys = array_shift($csv);
foreach ($csv as $i => $row) {
  $csv[$i] = array_combine($keys, $row);
}

$num_rows_csv = count($csv);
printf("Records in CSV file: %d\n", $num_rows_csv);

if ($num_rows_csv === 0) die('No data to process in CSV... exiting.x');

// configure geocoder
$config = [
  'timeout' => 2.0,
  'verify' => false,
];
$client = new Client($config);
$geocoder = new GoogleMaps($client, 'US', $_ENV['GOOGLE_MAPS_API_KEY']);

// prepare locations insert statement
$cols = '`address`, `latitude`, `longitude`, `location_hash`';
$query = "INSERT INTO locations ({$cols}) VALUES (?, ?, ?, ?)";
$l_stmt = $mysqli->prepare($query);

// prepare places insert statement (ignore duplicate unique hashes)
$cols = '`location_id`, `name`, `street`, `city`, `state`, `postal`, `place_hash`';
$query = "INSERT IGNORE INTO places ({$cols}) VALUES (?, ?, ?, ?, ?, ?, ?)";
$p_stmt = $mysqli->prepare($query);

$new_l_count = 0;
$new_p_count = 0;
$column_map = [
  'Ship_To_Name',
  'Ship_To_City',
  'Ship_To_Zip',
  'Ship_To_Street',
  'Ship_To_Region'
];
foreach ($csv as $index => $field) {
  foreach ($column_map as $c) {
    if (isset($field[$c])) {
      // trim whitespace
      $field[$c] = trim($field[$c]);
    }
  }
  $name = '';
  $city = '';
  $postal = '';
  $street = '';
  $state = '';
  // place name OR practice name
  if (isset($field['Ship_To_Name'])) {
    $name = $field['Ship_To_Name'];
  }
  // city
  if (isset($field['Ship_To_City'])) {
    $city = $field['Ship_To_City'];
  }
  // street address
  if (isset($field['Ship_To_Street'])) {
    $street = $field['Ship_To_Street'];
  }
  // postal code
  if (isset($field['Ship_To_Zip'])) {
    $postal = $field['Ship_To_Zip'];
  }
  // state
  if (isset($field['Ship_To_Region'])) {
    $state = $field['Ship_To_Region'];
  }

  // location base array for hash ID
  $l_arr = [$street, $city, $state, $postal];
  $l_hex = md5(implode('|', $l_arr));

  // place base array for hash ID
  $p_arr = [$name] + $l_arr;
  $p_hex = md5(implode('|', $p_arr));

  // check for matching place
  // Note: comparison to hex (vs binary) fixed mysql errors 
  // when trying to do an equality check on the initially empty table.
  $result = $mysqli->query("
    SELECT * FROM places
      WHERE HEX(`place_hash`) = '{$p_hex}'
      LIMIT 1;
  ");
  if ($result->num_rows) {
    // existing place where name AND address match hash, nothing to process
    continue;
  }
  // free result set
  $result->free_result();

  // store new or existing location id in variable
  $location_id = NULL;

  // check for matching location hash
  $result = $mysqli->query("
    SELECT * FROM locations 
      WHERE HEX(`location_hash`) = '{$l_hex}'
      LIMIT 1;
  ");
  if ($result->num_rows) {
    // existing location
    $row = $result->fetch_assoc();
    $location_id = $row['id'];
  } else {
    sleep(1);
    $raw_address = implode(' ', $l_arr);
    $geo_results = $geocoder->geocodeQuery(GeocodeQuery::create($raw_address));

    /*
    https://developers.google.com/maps/documentation/places/web-service/place-id

    A place ID is a textual identifier that uniquely identifies a place. The length of the identifier may vary (there is no maximum length for Place IDs, so might not be a valid
    option for storing in database unless we create a hash of it.

    Retrieve place details using the place ID:
    https://maps.googleapis.com/maps/api/place/details/json?place_id=ChIJrTLr-GyuEmsRBfy61i59si0&key=YOUR_API_KEY

    Place IDs are exempt from the caching restrictions, you can also store place ID values for later use, but you will need to refresh them every 12 months.

    //print_r($first->getId());
    */

    /** @var GoogleAddress $first */
    $first = $geo_results->first();
    $address = $first->getFormattedAddress();
    $latitude = $first->getCoordinates()->getLatitude();
    $longitude = $first->getCoordinates()->getLongitude();
    printf("Geocode result (raw address: %s, address: %s)\n", $raw_address, $address);

    // don't need these (leaving here for documentation purposes)
    // $postal_code = $first->getPostalCode();
    // $sub_locality = $first->getSubLocality();
    // $locality = $first->getLocality();
    // $street_number = $first->getStreetNumber();
    // $street_name = $first->getStreetName();
    // $state_code = $first->getAdminLevels()->get(1)->getCode();
    // $state_name = $first->getAdminLevels()->get(1)->getName();
    // $country_code = $first->getCountry()->getCode();

    /*
    HERE is a situation where two addresses
    supplied in the imported file ARE SLIGHTLY DIFFERENT (i.e. small typo or misspelling)
    but the geocode service finds the exact same address.  Ideally we do not want
    two of the same locations in the database. So, another internal lookup to match address.  If found, we can add the foreign key to the place record.

    This means one more database call but will save us a geocoding service API call moving forward. Additional scaling benefit is that we can now put a unique composite index on the
    lat/long cols for faster searches.

    Note: The main reason I'm using address for this lookup is simplicity, in order
    to avoid equality mistakes with rounded lat/long coordinates. Actual geocode results
    may have more decimal places than what the database is storing.
    */

    $stmt = $mysqli->prepare("SELECT * FROM locations WHERE `address` = ?");
    $stmt->bind_param('s', $address);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows) {
      // existing location! attach location id to new place entry!
      $row = $result->fetch_assoc();
      $location_id = $row['id'];
    } else {
      // execute new location insert in db (no existing hashes or lat/long)
      $l_bin = hex2bin($l_hex);
      $l_stmt->bind_param('sdds', $address, $latitude, $longitude, $l_bin);
      $l_stmt->execute();
      // new location
      $location_id = $l_stmt->insert_id;
      $new_l_count++;
      printf("New location (id: %d, address: %s)\n", $location_id, $address);
    }
  }
  // free result set
  $result->free_result();

  // execute new place insert in db
  $p_bin = hex2bin($p_hex);
  $p_stmt->bind_param('dssssss', $location_id, $name, $street, $city, $state, $postal, $p_bin);
  $p_stmt->execute();
  $new_p_count++;
  printf("New place (name: %s, location id: %d)\n", $name, $location_id);
}

$mysqli->close();

print " \n\n";
printf("Total new locations: %s \n", $new_l_count);
printf("Total new places: %s \n", $new_p_count);
print " \n\n";
