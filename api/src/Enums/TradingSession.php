<?php

namespace App\Enums;

enum TradingSession: string
{
    case ASIA = 'ASIA';
    case EUROPE = 'EUROPE';
    case EUROPE_US = 'EUROPE_US';
    case US = 'US';
    case OFF = 'OFF';

    private const SESSIONS = [
        'EUROPE' => ['timezone' => 'Europe/Paris', 'startHour' => 8, 'startMin' => 0, 'endHour' => 16, 'endMin' => 30],
        'US' => ['timezone' => 'America/New_York', 'startHour' => 9, 'startMin' => 30, 'endHour' => 16, 'endMin' => 0],
        'ASIA' => ['timezone' => 'Asia/Tokyo', 'startHour' => 9, 'startMin' => 0, 'endHour' => 15, 'endMin' => 0],
    ];

    /**
     * Classify a UTC datetime into a trading session using real timezones (DST-aware).
     * Detects EUROPE_US overlap when both sessions are active simultaneously.
     */
    public static function classify(\DateTimeInterface $closedAtUtc): self
    {
        $active = [];

        foreach (self::SESSIONS as $name => $def) {
            $local = \DateTime::createFromInterface($closedAtUtc);
            $local->setTimezone(new \DateTimeZone($def['timezone']));

            $hour = (int) $local->format('H');
            $min = (int) $local->format('i');
            $timeInMinutes = $hour * 60 + $min;

            $startMinutes = $def['startHour'] * 60 + $def['startMin'];
            $endMinutes = $def['endHour'] * 60 + $def['endMin'];

            if ($timeInMinutes >= $startMinutes && $timeInMinutes < $endMinutes) {
                $active[] = $name;
            }
        }

        if (in_array('EUROPE', $active) && in_array('US', $active)) {
            return self::EUROPE_US;
        }

        if (count($active) > 0) {
            return self::from($active[0]);
        }

        return self::OFF;
    }

    /**
     * Return session definitions for frontend display (timezone + hours).
     */
    public static function getSessionDefinitions(): array
    {
        return self::SESSIONS;
    }
}
