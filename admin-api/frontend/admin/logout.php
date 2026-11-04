<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_logout();
header('Location: login.php');
exit;
