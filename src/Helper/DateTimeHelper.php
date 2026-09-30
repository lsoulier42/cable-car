<?php

namespace App\Helper;

use DateTimeInterface;

class DateTimeHelper
{
    public const array FRENCH_MONTHS = [
            "Janvier",
            "Février",
            "Mars",
            "Avril",
            "Mai",
            "Juin",
            "Juillet",
            "Août",
            "Septembre",
            "Octobre",
            "Novembre",
            "Décembre"
        ];

    /**
     * @param DateTimeInterface $date
     * @return string
     */
    public static function formatMonthYearFrench(DateTimeInterface $date): string
    {
        $year = $date->format('Y');
        $month = (int)$date->format('m');
        return self::FRENCH_MONTHS[$month] . " " . $year;
    }
}
