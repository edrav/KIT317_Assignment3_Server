<?php

$historicalDataFileName = 'training_data.csv';
$predictionDataFileName = 'graph_input.json';


// load last prediction input and response
$predictionDataString = file_get_contents($predictionDataFileName);
$predictionData = json_decode($predictionDataString, true);

// Extract prediction values
$targetMonth = $predictionData['month'];
$targetDay = $predictionData['day'];
$targetSite = $predictionData['site'];

// Filter data matching site/month/day
$siteDayHistory = [];

if (($handle = fopen($historicalDataFileName, 'r')) !== false) {
    $headers = fgetcsv($handle);
    $headers = array_map('trim', $headers);
    # had to use fopen and fgetcsv as using array_map('str_getcsv', file($input_csv)); as in the model-trainer script
    # would hit a memory limit on running php in apache.  It worked for the model-trainer as that was run from the
    # command line

    while (($row = fgetcsv($handle)) !== false) {
        $rowAssoc = array_combine($headers, $row);                          # apply labels to this row data
        if ((int)$rowAssoc['site'] === (int)$predictionData['site']) {      # is this row for the right site?
            [$day, $month, $year] = explode('/', $rowAssoc['date']);        # then get extract the day, month, and year
            if ((int)$month === (int)$predictionData['month'] && (int)$day === (int)$predictionData['day']) {  # is this row for the right month and day?
                [$hours, $minutes, $seconds] = explode(':', $rowAssoc['time']);     #then process the row and place its content in an array of rows with data corresponding to the prediction input
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
    fclose($handle);
}

//Calculate half hourly averages
$averages = [];
for ($i=0;$i<1440;$i=$i+30) {

    $sumTemp = 0.0;
    $sumHumi = 0;
    $count = 0;

    #examination of the data showed that most measurements were taken at XX:00 or XX:30 but not all so average is based
    #all the measurements that occur on the half-hour and/or in the half-hour afterward
    foreach ($siteDayHistory as $siteDay) {
        if ($siteDay['time'] >= $i and $siteDay['time'] < $i + 30) {
            $sumTemp += $siteDay['temperature'];
            $sumHumi += $siteDay['humidity'];
            $count++;
        }
    }

    if ($count > 0) {
        $avgTemp = $sumTemp / $count;
        $avgHumi = $sumHumi / $count;
        $averages[] = [
            'timeInMinutes' => $i,
            'averageTemperature' => round($avgTemp, 1),
            'averageHumidity' => round($avgHumi)
        ];
    }
}

//create a date formatted as March 3rd, September 15th, etc.
$dateobject = DateTime::createFromFormat('!m-d', "$targetMonth-$targetDay");
$formattedDate = $dateobject->format('F jS');

//split out temperature and humidity averages and format for use with CanvasJS
$temperatureDataPoints = [];
$humidityDataPoints = [];
foreach ($averages as $record) {
    $timeInMinutes = $record['timeInMinutes'];
    $hours = floor($timeInMinutes / 60);
    $minutes = $timeInMinutes % 60;
    $label = sprintf('%02d:%02d', $hours, $minutes);
    $temperatureDataPoints[] = [
        'label' => $label,
        'y' => $record['averageTemperature'],
    ];
    $humidityDataPoints[] = [
        'label' => $label,
        'y' => $record['averageHumidity'],
    ];
}

?>

<script src="https://canvasjs.com/assets/script/canvasjs.min.js"></script>
<div id="temperatureChartContainer" style="height: 300px; width: 100%;"></div>
<script>
var temperatureChart = new CanvasJS.Chart("temperatureChartContainer", {
    title: {
        text: "<?=$predictionData['prediction']['locName'] ?> - Half hourly average temperatures <?=$formattedDate ?>"
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
            text: "<?=$predictionData['prediction']['locName'] ?> - Half hourly average humidity <?=$formattedDate ?>"
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