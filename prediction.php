<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\Regression\SVR;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\ModelManager;

include 'functions.php';

$modelManager = new ModelManager();
$svr_minHumidity = new SVR(Kernel::RBF);
$svr_maxHumidity = new SVR(Kernel::RBF);
$svr_minTemperature = new SVR(Kernel::RBF);
$svr_maxTemperature = new SVR(Kernel::RBF);
$modelMissing = False;


$minHumidity_file = 'minHumidity.svr';
if (file_exists($minHumidity_file)) {
    $svc_minHumidity = $modelManager->restoreFromFile($minHumidity_file);
} else {
    $modelMissing = True;
}

$maxHumidity_file = 'maxHumidity.svr';
if (file_exists($maxHumidity_file)) {
    $svc_maxHumidity = $modelManager->restoreFromFile($maxHumidity_file);
} else {
    $modelMissing = True;
}

$minTemperature_file = 'minTemperature.svr';
if (file_exists($minTemperature_file)) {
    $svc_minTemperature = $modelManager->restoreFromFile($minTemperature_file);
} else {
    $modelMissing = True;
}

$maxTemperature_file = 'maxTemperature.svr';
if (file_exists($maxTemperature_file)) {
    $svc_maxTemperature = $modelManager->restoreFromFile($maxTemperature_file);
} else {
    $modelMissing = True;
}

if ($modelMissing) {
    http_response_code(503);
    exit('One or more SVR files do not exist.');
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
    case 1:
        $siteName = 'Wynard';
        break;
    case 2:
        $siteName = 'Launceston';
        break;
    case 3:
        $siteName = 'Smithton';
        break;
    case 4:
        $siteName = 'Hobart';
        break;
    case 5:
        $siteName = 'Campania';
        break;
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
    'minTemp' => $pred_minTemperature,
    'maxTemp' => $pred_maxTemperature,
    'minHumi' => $pred_minHumidity,
    'maxHumi' => $pred_maxHumidity,
    'locName' => $siteName
];

$response = json_encode((object)$prediction);
http_response_code(200);
header('Content-type: application/json');
echo $response;

?>
