<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\Regression\SVR;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\ModelManager;

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
    $temperature = (float)$row[3];
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

foreach ($records as $site => $siteData) {
    foreach ($siteData as $date => $values) {
        $samples[] = [
            (int)$values['month'],
            (int)$values['day'],
            (int)$values['site']
        ];

        $labels['min_humidity'][] = (int)$values['min_humidity'];
        $labels['max_humidity'][] = (int)$values['max_humidity'];
        $labels['min_temperature'][] = (float)$values['min_temperature'];
        $labels['max_temperature'][] = (float)$values['max_temperature'];
    }
}



$svr_minHumidity = new SVR(Kernel::RBF);
$svr_maxHumidity = new SVR(Kernel::RBF);
$svr_minTemperature = new SVR(Kernel::RBF);
$svr_maxTemperature = new SVR(Kernel::RBF);

$modelManager = new ModelManager();


echo "Training minimum humidity started...\n";
$start_minHumidity = microtime(true);
$svr_minHumidity->train($samples, $labels['min_humidity']);
$modelManager->saveToFile($svr_minHumidity, 'minHumidity.svr');
$end_minHumidity = microtime(true);
echo "Training minimum humidity completed in " . round($end_minHumidity - $start_minHumidity, 2) . " seconds.\n";

echo "Training maximum humidity started...\n";
$start_maxHumidity = microtime(true);
$svr_maxHumidity->train($samples, $labels['max_humidity']);
$modelManager->saveToFile($svr_maxHumidity, 'maxHumidity.svr');
$end_maxHumidity = microtime(true);
echo "Training maximum humidity completed in " . round($end_maxHumidity - $start_maxHumidity, 2) . " seconds.\n";

echo "Training minimum temperature started...\n";
$start_minTemperature = microtime(true);
$svr_minTemperature->train($samples, $labels['min_temperature']);
$modelManager->saveToFile($svr_minTemperature, 'minTemperature.svr');
$end_minTemperature = microtime(true);
echo "Training minimum temperature completed in " . round($end_minTemperature - $start_minTemperature, 2) . " seconds.\n";

echo "Training maximum temperature started...\n";
$start_maxTemperature = microtime(true);
$svr_maxTemperature->train($samples, $labels['max_temperature']);
$modelManager->saveToFile($svr_maxTemperature, 'maxTemperature.svr');
$end_maxTemperature = microtime(true);
echo "Training maximum temperature completed in " . round($end_maxTemperature - $start_maxTemperature, 2) . " seconds.\n";


?>