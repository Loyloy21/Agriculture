<?php
declare(strict_types=1);

// Compatibility route: the admin logout moved to admin/logout.php. This old URL keeps its original
// behavior (end the admin session, then go to the login page) for bookmarks and old links.
require_once __DIR__ . '/../frontend/admin/includes/bootstrap.php';
admin_logout();
header('Location: ../admin/login.php');
exit;
