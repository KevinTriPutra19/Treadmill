<?php

function parse_duration_minutes($value)
{
    if ($value === null) {
        return 0.0;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return 0.0;
    }

    if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $value, $matches)) {
        $hours = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];

        return $hours * 60 + $minutes + ($seconds / 60);
    }

    if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:menit|mins?|m)?$/i', $value, $matches)) {
        return (float) $matches[1];
    }

    return (float) $value;
}

function parse_distance_km($value)
{
    if ($value === null) {
        return 0.0;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return 0.0;
    }

    if (preg_match('/(\d+(?:[\.,]\d+)?)/', $value, $matches)) {
        return (float) str_replace(',', '.', $matches[1]);
    }

    return 0.0;
}

function estimate_calories($durationMinutes, $distanceKm)
{
    $durationMinutes = (float) $durationMinutes;
    $distanceKm = (float) $distanceKm;

    $timeCalories = $durationMinutes > 0 ? $durationMinutes * 8 : 0;
    $distanceCalories = $distanceKm > 0 ? $distanceKm * 60 : 0;

    if ($timeCalories > 0 && $distanceCalories > 0) {
        // Jika keduanya tersedia, gunakan kombinasi agar kontribusi waktu dan jarak sama-sama dihitung.
        return (int) round(($timeCalories + $distanceCalories) / 2);
    }

    if ($distanceCalories > 0) {
        return (int) round($distanceCalories);
    }

    if ($timeCalories > 0) {
        return (int) round($timeCalories);
    }

    return 0;
}

function format_duration_readable($minutes)
{
    $minutes = max(0, (float) $minutes);
    if ($minutes <= 0) {
        return '0 menit';
    }

    $totalSeconds = (int) round($minutes * 60);
    $hours = intdiv($totalSeconds, 3600);
    $remaining = $totalSeconds % 3600;
    $mins = intdiv($remaining, 60);
    $seconds = $remaining % 60;

    if ($hours > 0) {
        return sprintf('%d jam %02d menit', $hours, $mins);
    }

    if ($mins > 0) {
        return $seconds > 0 ? sprintf('%d menit %02d detik', $mins, $seconds) : sprintf('%d menit', $mins);
    }

    return sprintf('%d detik', $seconds);
}
