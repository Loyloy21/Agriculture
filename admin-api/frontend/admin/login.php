<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/admin_accounts.php';

if (is_admin_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$notice = match (true) {
    isset($_GET['transferred']) => 'Administrator access was transferred. You have been signed out, and your former admin account can no longer sign in. The new administrator signs in with their own email and password.'
        . (($_GET['notifications'] ?? '') === 'incomplete' ? ' Not every transfer notification email could be sent; the transfer itself is complete.' : ''),
    isset($_GET['ended']) => 'Your administrator session has ended because this account no longer has admin access (for example, after an admin transfer).',
    isset($_GET['unavailable']) => 'Your administrator session could not be verified because the database is unavailable. Please try again.',
    default => '',
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $admin = admin_find_by_username(db(), $username);

            // Only the single active administrator may sign in (a replaced or disabled admin account cannot).
            if ($admin && $admin['status'] === 'active' && $admin['account_status'] === 'Active' && $admin['role'] === 'admin' && password_verify($password, $admin['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['admin_role'] = $admin['role'];
                header('Location: dashboard.php');
                exit;
            }
            $error = 'Invalid login credentials.';
        } catch (Throwable $exception) {
            $error = 'Unable to connect to the database. Import the SQL setup first.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login | AgriReach</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/admin-redesign.css?v=20261010">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-brand-panel">
            <div class="brand-mark">AR</div>
            <p class="eyebrow">Department of Agriculture</p>
            <h1>AgriReach</h1>
            <p>Real-time agricultural damage reporting and weather-driven decision support.</p>
        </section>
        <section class="login-card-wrap">
            <div class="login-card">
                <p class="eyebrow text-success mb-2">Admin portal</p>
                <h2>Welcome back</h2>
                <p class="text-muted mb-4">Sign in to manage the AgriReach platform.</p>
                <?php if ($notice !== '' && $error === ''): ?><div class="alert alert-info py-2" role="status"><?= e($notice) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
                <form method="post" novalidate>
                    <div class="mb-3"><label class="form-label" for="username">Username</label><input class="form-control form-control-lg" id="username" name="username" autocomplete="username" required></div>
                    <div class="mb-4"><label class="form-label" for="password">Password</label><input class="form-control form-control-lg" type="password" id="password" name="password" autocomplete="current-password" required></div>
                    <button class="btn btn-success btn-lg w-100" type="submit">Sign in</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
