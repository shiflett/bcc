<?php
// includes/layout.php

function html_head(string $title, array $extra_css = [], array $extra_js = []): void {
    $full_title = $title === 'Backcountry Club' ? $title : "{$title} — Backcountry Club";
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$full_title}</title>
    <link rel="stylesheet" href="/css/app.css">
HTML;
    foreach ($extra_css as $href) {
        echo "    <link rel=\"stylesheet\" href=\"{$href}\">\n";
    }
    foreach ($extra_js as $src) {
        echo "    <script src=\"{$src}\"></script>\n";
    }
    echo "</head>\n<body>\n";
}

function html_foot(array $scripts = []): void {
    foreach ($scripts as $src) {
        echo "<script src=\"{$src}\"></script>\n";
    }
    echo "</body>\n</html>\n";
}

function fmt_ele(float $metres): string {
    return number_format(round($metres * 3.28084)) . ' ft';
}

function fmt_dist(float $metres): string {
    return round($metres / 1609.34, 1) . ' mi';
}

function fmt_time(string $ts): string {
    return (new DateTimeImmutable($ts))
        ->setTimezone(new DateTimeZone('America/Denver'))
        ->format('g:i a');
}

function fmt_date_range(string $start, string $end): string {
    $s = new DateTimeImmutable($start);
    $e = new DateTimeImmutable($end);
    if ($s->format('Y-m-d') === $e->format('Y-m-d')) {
        return $s->format('M j, Y');
    }
    if ($s->format('Y-m') === $e->format('Y-m')) {
        return $s->format('M j') . '–' . $e->format('j, Y');
    }
    return $s->format('M j') . ' – ' . $e->format('M j, Y');
}
