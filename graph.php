<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$historicalDataFileName = 'training_data.csv';
$predictionDataFileName = 'graph_input.json';

// load data
$historicalData = array_map('str_getcsv', file($historicalDataFileName));  // raw data
$headers = array_map('trim', array_shift($historicalData));               // header row

$predictionDataString = file_get_contents($predictionDataFileName);
$predictionData = json_decode($predictionDataString, true);

// Extract prediction values
$targetMonth = $predictionData['month'];
$targetDay = $predictionData['day'];
$targetSite = $predictionData['site'];

// Filter data matching site/month/day
$siteDayHistory = [];

foreach ($historicalData as $row) {
    $rowAssoc = array_combine($headers, $row);  // convert indexed row to assoc using headers

    if ((int)$rowAssoc['site'] === (int)$targetSite) {
        [$year, $month, $day] = explode('-', $rowAssoc['date']);
        if ((int)$month === (int)$targetMonth && (int)$day === (int)$targetDay) {

            [$hours, $minutes, $seconds] = explode(':', $rowAssoc['time']);
            $timeInMinutes = (int)$hours * 60 + (int)$minutes;
            $siteDayHistory[] = [
                'year' => (int)$year,
                'month' => (int)$month,
                'day' => (int)$day,
                'time' => $timeInMinutes,
                'temperature' => (float)$rowAssoc['temperature'],
                'humidity' => (int)$rowAssoc['humidity']
            ];
        }
    }
}

//Calculate half hourly averages
$averages = [];
for ($i=0;$i<1440;$i=$i+30) {

    $sumTemp = 0.0;
    $sumHumi = 0;
    $count = 0;

    foreach ($siteDayHistory as $siteDay) {
        if ($siteDay['time'] >= $i and $siteDay['time'] < $i + 30) {
            $sumTemp += $siteDay['temperature'];
            $sumHumi += $siteDay['humidity'];
            $count++;
        }
    }

    $avgTemp = $sumTemp / $count;
    $avgHumi = $sumHumi / $count;

    $averages[] = [
        'timeInMinutes' => $i,
        'averageTemperature' => round($avgTemp, 1),
        'averageHumidity' => round($avgHumi)
    ];
}

$dateobject = DateTime::createFromFormat('!m-d', "$targetMonth-$targetDay");
$formattedDate = $dateobject->format('F jS');

$temperatureDataPoints = [];
$humidityDataPoints = [];
foreach ($averages as $record) {
    $timeInMinutes = $record['timeInMinutes'];
    $hours = floor($timeInMinutes / 60);
    $minutes = $minutes % 60;
    $label = sprintf('%02d:%02d', $hours, $minutes);
    $temperatureDataPoints[] = [
        'label' => $label,
        'temperature' => $record['averageTemperature'],
    ];
    $humidityDataPoints[] = [
        'label' => $label,
        'humidity' => $record['averageHumidity'],
    ];
}

?>

<script src="https://canvasjs.com/assets/script/canvasjs.min.js"></script>
<div id="chartContainer" style="height: 300px; width: 100%;"></div>
<script>
var temperatureChart = new CanvasJS.Chart("temperatureChartContainer", {
    title: {
        text: "<?=$predictionData['prediction']['locname'] ?> - Half hourly average temperatures <?=$formattedDate ?>"
    },
    axisX: {
        title: "Time (HH:mm)"
    },
    axisY: {
        title: "Temperature (°C)"
    },
    data: [{
        type: "line",
        dataPoints: <?= json_encode($temperatureDataPoints, JSON_NUMERIC_CHECK); ?>
    }]
});
temperatureChart.render();
</script>
<div style="margin-top: 20px;">
    <table style="width: 100%; text-align: center; font-family: Arial, sans-serif;">
        <thead>
            <tr>
                <th colspan="2">Predicted Temperature</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Min: <?= $predictionData['prediction']['minTemp'] ?>&deg;C</td>
                <td>Max: <?= $predictionData['prediction']['maxTemp'] ?>&deg;C</td>
            </tr>
        </tbody>
    </table>
</div>

<div id="humidityChartContainer" style="height: 300px; width: 100%;"></div>
<script>
    var humidityChart = new CanvasJS.Chart("humidityChartContainer", {
        title: {
            text: "<?=$predictionData['prediction']['locname'] ?> - Half hourly average humidity <?=$formattedDate ?>"
        },
        axisX: {
            title: "Time (HH:mm)"
        },
        axisY: {
            title: "Humidity (%)"
        },
        data: [{
            type: "line",
            dataPoints: <?= json_encode($humidityDataPoints, JSON_NUMERIC_CHECK); ?>
        }]
    });
    humidityChart.render();
</script>
<div style="margin-top: 20px;">
    <table style="width: 100%; text-align: center; font-family: Arial, sans-serif;">
        <thead>
        <tr>
            <th colspan="2">Predicted Humidity</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>Min: <?= $predictionData['prediction']['minHumi'] ?>%</td>
            <td>Max: <?= $predictionData['prediction']['maxHumi'] ?>%</td>
        </tr>
        </tbody>
    </table>
</div>