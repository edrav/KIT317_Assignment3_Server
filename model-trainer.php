<?php
require_once __DIR__ . '/vendor/autoload.php';

/*
php model-trainer.php --eval
runs the trainer in evaluation mode to measure the accuracy of models
*/
$evaluationMode = in_array('--eval', $argv);

use Phpml\SupportVectorMachine\SupportVectorMachine;
use Phpml\SupportVectorMachine\Type;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\ModelManager;

include 'functions.php';

$input_csv = 'training_data.csv';

// load data
echo "Loading $input_csv\n";

$data = array_map('str_getcsv', file($input_csv));      # load raw data
$headers = array_map('trim', array_shift($data));       # remove header row and record column labels in separate array


// process data
echo "Processing $input_csv\n";

$records = [];
foreach ($data as $row) {
    # extract values from the row, row[2] is time which is not used in training models but is used in graph.php
    $site = (int)($row[0]);
    $date = $row[1];
    $humidity = (int)$row[3];
    $temperature = (float)$row[4];
    [$dayOfMonth, $month, $year] = explode('/', $date);
    $dayOfYear = convertToDayOfYear($month, $dayOfMonth);  # gives date in as an integer from 1-365

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

// Split processed data into samples and labels
echo "Identifying samples and labels.\n";

$samples = [];
$labels = [
    'min_humidity' => [],
    'max_humidity' => [],
    'min_temperature' => [],
    'max_temperature' => []
];

foreach ($records as $site => $siteData) {              # loop through each site
    foreach ($siteData as $date => $values) {           # loop through the dates for each site

        # produces a sample record of the form [267, 0, 0, 1, 0, 0], i.e. the day of the year followed by five binary flags
        # one for each site in the training data this prevents the model inferring a numerical relationship between sites
        $samples[] = [
            (int)$values['day_of_year'],
            (int)matchSiteID(1, $values['site']),
            (int)matchSiteID(2, $values['site']),
            (int)matchSiteID(3, $values['site']),
            (int)matchSiteID(4, $values['site']),
            (int)matchSiteID(5, $values['site'])
        ];
                                                        # append min/max humidity/temperature values to the labels array for that prediction target
        $labels['min_humidity'][] = (int)$values['min_humidity'];
        $labels['max_humidity'][] = (int)$values['max_humidity'];
        $labels['min_temperature'][] = (float)$values['min_temperature'];
        $labels['max_temperature'][] = (float)$values['max_temperature'];
    }
}


if ($evaluationMode) {
    /*
    Evaluation mode
    in evaluation mode the sample and labels are placed in a random order.  Then the first 80% are used to train the model,
    and the last 20% are sued to test the accuracy of the model.  The randomising of the order is necessary as the source
    data is ordered, and consequently the processed data is ordered first by site and then by date.  While ordered this way,
    splitting the samples and labels into training/testing data by splitting at a specific index would result in a training
    dataset that was all data from the first 4 sites, and a testing dataset that was all from the 5th site.
    */
    // Randomise the order of Samples and Labels while preserving the correlation between them
    echo "Evaluation Mode.\n";
    function meanAbsoluteError(array $actual, array $predicted): float
    {
        $n = count($actual);
        $total = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $total += abs($actual[$i] - $predicted[$i]);
        }
        return $total / $n;
    }

    function rootMeanSquareError(array $actual, array $predicted): float
    {
        $n = count($actual);
        $total = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $total += pow($actual[$i] - $predicted[$i], 2);
        }
        return sqrt($total / $n);
    }

    // shuffle samples and labels while maintaining relationship between them
    echo "Shuffling samples and labels.\n";
    $indices = range(0, count($samples) - 1);       # produce an ordered array of all index numbers in the samples array
    shuffle($indices);                              # randomise the order of index numbers in the array of indexes

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

    // create training and test datasets
    echo "Splitting samples and labels into training and testing datasets.\n";
    $splitIndex = (int)(count($shuffledSamples) * 0.8);         # determine the index where the dataset will be split into training and test

    # split the randomised samples array into a training portion and a testing portion
    $trainSamples = array_slice($shuffledSamples, 0, $splitIndex);
    $testSamples = array_slice($shuffledSamples, $splitIndex);

    # split the randomised labels array into a training portion and a testing portion
    $trainLabels = [
        'min_humidity' => [],
        'max_humidity' => [],
        'min_temperature' => [],
        'max_temperature' => []
    ];
    $trainLabels['min_humidity'] = array_slice($shuffledLabels['min_humidity'], 0, $splitIndex);
    $trainLabels['max_humidity'] = array_slice($shuffledLabels['max_humidity'], 0, $splitIndex);
    $trainLabels['min_temperature'] = array_slice($shuffledLabels['min_temperature'], 0, $splitIndex);
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
    echo "Training SVM models.\n";
    $svm_minHumidity = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);
    $svm_maxHumidity = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);
    $svm_minTemperature = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);
    $svm_maxTemperature = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);

    echo "- Training minimum humidity model.";
    $svm_minHumidity->train($trainSamples, $trainLabels['min_humidity']);

    echo "- Training maximum humidity model .";
    $svm_maxHumidity->train($trainSamples, $trainLabels['max_humidity']);

    echo "- Training minimum temperature model";
    $svm_minTemperature->train($trainSamples, $trainLabels['min_temperature']);

    echo "- Training maximum temperature model.";
    $svm_maxTemperature->train($trainSamples, $trainLabels['max_temperature']);


    /*
     * arrays of predictions for each prediction target are created using the test samples and then compared against the
     * test labels using both Mean Absolute Error and Root Mean Squared Error methods
     */
    echo "Testing SVM models.\n";
    $pred_minHumidity = [];
    $pred_maxHumidity = [];
    $pred_minTemperature = [];
    $pred_maxTemperature = [];

    for ($i = 0; $i < count($testSamples); $i++) {
        $pred_minHumidity[] = $svm_minHumidity->predict($testSamples[$i]);
        $pred_maxHumidity[] = $svm_maxHumidity->predict($testSamples[$i]);
        $pred_minTemperature[] = $svm_minTemperature->predict($testSamples[$i]);
        $pred_maxTemperature[] = $svm_maxTemperature->predict($testSamples[$i]);
    }

    echo "Calculating error measures.\n";
    $mae_minHumidity = meanAbsoluteError($testLabels['min_humidity'], $pred_minHumidity);
    $mae_maxHumidity = meanAbsoluteError($testLabels['max_humidity'], $pred_maxHumidity);
    $mae_minTemperature = meanAbsoluteError($testLabels['min_temperature'], $pred_minTemperature);
    $mae_maxTemperature = meanAbsoluteError($testLabels['max_temperature'], $pred_maxTemperature);

    $rmse_minHumidity = rootMeanSquareError($testLabels['min_humidity'], $pred_minHumidity);
    $rmse_maxHumidity = rootMeanSquareError($testLabels['max_humidity'], $pred_maxHumidity);
    $rmse_minTemperature = rootMeanSquareError($testLabels['min_temperature'], $pred_minTemperature);
    $rmse_maxTemperature = rootMeanSquareError($testLabels['max_temperature'], $pred_maxTemperature);


    echo "Minimum humidity prediction error - MAE: " . round($mae_minHumidity, 2) . ", RMSE: " . round($rmse_minHumidity, 2) . "\n";
    echo "Maximum humidity prediction error - MAE: " . round($mae_maxHumidity, 2) . ", RMSE: " . round($rmse_maxHumidity, 2) . "\n";
    echo "Minimum temperature prediction error - MAE: " . round($mae_minTemperature, 2) . ", RMSE: " . round($rmse_minTemperature, 2) . "\n";
    echo "Maximum temperature prediction error - MAE: " . round($mae_maxTemperature, 2) . ", RMSE: " . round($rmse_maxTemperature, 2) . "\n";

} else {
    // Normal mode

    // if there is an existing model file this function renames it by appending the filename with a datetime string
    function archiveModelFile(string $filename): void {
        if (file_exists($filename)) {
            $timestamp = date('YmdHis');
            $newName = preg_replace('/\.model$/', "_$timestamp.model", $filename);
            rename($filename, $newName);
            echo "Archived existing model: $filename → $newName\n";
        }
    }

    archiveModelFile('minHumidity.model');
    archiveModelFile('maxHumidity.model');
    archiveModelFile('minTemperature.model');
    archiveModelFile('maxTemperature.model');

    //train models
    $svm_minHumidity = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);
    $svm_maxHumidity = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);
    $svm_minTemperature = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);
    $svm_maxTemperature = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);

    echo "Training minimum humidity model.\n";
    $svm_minHumidity->train($samples, $labels['min_humidity']);
    file_put_contents('minHumidity.model', $svm_minHumidity->getModel());

    echo "Training maximum humidity model.\n";
    $svm_maxHumidity->train($samples, $labels['max_humidity']);
    file_put_contents('maxHumidity.model', $svm_maxHumidity->getModel());

    echo "Training minimum temperature model.\n";
    $svm_minTemperature->train($samples, $labels['min_temperature']);
    file_put_contents('minTemperature.model', $svm_minTemperature->getModel());

    echo "Training maximum temperature model.\n";
    $svm_maxTemperature->train($samples, $labels['max_temperature']);
    file_put_contents('maxTemperature.model', $svm_maxTemperature->getModel());
}