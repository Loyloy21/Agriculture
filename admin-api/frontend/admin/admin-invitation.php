<?php
declare(strict_types=1);

// Public invitation page for a replacement administrator: the invited person sets their own password.
// No session is started and no admin access is granted here; completing setup only marks the process
// Ready for Transfer, which the current administrator must then confirm in Settings.
// The token is read from the URL fragment by the page script and sent only in POST bodies, so it never appears in
// server access logs or Referer headers.
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/lib/admin_replacement.php';

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $respond = static function (int $status, array $payload): never {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };
    $raw = (string) file_get_contents('php://input', false, null, 0, 8192);
    $body = json_decode($raw, true);
    if (!is_array($body)) $respond(400, ['success' => false, 'message' => 'The request is invalid.']);
    $token = is_string($body['token'] ?? null) ? $body['token'] : '';
    try {
        $connection = db();
        if (($body['action'] ?? '') === 'check') {
            $check = admin_replacement_token_state($connection, $token);
            if ($check['state'] !== 'valid') $respond(410, ['success' => false, 'state' => $check['state'], 'message' => admin_replacement_state_message($check['state'])]);
            $respond(200, ['success' => true, 'state' => 'valid', 'fullName' => (string) $check['invitation']['full_name'], 'email' => (string) $check['invitation']['email'],
                'expiresAt' => admin_utc_to_manila((string) $check['invitation']['token_expires_at'])]);
        }
        if (($body['action'] ?? '') === 'complete') {
            $result = admin_replacement_complete_setup($connection, $token, is_string($body['password'] ?? null) ? $body['password'] : '', is_string($body['passwordConfirmation'] ?? null) ? $body['passwordConfirmation'] : '');
            $respond(200, ['success' => true, 'fullName' => $result['fullName'], 'email' => $result['email']]);
        }
        $respond(422, ['success' => false, 'message' => 'The request is invalid.']);
    } catch (AdminReplacementException $exception) {
        if ($exception->httpStatus === 429) header('Retry-After: 900');
        $respond($exception->httpStatus, ['success' => false, 'message' => $exception->getMessage(), 'field' => $exception->field]);
    } catch (Throwable $exception) {
        error_log('Admin invitation request failed: ' . get_class($exception));
        $respond(500, ['success' => false, 'message' => 'The invitation could not be checked right now. Please try again later.']);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Administrator Invitation | AgriReach</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/admin-redesign.css?v=20261024">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-brand-panel">
            <div class="brand-mark">AR</div>
            <p class="eyebrow">Department of Agriculture</p>
            <h1>AgriReach</h1>
            <p>Administrator account hand-over.</p>
        </section>
        <section class="login-card-wrap">
            <div class="login-card">
                <p class="eyebrow text-success mb-2">Administrator invitation</p>
                <h2 id="invitationTitle">Checking your invitation…</h2>
                <div id="invitationMessage" class="alert py-2" role="status" hidden></div>
                <form id="invitationForm" novalidate hidden>
                    <p class="text-muted small">Set your own password for the AgriReach admin website. You will <strong>not</strong> have access yet: the current administrator must confirm the transfer first. Your login username is your email address.</p>
                    <div class="mb-3"><label class="form-label" for="invitedName">Name</label><input class="form-control" id="invitedName" readonly></div>
                    <div class="mb-3"><label class="form-label" for="invitedEmail">Email (login username)</label><input class="form-control" id="invitedEmail" autocomplete="username" readonly></div>
                    <div class="mb-3"><label class="form-label" for="password">New password</label><input class="form-control" type="password" id="password" autocomplete="new-password" maxlength="72" required aria-describedby="passwordHelp"><div id="passwordHelp" class="form-text">At least 8 characters with uppercase, lowercase and a number.</div></div>
                    <div class="mb-3"><label class="form-label" for="passwordConfirmation">Confirm password</label><input class="form-control" type="password" id="passwordConfirmation" autocomplete="new-password" maxlength="72" required></div>
                    <p class="small text-muted" id="invitationExpiry"></p>
                    <button class="btn btn-success btn-lg w-100" type="submit" id="invitationSubmit">Set password</button>
                </form>
            </div>
        </section>
    </main>
<script>
(() => {
  const token = new URLSearchParams(location.hash.slice(1)).get('token') || '';
  const title = document.getElementById('invitationTitle');
  const message = document.getElementById('invitationMessage');
  const form = document.getElementById('invitationForm');
  const submit = document.getElementById('invitationSubmit');
  const show = (text, tone) => { message.textContent = text; message.className = 'alert py-2 alert-' + tone; message.hidden = false; };
  const post = async (payload) => {
    const response = await fetch(location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ token, ...payload }), credentials: 'omit', referrerPolicy: 'no-referrer' });
    const data = await response.json().catch(() => ({ success: false, message: 'The server response could not be read.' }));
    return { ok: response.ok, data };
  };
  (async () => {
    if (!token) { title.textContent = 'Invitation link incomplete'; show('Open the full link from your invitation email.', 'warning'); return; }
    const { ok, data } = await post({ action: 'check' });
    if (!ok) { title.textContent = 'Invitation unavailable'; show(data.message, 'warning'); return; }
    title.textContent = 'Set your administrator password';
    document.getElementById('invitedName').value = data.fullName;
    document.getElementById('invitedEmail').value = data.email;
    document.getElementById('invitationExpiry').textContent = 'This invitation expires on ' + data.expiresAt + ' (Philippine time) and can be used once.';
    form.hidden = false;
  })().catch(() => { title.textContent = 'Invitation unavailable'; show('The invitation could not be checked. Please try again later.', 'danger'); });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (submit.disabled) return;
    submit.disabled = true;
    message.hidden = true;
    try {
      const { ok, data } = await post({ action: 'complete', password: document.getElementById('password').value, passwordConfirmation: document.getElementById('passwordConfirmation').value });
      if (!ok) { show(data.message, data.field === 'form' ? 'warning' : 'danger'); if (data.field === 'form') form.hidden = true; return; }
      form.reset(); form.hidden = true;
      history.replaceState(null, '', location.pathname);
      title.textContent = 'Password set';
      show('Your password is set. You do not have administrator access yet: the current administrator must now confirm the transfer. After that, sign in at the admin login with ' + data.email + ' and this password.', 'success');
    } catch {
      show('The password could not be saved. Please try again.', 'danger');
    } finally {
      submit.disabled = false;
    }
  });
})();
</script>
</body>
</html>
