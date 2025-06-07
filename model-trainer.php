<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\Regression\SVR;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\ModelManager;

// turns month and day to just day of the year e.g. March 3rd becomes 31+28+3=62
// prevents inference of relationship between the same day of each month, e.g the 10th of march and the 10th of
// september have no specific relationship
Function convertToDayOfYear($m, $d) {  # turns month and day to just day of the year e.g. March 3rd becomes 31+28+3=62
    $totalDays = 0;
    static $daysInMonth = [ 0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, ];
    for ($i=0; $i < $m; $i++) {
        $totalDays = $totalDays + $daysInMonth[$i];
    }

    $totalDays = $totalDays + $d;

    return $totalDays;
}

// used to produce $sample training dataset with no numeric relationship between sites.  e.g. Site ID's are arbitrary and
// There is nothing to be inferred from the fact that site '2' is in between sites '1' and '3' or that it is half of site '4'
Function matchSiteID($staticSite, $variableSite) {
    return $staticSite === $variableSite ? 1 : 0;
}

$input_csv = 'training_data.csv';
$output_json = 'training_data.json';

$data = array_map('str_getcsv', file($input_csv));      # load raw data
$headers = array_map('trim', array_shift($data));       # remove header row and record column labels in separate array




$records = [];
foreach ($data as $row) {
    # extract values from the row
    $site = (int)($row[0]);
    $date = $row[1];
    $humidity = (int)$row[2];
    $temperature = (float)$row[3];
    [$year, $month, $dayOfMonth] = explode('-', $date);
    $dayOfYear = convertToDayOfYear($month, $dayOfMonth);

    if (!isset($records[$site][$date])) {               # check for existing record for the current site/date
        $records[$site][$date] = [                      # create a new record if none exists
            'site' => $site,
            'day_of_year' => (int)$dayOfYear,
            'min_humidity' => $humidity,                # when the record is first created the current row humidity will be both the minimum and maximum
            'max_humidity' => $humidity,                # humidity. These will be updated as new humidity values are seen for that site and date.
            'min_temperature' => $temperature,          # as above for temperature
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

# Separate aggregated training data into samples and labels arrays
foreach ($records as $site => $siteData) {              # loop through each site
    foreach ($siteData as $date => $values) {           # loop through the dates for each site

        # produces a sample record of the form [267, 0, 0, 1, 0, 0], i.e. the day of the year followed by five binary flags
        # one for each site in the training data
        $samples[] = [
            (int)$values['day_of_year'],
            (int)matchSiteID(1, $values['site']),
            (int)matchSiteID(2, $values['site']),
            (int)matchSiteID(3, $values['site']),
            (int)matchSiteID(4, $values['site']),
            (int)matchSiteID(5, $values['site'])
        ];
                                                        # append min/max humidity/temperature values to the labels array for that predicition target
        $labels['min_humidity'][] = (int)$values['min_humidity'];
        $labels['max_humidity'][] = (int)$values['max_humidity'];
        $labels['min_temperature'][] = (float)$values['min_temperature'];
        $labels['max_temperature'][] = (float)$values['max_temperature'];
    }
}

/*
Evaluation mode
in evaluation mode the sample and labels are placed in a random order and then the first 80% are used to train the model
and the last 20% are sued to test the accuracy of the model
*/
/**/
function meanAbsoluteError(array $actual, array $predicted): float {
    $n = count($actual);
    $total = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $total += abs($actual[$i] - $predicted[$i]);
    }
    return $total / $n;
}

function rootMeanSquareError(array $actual, array $predicted): float {
    $n = count($actual);
    $total = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $total += pow($actual[$i] - $predicted[$i], 2);
    }
    return sqrt($total / $n);
}

$indices = range(0,count($samples)-1);  # produce an ordered array of all index numbers in the samples array
shuffle($indices);                         # randomise the order of index numbers in the array of indexes

$shuffledSamples = [];
$shuffledLabels = [
    'min_humidity' => [],
    'max_humidity' => [],
    'min_temperature' => [],
    'max_temperature' => []
];

foreach ($indices as $index) {
    $shuffledSamples[] = $samples[$index];                  # reorder the samples array in the order of the randomised indexes array
    foreach ($labels as $label => $labelArray) {
        $shuffledLabels[$label][] = $labelArray[$index];    # reorder each prediction target array in the order of the randomised indexes array
    }
}

$splitIndex = (int)(count($shuffledSamples) * 0.8);         # determine the index where the dataset will be split into training and test

// split the randomised samples array into a training portion and a testing portion
$trainSamples = array_slice($shuffledSamples, 0, $splitIndex);
$testSamples = array_slice($shuffledSamples, $splitIndex);

// split the randomised labels array into a training portion and a testing portion
$trainLabels = [
    'min_humidity' => [],
    'max_humidity' => [],
    'min_temperature' => [],
    'max_temperature' => []
];
$trainLabels['min_humidity'] = array_slice($shuffledLabels['min_humidity'], 0, $splitIndex);
$trainLabels['max_humidity'] = array_slice($shuffledLabels['max_humidity'], 0, $splitIndex);
$trainLabels['min_temperature'] = array_slice($shuffledLabels['min_temperature'],0, $splitIndex);
$trainLabels['max_temperature'] = array_slice($shuffledLabels['max_temperature'], 0, $splitIndex);

$testLabels = [
    'min_humidity' => [],
    'max_humidity' => [],
    'min_temperature' => [],
    'max_temperature' => []
];
$testLabels['min_humidity'] = array_slice($shuffledLabels['min_humidity'], $splitIndex);
$testLabels['max_humidity'] = array_slice($shuffledLabels['max_humidity'], $splitIndex);
$testLabels['min_temperature'] = array_slice($shuffledLabels['min_temperature'], $splitIndex);
$testLabels['max_temperature'] = array_slice($shuffledLabels['max_temperature'], $splitIndex);


// train SVR models for each prediciton target using the training portion of the samples and labels datasets
$svr_minHumidity = new SVR(Kernel::RBF);
$svr_maxHumidity = new SVR(Kernel::RBF);
$svr_minTemperature = new SVR(Kernel::RBF);
$svr_maxTemperature = new SVR(Kernel::RBF);

$svr_minHumidity -> train($trainSamples, $trainLabels['min_humidity']);
$svr_maxHumidity -> train($trainSamples, $trainLabels['max_humidity']);
$svr_minTemperature -> train($trainSamples, $trainLabels['min_temperature']);
$svr_maxTemperature -> train($trainSamples, $trainLabels['max_temperature']);

$pred_minHumidity = [];
$pred_maxHumidity = [];
$pred_minTemperature = [];
$pred_maxTemperature = [];

for ($i = 0; $i < count($testSamples); $i++) {
    $pred_minHumidity[] = $svr_minHumidity->predict($testSamples[$i]);
    $pred_maxHumidity[] = $svr_maxHumidity->predict($testSamples[$i]);
    $pred_minTemperature[] = $svr_minTemperature->predict($testSamples[$i]);
    $pred_maxTemperature[] = $svr_maxTemperature->predict($testSamples[$i]);
}

$mae_minHumidity = meanAbsoluteError($testLabels['min_humidity'], $pred_minHumidity);
$mae_maxHumidity = meanAbsoluteError($testLabels['max_humidity'], $pred_maxHumidity);
$mae_minTemperature = meanAbsoluteError($testLabels['min_temperature'], $pred_minTemperature);
$mae_maxTemperature = meanAbsoluteError($testLabels['max_temperature'], $pred_maxTemperature);

$rmse_minHumidity = rootMeanSquareError($testLabels['min_humidity'], $pred_minHumidity);
$rmse_maxHumidity = rootMeanSquareError($testLabels['max_humidity'], $pred_maxHumidity);
$rmse_minTemperature = rootMeanSquareError($testLabels['min_temperature'], $pred_minTemperature);
$rmse_maxTemperature = rootMeanSquareError($testLabels['max_temperature'], $pred_maxTemperature);

echo "Minimum humidity prediction error - MAE: $mae_minHumidity, RMSE: $rmse_minHumidity\n";
echo "Maximum humidity prediction error - MAE: $mae_maxHumidity, RMSE: $rmse_maxHumidity\n";
echo "Minimum temperature prediction error - MAE: $mae_minTemperature, RMSE: $rmse_minTemperature\n";
echo "Maximum temperature prediction error - MAE: $mae_maxTemperature, RMSE: $rmse_maxTemperature\n";

/**/


// Normal mode
/*
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
/**/

?>