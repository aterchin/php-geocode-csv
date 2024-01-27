<?php
$db_host = $_ENV['DB_HOST'];
$db_user = $_ENV['DB_USER'];
$db_pass = $_ENV['DB_PASS'];
$db_name = $_ENV['DB_NAME'];
$db_port = $_ENV['DB_PORT'];
$db_socket = $_ENV['DB_SOCKET'];

$mysqli = @new mysqli(
  $db_host,
  $db_user,
  $db_pass,
  $db_name,
  $db_port,
  $db_socket
);

if ($mysqli->connect_error) {
  echo 'Errno: ' . $mysqli->connect_errno . "\n";
  echo 'Error: ' . $mysqli->connect_error . "\n";
  exit();
}

echo 'Success: A proper connection to MySQL was made.' . "\n";
echo 'Host information: ' . $mysqli->host_info . "\n";
echo 'Protocol version: ' . $mysqli->protocol_version . "\n";

$locations = "CREATE TABLE IF NOT EXISTS locations (
  `id` INT(20) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `address` VARCHAR(255) NOT NULL,
  `latitude` DECIMAL(8,6) NOT NULL,
  `longitude` DECIMAL(9,6) NOT NULL,
  `location_hash` BINARY(16) NOT NULL COMMENT 'MD5 hashed address from imported file.',
  INDEX `locations_latitude_longitude_index` (`latitude`,`longitude`),
  UNIQUE KEY `location_hash` (`location_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Geocoded location data.'";

$mysqli->query($locations);

$places = "CREATE TABLE IF NOT EXISTS places (
  `id` INT(20) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `location_id` INT(20) unsigned DEFAULT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `street` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(255) DEFAULT NULL,
  `state` VARCHAR(255) DEFAULT NULL,
  `postal` VARCHAR(255) DEFAULT NULL,
  `place_hash` BINARY(16) NOT NULL COMMENT 'MD5 hashed row from imported file.',
  `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES locations (`id`)
    ON DELETE CASCADE,
  UNIQUE KEY `place_hash` (`place_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Place data which references a location.'";

$mysqli->query($places);

//$mysqli->close();
