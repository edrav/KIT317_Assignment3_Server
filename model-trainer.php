<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\Regression\SVR;
use Phpml\SupportVectorMachine\Kernel;

$input_csv = 'training_data.csv';
$output_json = 'training_data.json';

$data = array_map('str_getcsv', file($input_csv));          # load raw data
$headers = array_map('trim', array_shift($data));           # remove header row and record column labels in separate array

$records = [];
foreach ($data as $row) {
    # extract values from the row
    $site = (int)($row[0]);                           #
    $date = $row[1];
    $humidity = (float)$row[2];
    $temperature = ($row)[3];

    if (!isset($records[$site][$date])) {
        $records[$site][$date] = [
            'site' => $site,
            'date' => $date,
            'min_humidity' => $humidity,
            'max_humidity' => $humidity,
            'min_temperature' => $temperature,
            'max_temperature' => $temperature,
        ];
    } else {
        $records[$site][$date]['min_humidity'] = min($records[$site][$date]['min_humidity'], $humidity);
        $records[$site][$date]['max_humidity'] = max($records[$site][$date]['max_humidity'], $humidity);
        $records[$site][$date]['min_temperature'] = min($records[$site][$date]['min_temperature'], $temperature);
        $records[$site][$date]['max_temperature'] = max($records[$site][$date]['max_temperature'], $temperature);

    }
}



?>