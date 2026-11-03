<?php
/* Developer: Alindevx00x (https://alindevx00x.me) */
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_csrf_is_valid()) {
    unset(
        $_SESSION['admin_user_id'],
        $_SESSION['admin_username'],
        $_SESSION['admin_login_attempts'],
        $_SESSION['admin_login_blocked_until'],
        $_SESSION['admin_csrf_token']
    );
    session_regenerate_id(true);
}

header('Location: index.php');
exit;
