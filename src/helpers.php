<?php
declare(strict_types=1);

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(mixed $v): string
{
    return ($v === null || $v === '') ? '—' : '$' . number_format((float)$v);
}

function phone_fmt(string $n): string
{
    return strlen($n) === 10 ? sprintf('(%s) %s-%s', substr($n, 0, 3), substr($n, 3, 3), substr($n, 6)) : $n;
}

/** UTC datetime string -> Miami time (Eastern) display, e.g. "Nov 24, 2026 6:44 pm EST" */
function when(?string $utc, string $fmt = 'M j, Y g:i a T'): string
{
    if (!$utc) {
        return '—';
    }
    $t = strtotime($utc . ' UTC');
    return $t ? date($fmt, $t) : '—';
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(\Leads\Auth::csrf()) . '">';
}

function score_class(int $s): string
{
    return $s >= 70 ? 'hot' : ($s >= 50 ? 'warm' : 'cool');
}

function qs(array $changes): string
{
    $q = array_merge($_GET, $changes);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        }
    }
    return '?' . http_build_query($q);
}
