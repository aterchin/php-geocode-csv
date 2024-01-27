<?php
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

// Wall clock time
$time_start = microtime(true);
echo "\n";

// https://aaronfrancis.com/2021/efficient-distance-querying-in-my-sql

// get all addresses
// within the "point of interest" (-73.9853043, 40.7486538)
// which is the Empire State Building: 20 W 34th St., New York, NY 10001
// within 1 miles

// $query = "
//   SELECT `address` 
//   FROM locations
//   WHERE   (
//     ST_Distance_Sphere(
//       point(`longitude`, `latitude`),
//       point(-73.9853043, 40.7486538)
//     ) *.000621371192
//   ) <= 1;
//   ";

// $result = $mysqli->query($query);
// if (!$result->num_rows) die('No results.');

// while ($address = $result->fetch_column()) {
//   var_dump($address);
// }

// This is a great start, because it gives you results that are 100% correct, which is pretty important! The downside to this method is that it is incredibly slow. MySQL can't use any indexes on this query, because the columns are hidden in a calculation.

// https://aaronfrancis.com/2021/efficient-distance-querying-in-my-sql#querying-against-a-constant

// Small change: we're going to convert our search criteria from miles to meters once

// $query = "
//   SELECT `address` 
//   FROM locations
//   WHERE   (
//     ST_Distance_Sphere(
//       point(`longitude`, `latitude`),
//       point(-73.9853043, 40.7486538)
//     )
//   ) <= 1609.34400061;
//   ";

// $result = $mysqli->query($query);
// if (!$result->num_rows) die('No results.');

// while ($address = $result->fetch_column()) {
//   var_dump($address);
// }

function boundingBox($latitude, $longitude, $distance)
{
  $latLimits = [deg2rad(-90), deg2rad(90)];
  $lonLimits = [deg2rad(-180), deg2rad(180)];

  $radLat = deg2rad($latitude);
  $radLon = deg2rad($longitude);

  if (
    $radLat < $latLimits[0] || $radLat > $latLimits[1]
    || $radLon < $lonLimits[0] || $radLon > $lonLimits[1]
  ) {
    throw new \Exception("Invalid Argument");
  }

  // Angular distance in radians on a great circle,
  // using Earth's radius in miles.
  $angular = $distance / 3958.762079;

  $minLat = $radLat - $angular;
  $maxLat = $radLat + $angular;

  if ($minLat > $latLimits[0] && $maxLat < $latLimits[1]) {
    $deltaLon = asin(sin($angular) / cos($radLat));
    $minLon = $radLon - $deltaLon;

    if ($minLon < $lonLimits[0]) {
      $minLon += 2 * pi();
    }

    $maxLon = $radLon + $deltaLon;

    if ($maxLon > $lonLimits[1]) {
      $maxLon -= 2 * pi();
    }
  } else {
    // A pole is contained within the distance.
    $minLat = max($minLat, $latLimits[0]);
    $maxLat = min($maxLat, $latLimits[1]);
    $minLon = $lonLimits[0];
    $maxLon = $lonLimits[1];
  }

  return [
    'minLat' => rad2deg($minLat),
    'minLon' => rad2deg($minLon),
    'maxLat' => rad2deg($maxLat),
    'maxLon' => rad2deg($maxLon),
  ];
}

// RUN THE SEARCH

$latitude = 40.7486538;
$longitude = -73.9853043;
$miles = 1;

// lots o' fun calculations in here
$box = boundingBox($latitude, $longitude, $miles);

$query = "
SELECT `address` 
FROM locations
WHERE `latitude` BETWEEN {$box['minLat']} AND {$box['maxLat']}
AND `longitude` BETWEEN {$box['minLon']} AND {$box['maxLon']}
AND (
  ST_Distance_Sphere(
      point(`longitude`, `latitude`),
      point({$longitude}, {$latitude})
    )
  ) <= ({$miles} / 0.000621371192);
";

$search = $mysqli->query($query);
echo $query . "\n";

if (!$search->num_rows) die('No results.');

while ($address = $search->fetch_column()) {
  var_dump($address);
}

echo 'Execution time in seconds: ' . (microtime(true) - $time_start);
echo "\n\n";
