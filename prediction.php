<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\SupportVectorMachine\SupportVectorMachine;
use Phpml\SupportVectorMachine\Type;
use Phpml\SupportVectorMachine\Kernel;

include 'functions.php';


/*
 * I had a lot of trouble with using models that had been saved and loaded using PHPML ModelManager, the only solution I
 * was able to find that worked was to save models using:
 *
 * file_put_contents('maxHumidity.model', $svm_maxHumidity->getModel());
 *
 * and to load them using the function below.
 * using the predict function of a model that had been saved and loaded with the model manage would produce this error:
 *
 * [07-Jun-2025 23:58:28 Australia/Melbourne] PHP Fatal error:  Uncaught Phpml\Exception\LibsvmCommandException: Failed running libsvm command: "/var/www/iotserver.com/html/vendor/php-ai/php-ml/bin/libsvm/svm-predict -b 0
 * '/var/www/iotserver.com/html/vendor/php-ai/php-ml/var/phpml68444584b7aa55.14949995'
 * '/var/www/iotserver.com/html/vendor/php-ai/php-ml/var/phpml68444584b7aa55.14949995-model'
 * '/var/www/iotserver.com/html/vendor/php-ai/php-ml/var/phpml68444584b7aa55.14949995-output'"
 * with reason: "can't open model file /var/www/iotserver.com/html/vendor/php-ai/php-ml/var/phpml68444584b7aa55.14949995-model" in /var/www/iotserver.com/html/vendor/php-ai/php-ml/src/SupportVectorMachine/SupportVectorMachine.php:249
 * Stack trace:
 * #0 /var/www/iotserver.com/html/vendor/php-ai/php-ml/src/SupportVectorMachine/SupportVectorMachine.php(185): Phpml\SupportVectorMachine\SupportVectorMachine->runSvmPredict()
 * #1 /var/www/iotserver.com/html/prediction.php(95): Phpml\SupportVectorMachine\SupportVectorMachine->predict()
 * #2 {main}
 * thrown in /var/www/iotserver.com/html/vendor/php-ai/php-ml/src/SupportVectorMachine/SupportVectorMachine.php on line 249
 *
 * Various efforts to set the permissions on /vendor/php-ai/phpml/var to ensure the apache service could read from there
 * did not resolve the issue even though I conclusively demonstrated that apache could read and write to the path.
 */
function loadSvmFromModelFile(string $path): ?SupportVectorMachine {
    if (!file_exists($path)) {
        return null;
    }

    $svm = new SupportVectorMachine(Type::EPSILON_SVR, Kernel::RBF);
    $modelStr = file_get_contents($path);

    $ref = new ReflectionClass($svm);
    $prop = $ref->getProperty('model');
    $prop->setAccessible(true);
    $prop->setValue($svm, $modelStr);

    return $svm;
}


// load models
$svr_minHumidity     = loadSvmFromModelFile('minHumidity.model');
$svr_maxHumidity     = loadSvmFromModelFile('maxHumidity.model');
$svr_minTemperature  = loadSvmFromModelFile('minTemperature.model');
$svr_maxTemperature  = loadSvmFromModelFile('maxTemperature.model');

// test for missing models
if (!$svr_minHumidity || !$svr_maxHumidity || !$svr_minTemperature || !$svr_maxTemperature) {
    http_response_code(503);
    exit('One or more model files do not exist or failed to load.');
}

// get URL parameters
$month = $_GET['month'] ?? null;
$dayOfMonth = $_GET['day'] ?? null;
$site = isset($_GET['site']) ? (int)$_GET['site'] : null;

// test for missing parameters
if ($month === null || $dayOfMonth === null || $site === null) {
    http_response_code(400);
    exit('One or more parameters missing from URI.');
}

// translate site ID into site location
switch ($site) {
    case 1: $siteName = 'Wynard'; break;
    case 2: $siteName = 'Launceston'; break;
    case 3: $siteName = 'Smithton'; break;
    case 4: $siteName = 'Hobart'; break;
    case 5: $siteName = 'Campania'; break;
    default:
        http_response_code(400);
        exit('Site not found.');
}

// format input into sample format
$dayOfYear = convertToDayOfYear($month, $dayOfMonth);
$sample = [
    $dayOfYear,
    (int)matchSiteID(1, $site),
    (int)matchSiteID(2, $site),
    (int)matchSiteID(3, $site),
    (int)matchSiteID(4, $site),
    (int)matchSiteID(5, $site)
];

// generate predictions
$pred_minHumidity = $svr_minHumidity->predict($sample);
$pred_maxHumidity = $svr_maxHumidity->predict($sample);
$pred_minTemperature = $svr_minTemperature->predict($sample);
$pred_maxTemperature = $svr_maxTemperature->predict($sample);

// aggregate response properties
$prediction = [
    'minTemp' => round($pred_minTemperature, 1),
    'maxTemp' => round($pred_maxTemperature, 1),
    'minHumi' => round($pred_minHumidity),
    'maxHumi' => round($pred_maxHumidity),
    'locName' => $siteName
];

// save input and reponse to disk for use in graphing
$graphInfo = [
    'month' => $month,
    'day' => $dayOfMonth,
    'site' => $site,
    'prediction' => $prediction
];
$graphJSON = json_encode($graphInfo, JSON_PRETTY_PRINT);
file_put_contents('graph_input.json', $graphJSON);

// format prediction response into json and return to the connecting device
$response = json_encode((object)$prediction);
http_response_code(200);
header('Content-type: application/json');
echo $response;
?>
