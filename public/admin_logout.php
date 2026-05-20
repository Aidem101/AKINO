<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

admin_logout();

header('Location: Admin_Login.php?logout=1');
exit;
