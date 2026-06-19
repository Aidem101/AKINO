<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$csrfToken = $_GET['csrf'] ?? null;

if (!admin_verify_csrf_token(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Сессия выхода устарела. Вернитесь в административную панель и повторите попытку.';
    exit;
}

admin_logout();

header('Location: Admin_Login.php?logout=1');
exit;
