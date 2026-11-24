<?php
declare(strict_types=1);

namespace Leads;

final class View
{
    public static function render(string $template, array $vars = [], ?string $title = null): void
    {
        extract($vars, EXTR_SKIP);
        $__title = $title;
        ob_start();
        require LEADS_ROOT . '/views/' . $template . '.php';
        $__content = (string)ob_get_clean();
        require LEADS_ROOT . '/views/layout.php';
    }

    public static function flash(?string $msg = null, string $kind = 'ok'): ?array
    {
        if ($msg !== null) {
            $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
            return null;
        }
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f;
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }
}
