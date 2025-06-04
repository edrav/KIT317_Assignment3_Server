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
    $site = (int)($row[0]);
    $date = $row[1];
    $humidity = (int)$row[2];
    $temperature = (float)$row)[3];
    [$year, $month, $day] = explode('-', $date);

    if (!isset($records[$site][$date])) {               # check for existing record for the current site/date
        $records[$site][$date] = [                      # create a new record if none exists
            'site' => $site,
            'month' => (int)$month,
            'day' => (int)$day,
            'min_humidity' => $humidity,
            'max_humidity' => $humidity,
            'min_temperature' => $temperature,
            'max_temperature' => $temperature,
        ];
    } else {                                            # if a record already exists compare it to the current row temp/humidity and keep the current row value if it is a new min/max
        $records[$site][$date]['min_humidity'] = min($records[$site][$date]['min_humidity'], $humidity);
        $records[$site][$date]['max_humidity'] = max($records[$site][$date]['max_humidity'], $humidity);
        $records[$site][$date]['min_temperature'] = min($records[$site][$date]['min_temperature'], $temperature);
        $records[$site][$date]['max_temperature'] = max($records[$site][$date]['max_temperature'], $temperature);

    }
}

$samples = [];
$labels = [
    'min_humidity' => [],
    'max_humidity' => [],
    'min_temperature' => [],
    'max_temperature' => []
];

?>