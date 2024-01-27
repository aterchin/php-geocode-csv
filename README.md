# PHP Geocoder - CSV file

Uses a geocoding provider like Google Maps API to run scripts which:
- parse a CSV file
- store original and geocoded data in separate tables

## Install

1. Change to this directory `cd php-geocode-csv`
2. `composer install`
3. Create a database table
4. Copy `.env.example` and to `.env` and add database and local server info variables

Get a Google Maps API key at [Google Cloud Console](https://console.cloud.google.com/welcome) and add to `.env` file.

## Run import
1. Run `php run.php`.

