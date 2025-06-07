<?php

/*
convertToDayOfYear()
turns month and day to just day of the year e.g. March 3rd becomes 31+28+3=62
prevents inference of relationship between the same day of each month, e.g the 10th of march and the 10th of
september have no specific relationship
*/
Function convertToDayOfYear($m, $d) {  # turns month and day to just day of the year e.g. March 3rd becomes 31+28+3=62
    $totalDays = 0;
    static $daysInMonth = [ 0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, ];
    for ($i=0; $i < $m; $i++) {
        $totalDays = $totalDays + $daysInMonth[$i];
    }

    $totalDays = $totalDays + $d;

    return $totalDays;
}

/*
matchSiteID()
Used to produce $sample training dataset with no numeric relationship between sites.  e.g. Site ID's are arbitrary and
There is nothing to be inferred from the fact that site '2' is in between sites '1' and '3' or that it is half of site '4'
 */
Function matchSiteID($staticSite, $variableSite) {
    return $staticSite === $variableSite ? 1 : 0;
}
