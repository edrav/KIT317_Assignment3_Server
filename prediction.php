<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\SupportVectorMachine\SupportVectorMachine;
use Phpml\SupportVectorMachine\Type;
use Phpml\SupportVectorMachine\Kernel;

include 'functions.php';

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

$svr_minHumidity     = loadSvmFromModelFile('minHumidity.model');
$svr_maxHumidity     = loadSvmFromModelFile('maxHumidity.model');
$svr_minTemperature  = loadSvmFromModelFile('minTemperature.model');
$svr_maxTemperature  = loadSvmFromModelFile('maxTemperature.model');

if (!$svr_minHumidity || !$svr_maxHumidity || !$svr_minTemperature || !$svr_maxTemperature) {
    http_response_code(503);
    exit('One or more model files do not exist or failed to load.');
}

$month = $_GET['month'] ?? null;
$dayOfMonth = $_GET['day'] ?? null;
$site = isset($_GET['site']) ? (int)$_GET['site'] : null;

error_log("month=$month&day=$dayOfMonth&site=$site");

if ($month === null || $dayOfMonth === null || $site === null) {
    http_response_code(400);
    exit('One or more parameters missing from URI.');
}

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

$dayOfYear = convertToDayOfYear($month, $dayOfMonth);
$sample = [
    $dayOfYear,
    (int)matchSiteID(1, $site),
    (int)matchSiteID(2, $site),
    (int)matchSiteID(3, $site),
    (int)matchSiteID(4, $site),
    (int)matchSiteID(5, $site)
];

$pred_minHumidity = $svr_minHumidity->predict($sample);
$pred_maxHumidity = $svr_maxHumidity->predict($sample);
$pred_minTemperature = $svr_minTemperature->predict($sample);
$pred_maxTemperature = $svr_maxTemperature->predict($sample);

$prediction = [
    'minTemp' => round($pred_minTemperature, 1),
    'maxTemp' => round($pred_maxTemperature, 1),
    'minHumi' => round($pred_minHumidity),
    'maxHumi' => round($pred_maxHumidity),
    'locName' => $siteName
];

$graphInfo = [
    'month' => $month,
    'day' => $dayOfMonth,
    'site' => $site,
    'prediction' => $prediction
];

$graphJSON = json_encode($graphInfo, JSON_PRETTY_PRINT);

file_put_contents('graph_input.json', $graphJSON);

$response = json_encode((object)$prediction);
http_response_code(200);
header('Content-type: application/json');
echo $response;
?>
