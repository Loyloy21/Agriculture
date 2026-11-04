<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pagination.php';
require_admin();
require_once __DIR__ . '/../../backend/lib/admin_farms.php';
require_once __DIR__ . '/../../backend/lib/private_uploads.php';
require_once __DIR__ . '/../../backend/lib/admin_users.php';
require_once __DIR__ . '/../../backend/lib/farmer_identity.php';

$csrf_token = admin_csrf_token();
$message = '';
$message_type = 'success';
$allowed_roles = ['Farmer', 'Administrator'];
$allowed_statuses = ['Active', 'Inactive', 'Blocked'];
$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$role = in_array($_GET['role'] ?? null, $allowed_roles, true) ? (string) $_GET['role'] : '';
$account_status = in_array($_GET['account_status'] ?? null, $allowed_statuses, true) ? (string) $_GET['account_status'] : '';
$list_filters = ['search' => $search, 'role' => $role, 'account_status' => $account_status];
$return_url = admin_page_url('manage-users.php', $list_filters, admin_requested_page($_GET['page'] ?? null));

function user_action_error(string $text): void
{
    global $return_url;
    $_SESSION['manage_users_error'] = $text;
    header('Location: ' . $return_url);
    exit;
}

function valid_user_type(string $user_type): bool
{
    return in_array($user_type, ['farmer', 'admin'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) {
        user_action_error('Your session token is invalid. Please try again.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $user_type = (string) ($_POST['user_type'] ?? '');
    $user_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        $connection = db();
        if ($action === 'add') {
            $first_name = farmer_name_part((string) ($_POST['first_name'] ?? ''));
            $middle_name = farmer_name_part((string) ($_POST['middle_name'] ?? ''), true);
            $last_name = farmer_name_part((string) ($_POST['last_name'] ?? ''));
            $extension_name = farmer_name_part((string) ($_POST['extension_name'] ?? ''), true);
            $gender = (string) ($_POST['gender'] ?? '');
            $contact_number = trim((string) ($_POST['contact_number'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $address = trim((string) ($_POST['address'] ?? ''));
            $date_of_birth = (string) ($_POST['date_of_birth'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $confirm_password = (string) ($_POST['confirm_password'] ?? '');
            $farm_name = trim((string) ($_POST['farm_name'] ?? ''));
            $farm_type = trim((string) ($_POST['farm_type'] ?? ''));
            $agricultural_category = trim((string) ($_POST['agricultural_category'] ?? ''));
            $farm_location = trim((string) ($_POST['farm_location'] ?? ''));
            $farm_size = filter_var($_POST['farm_size'] ?? null, FILTER_VALIDATE_FLOAT);
            $main_product = trim((string) ($_POST['main_product'] ?? ''));
            $latitude_value = trim((string) ($_POST['latitude'] ?? ''));
            $longitude_value = trim((string) ($_POST['longitude'] ?? ''));
            $latitude = $latitude_value === '' ? null : filter_var($latitude_value, FILTER_VALIDATE_FLOAT);
            $longitude = $longitude_value === '' ? null : filter_var($longitude_value, FILTER_VALIDATE_FLOAT);
            $id_type = trim((string) ($_POST['id_type'] ?? ''));
            $id_number = trim((string) ($_POST['id_number'] ?? ''));
            $allowed_farm_options = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
            $birth_date = DateTime::createFromFormat('!Y-m-d', $date_of_birth);
            $birth_date_valid = $birth_date && $birth_date->format('Y-m-d') === $date_of_birth && $birth_date < new DateTime('today');
            if ($first_name === '' || mb_strlen($first_name) > 75 || $last_name === '' || mb_strlen($last_name) > 75 || !in_array($gender, ['Male', 'Female', 'Other', 'Prefer not to say'], true) || !preg_match('/^[0-9+() .-]{7,40}$/', $contact_number) || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($address) > 255 || !$birth_date_valid || mb_strlen($password) < 8 || !hash_equals($password, $confirm_password) || $farm_name === '' || mb_strlen($farm_name) > 150 || !in_array($farm_type, $allowed_farm_options, true) || !in_array($agricultural_category, $allowed_farm_options, true) || $farm_location === '' || mb_strlen($farm_location) > 255 || $farm_size === false || $farm_size <= 0 || $main_product === '' || mb_strlen($main_product) > 150 || ($latitude !== null && ($latitude === false || $latitude < -90 || $latitude > 90)) || ($longitude !== null && ($longitude === false || $longitude < -180 || $longitude > 180)) || $id_type === '' || mb_strlen($id_type) > 100 || mb_strlen($id_number) > 100) {
                user_action_error('Complete all required fields with valid personal and farm information. Passwords must contain at least 8 characters.');
            }
            if (!farmer_middle_name_valid($middle_name) || !farmer_extension_name_valid($extension_name)) {
                user_action_error('Enter a valid Middle Name and Extension Name (such as Jr., Sr., II or III), or leave them blank.');
            }
            if ($id_type === RSBSA_ID_TYPE) {
                // Same rule as app registration: the complete 15-digit reference number, stored as 00-00-00-000-000000.
                if (rsbsa_reference_error($id_number) !== null) user_action_error('Enter all 15 digits of the RSBSA reference number (' . RSBSA_REFERENCE_FORMAT . ').');
                $id_number = (string) rsbsa_reference_normalize($id_number);
            }

            if (admin_user_contact_taken($connection, $email, $contact_number)) {
                user_action_error('That email address or contact number is already registered.');
            }

            $upload = $_FILES['valid_id'] ?? null;
            $allowed_mime_types = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name']) || (int) $upload['size'] > 8 * 1024 * 1024) {
                user_action_error('Upload a JPG or PNG valid ID image no larger than 8MB.');
            }
            $file_info = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $file_info->file((string) $upload['tmp_name']);
            if (!isset($allowed_mime_types[$mime_type]) || @getimagesize((string) $upload['tmp_name']) === false) {
                user_action_error('The valid ID must be a real JPG or PNG image.');
            }
            // Same private storage as mobile registration (outside the web root); found by basename on read.
            try {
                $upload_directory = ensure_private_farmer_id_directory();
            } catch (RuntimeException) {
                user_action_error('The valid ID image could not be saved.');
            }
            $stored_filename = generated_image_filename($allowed_mime_types[$mime_type]);
            $stored_path = $upload_directory . DIRECTORY_SEPARATOR . $stored_filename;
            if (!move_uploaded_file((string) $upload['tmp_name'], $stored_path)) {
                user_action_error('The valid ID image could not be saved.');
            }

            $full_name = farmer_compose_full_name($first_name, $middle_name, $last_name, $extension_name);
            if (mb_strlen($full_name) > 150) user_action_error('The full name is too long. Shorten the middle name.');
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            admin_create_pending_farmer(
                $connection,
                ['full_name' => $full_name, 'first_name' => $first_name, 'middle_name' => $middle_name, 'last_name' => $last_name, 'extension_name' => $extension_name, 'gender' => $gender, 'date_of_birth' => $date_of_birth, 'contact_number' => $contact_number, 'email' => $email, 'address' => $address, 'password_hash' => $password_hash],
                ['farm_name' => $farm_name, 'farm_type' => $farm_type, 'agricultural_category' => $agricultural_category, 'land_area' => $farm_size, 'farm_address' => $farm_location, 'latitude' => $latitude, 'longitude' => $longitude, 'main_product' => $main_product],
                ['id_type' => $id_type, 'id_number' => $id_number, 'image_path' => $stored_filename]
            );
            $_SESSION['manage_users_flash'] = 'Farmer added with Pending verification status. Review the application in Farmer Verification; it will appear here after approval.';
            header('Location: ' . $return_url);
            exit;
        }

        if ($user_id === false || !valid_user_type($user_type)) {
            user_action_error('Invalid user action.');
        }

        if ($user_type === 'farmer') {
            if (admin_farmer_verification_status($connection, $user_id) !== 'Approved') {
                user_action_error('Only approved farmers can be managed here. Review pending or rejected registrations in Farmer Verification.');
            }
        }

        if ($action === 'edit') {
            $contact_number = trim((string) ($_POST['contact_number'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $address = trim((string) ($_POST['address'] ?? ''));
            // Farmers with stored name parts are edited part by part and full_name is recomposed from them;
            // administrators and older farmer records without parts keep the single Full Name field.
            $name_parts = null;
            if ($user_type === 'farmer' && array_key_exists('first_name', $_POST)) {
                $name_parts = [
                    'first' => farmer_name_part((string) $_POST['first_name']),
                    'middle' => farmer_name_part((string) ($_POST['middle_name'] ?? ''), true),
                    'last' => farmer_name_part((string) ($_POST['last_name'] ?? '')),
                    'extension' => farmer_name_part((string) ($_POST['extension_name'] ?? ''), true),
                ];
                $full_name = farmer_compose_full_name($name_parts['first'], $name_parts['middle'], $name_parts['last'], $name_parts['extension']);
                if ($name_parts['first'] === '' || mb_strlen($name_parts['first']) > 75 || $name_parts['last'] === '' || mb_strlen($name_parts['last']) > 75 || !farmer_middle_name_valid($name_parts['middle']) || !farmer_extension_name_valid($name_parts['extension'])) {
                    user_action_error('Enter a valid First and Last Name. Middle Name and Extension Name (such as Jr., Sr., II or III) are optional.');
                }
            } else {
                $full_name = trim((string) ($_POST['full_name'] ?? ''));
            }
            if ($full_name === '' || mb_strlen($full_name) > 150 || !preg_match('/^[0-9+() .-]{7,40}$/', $contact_number) || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($address) > 255) {
                user_action_error('Enter valid profile information.');
            }
            if (admin_user_contact_taken($connection, $email, $contact_number, $user_type, $user_id)) {
                user_action_error('That email address or contact number is already registered.');
            }
            $profile_changed = $name_parts !== null
                ? admin_farmer_update_profile($connection, $user_id, $name_parts, $contact_number, $email, $address)
                : admin_user_update_profile($connection, $user_type, $user_id, $full_name, $contact_number, $email, $address);
            $_SESSION['manage_users_flash'] = $profile_changed ? 'User profile updated successfully.' : 'No profile changes were made.';
            header('Location: ' . $return_url);
            exit;
        }

        if (in_array($action, ['toggle', 'block'], true)) {
            if ($user_type === 'admin') {
                // Exactly one active administrator: admin access changes only through Settings → Admin Account (replacement).
                user_action_error($user_id === (int) $_SESSION['admin_id'] ? 'You cannot change your own account status.' : 'Administrator access is changed only through Settings → Admin Account (Replace Admin Account).');
            }
            $column = $action === 'block' ? 'Blocked' : 'Inactive';
            $current_status = admin_user_account_status($connection, $user_type, $user_id);
            if ($current_status === null) {
                user_action_error('User account not found.');
            }
            if ($action === 'toggle') {
                $column = $current_status === 'Active' ? 'Inactive' : 'Active';
            } elseif ($current_status === 'Blocked') {
                $column = 'Active';
            }
            admin_user_set_account_status($connection, $user_type, $user_id, $column);
            $_SESSION['manage_users_flash'] = 'User account status updated to ' . $column . '.';
            header('Location: ' . $return_url);
            exit;
        }
        user_action_error('Unsupported user action.');
    } catch (Throwable $exception) {
        if (isset($connection) && $connection->inTransaction()) {
            $connection->rollBack();
        }
        if (isset($stored_path) && is_file($stored_path)) {
            unlink($stored_path);
        }
        user_action_error('The user action could not be completed. Check the database setup and try again.');
    }
}

if (!empty($_SESSION['manage_users_flash'])) {
    $message = (string) $_SESSION['manage_users_flash'];
    unset($_SESSION['manage_users_flash']);
}
if (!empty($_SESSION['manage_users_error'])) {
    $message = (string) $_SESSION['manage_users_error'];
    $message_type = 'danger';
    unset($_SESSION['manage_users_error']);
}

$users = [];
$load_error = '';
$total_users = 0;
$page_state = admin_page_state(0, admin_requested_page($_GET['page'] ?? null));
try {
    $connection = db();
    $total_users = admin_user_count($connection, $search, $role, $account_status);
    $page_state = admin_page_state($total_users, admin_requested_page($_GET['page'] ?? null));
    admin_redirect_clamped_page('manage-users.php', $list_filters, $page_state);
    $users = admin_user_page($connection, $search, $role, $account_status, $page_state['per_page'], $page_state['offset']);
    foreach ($users as &$listedUser) {
        $listedUser['linked_farms'] = $listedUser['user_type'] === 'farmer' ? admin_linked_farms(db(), (int) $listedUser['user_id']) : [];
    }
    unset($listedUser);
} catch (Throwable $exception) {
    $load_error = 'User data is not available yet. Import the updated database setup first.';
}

$page_title = 'Manage Users';
$active_page = 'manage-users';
$page_css = 'assets/css/manage-users.css';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-1">Account administration</p><h1 class="page-title mb-1">Manage Users</h1><p class="text-muted mb-0">View registered users and maintain their AgriReach account access.</p></div><button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus"></i> Add User</button></div>
    <?php if ($message !== ''): ?><div class="alert alert-<?= e($message_type) ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($load_error !== ''): ?><div class="alert alert-warning" role="alert"><?= e($load_error) ?></div><?php endif; ?>
    <section class="content-card"><form class="row g-2 align-items-end mb-4" method="get"><div class="col-12 col-lg-5"><label class="form-label small fw-semibold" for="search">Search users</label><input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Name, email, or contact number"></div><div class="col-12 col-md-5 col-lg-3"><label class="form-label small fw-semibold" for="role">Role</label><select class="form-select" id="role" name="role"><option value="">All roles</option><?php foreach ($allowed_roles as $option): ?><option value="<?= e($option) ?>"<?= $role === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-5 col-lg-3"><label class="form-label small fw-semibold" for="account_status">Account status</label><select class="form-select" id="account_status" name="account_status"><option value="">All statuses</option><?php foreach ($allowed_statuses as $option): ?><option value="<?= e($option) ?>"<?= $account_status === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-2 col-lg-1"><button class="btn btn-success w-100" type="submit">Filter</button></div></form>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="section-title mb-1">Registered users</h2><p class="text-muted small mb-0"><?= $total_users ?> user<?= $total_users === 1 ? '' : 's' ?> found</p></div></div><div class="table-responsive"><table class="table align-middle manage-users-table mb-0"><thead><tr><th>User Name</th><th>Contact Number</th><th>Email</th><th>Location</th><th>Role</th><th>Account Status</th><th>Date Joined</th><th class="text-end">Action</th></tr></thead><tbody><?php if (!$users): ?><tr><td colspan="8" class="text-center py-5 text-muted">No users match the selected filters.</td></tr><?php endif; ?><?php foreach ($users as $user): $detail_json = json_encode($user, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?><tr><td><strong><?= e((string) $user['user_name']) ?></strong></td><td><?= e((string) $user['contact_number']) ?></td><td><?= e((string) $user['email']) ?></td><td><?= e((string) ($user['farm_address'] ?: $user['address'] ?: '—')) ?></td><td><span class="role-pill <?= $user['user_type'] === 'admin' ? 'administrator' : 'farmer' ?>"><?= e((string) $user['user_role']) ?></span></td><td><span class="account-status <?= strtolower((string) $user['account_status']) ?>"><?= e((string) $user['account_status']) ?></span></td><td><?= e(date('M d, Y', strtotime((string) $user['date_registered']))) ?></td><td class="text-end action-cell"><button class="btn btn-outline-success btn-sm view-user-btn" type="button" data-user-id="<?= e((string) $user['user_key']) ?>">View Details</button><button class="user-actions-toggle" type="button" data-user-id="<?= e((string) $user['user_key']) ?>" aria-label="Actions for <?= e((string) $user['user_name']) ?>" aria-haspopup="menu" aria-controls="userActionsMenu" aria-expanded="false"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button><script type="application/json" id="user-data-<?= e((string) $user['user_key']) ?>"><?= $detail_json ?></script></td></tr><?php endforeach; ?></tbody></table></div><?= admin_pagination_html($page_state, 'manage-users.php', $list_filters) ?></section>
</div>

<div class="user-actions-menu" id="userActionsMenu" role="menu" aria-label="User account actions" hidden>
    <button class="user-menu-action" id="menuEditUser" type="button" role="menuitem" data-menu-item><i class="bi bi-pencil" aria-hidden="true"></i><span>Edit</span></button>
    <form method="post" id="menuToggleForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
        <input type="hidden" name="user_type" id="menuToggleUserType">
        <input type="hidden" name="user_id" id="menuToggleUserId">
        <input type="hidden" name="action" value="toggle">
        <button class="user-menu-action" id="menuToggleAction" type="submit" role="menuitem" data-menu-item><i class="bi bi-person-dash" aria-hidden="true"></i><span>Deactivate</span></button>
    </form>
    <form method="post" id="menuBlockForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
        <input type="hidden" name="user_type" id="menuBlockUserType">
        <input type="hidden" name="user_id" id="menuBlockUserId">
        <input type="hidden" name="action" value="block">
        <button class="user-menu-action user-menu-action-danger" id="menuBlockAction" type="submit" role="menuitem" data-menu-item><i class="bi bi-slash-circle" aria-hidden="true"></i><span>Block</span></button>
    </form>
</div>

<div class="modal fade" id="userDetailsModal" tabindex="-1" aria-labelledby="userDetailsTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content verification-modal"><div class="modal-header"><div><p class="eyebrow mb-1">Account information</p><h2 class="modal-title fs-5" id="userDetailsTitle">User details</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="detail-grid"><section><h3>Personal Information</h3><dl id="userPersonalDetails"></dl></section><section><h3>Account Information</h3><dl id="userAccountDetails"></dl></section><section class="farmer-only-detail linked-farms"><h3>All linked farms</h3><dl id="userFarmerDetails"></dl></section></div></div></div></div></div>
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content verification-modal"><div class="modal-header"><h2 class="modal-title fs-5" id="editUserTitle">Edit user</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="user_type" id="editUserType"><input type="hidden" name="user_id" id="editUserId"><div class="row g-3"><div class="col-md-6" id="editFullNameGroup"><label class="form-label" for="editFullName">Full Name</label><input class="form-control" id="editFullName" name="full_name" required></div><div class="col-12" id="editNameParts" hidden><div class="row g-3"><div class="col-md-6"><label class="form-label" for="editFirstName">First Name <span class="required-mark">*</span></label><input class="form-control" id="editFirstName" name="first_name" maxlength="75" disabled></div><div class="col-md-6"><label class="form-label" for="editMiddleName">Middle Name <span class="text-muted small">(optional)</span></label><input class="form-control" id="editMiddleName" name="middle_name" maxlength="75" disabled></div><div class="col-md-6"><label class="form-label" for="editLastName">Last Name <span class="required-mark">*</span></label><input class="form-control" id="editLastName" name="last_name" maxlength="75" disabled></div><div class="col-md-6"><label class="form-label" for="editExtensionName">Extension Name <span class="text-muted small">(optional, e.g. Jr., Sr., III)</span></label><input class="form-control" id="editExtensionName" name="extension_name" maxlength="20" disabled></div><div class="col-12"><div class="form-text">Full name: <strong id="editComposedName"></strong></div></div></div></div><div class="col-md-6"><label class="form-label" for="editContact">Contact Number</label><input class="form-control" id="editContact" name="contact_number" required></div><div class="col-md-6"><label class="form-label" for="editEmail">Email</label><input class="form-control" type="email" id="editEmail" name="email" required></div><div class="col-12"><label class="form-label" for="editAddress">Address</label><textarea class="form-control" id="editAddress" name="address" rows="3" required></textarea></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Save Changes</button></div></form></div></div></div>
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content verification-modal"><div class="modal-header"><div><p class="eyebrow mb-1">Farmer registration</p><h2 class="modal-title fs-5" id="addUserTitle">Add New User</h2><p class="text-muted small mb-0">Create a farmer account for verification by the Department of Agriculture.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><form method="post" enctype="multipart/form-data" id="addUserForm"><div class="modal-body add-user-modal-body"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="add"><section class="registration-section"><h3>Personal Information</h3><div class="row g-3"><div class="col-md-6"><label class="form-label" for="addFirstName">First Name <span class="required-mark">*</span></label><input class="form-control" id="addFirstName" name="first_name" required maxlength="75"></div><div class="col-md-6"><label class="form-label" for="addMiddleName">Middle Name <span class="text-muted small">(optional)</span></label><input class="form-control" id="addMiddleName" name="middle_name" maxlength="75"></div><div class="col-md-6"><label class="form-label" for="addLastName">Last Name <span class="required-mark">*</span></label><input class="form-control" id="addLastName" name="last_name" required maxlength="75"></div><div class="col-md-6"><label class="form-label" for="addExtensionName">Extension Name <span class="text-muted small">(optional, e.g. Jr., Sr., III)</span></label><input class="form-control" id="addExtensionName" name="extension_name" maxlength="20"></div><div class="col-md-6"><label class="form-label" for="addGender">Gender <span class="required-mark">*</span></label><select class="form-select" id="addGender" name="gender" required><option value="">Select gender</option><option>Male</option><option>Female</option><option>Other</option><option>Prefer not to say</option></select></div><div class="col-md-6"><label class="form-label" for="addEmail">Email <span class="required-mark">*</span></label><input class="form-control" type="email" id="addEmail" name="email" required maxlength="160"></div><div class="col-md-6"><label class="form-label" for="addContact">Phone Number <span class="required-mark">*</span></label><input class="form-control" type="tel" id="addContact" name="contact_number" required maxlength="40"></div><div class="col-md-6"><label class="form-label" for="addDateOfBirth">Date of Birth <span class="required-mark">*</span></label><input class="form-control" type="date" id="addDateOfBirth" name="date_of_birth" required></div><div class="col-12"><label class="form-label" for="addAddress">Address <span class="required-mark">*</span></label><textarea class="form-control" id="addAddress" name="address" rows="2" required maxlength="255"></textarea></div><div class="col-md-6"><label class="form-label" for="addPassword">Password <span class="required-mark">*</span></label><input class="form-control" type="password" id="addPassword" name="password" minlength="8" required><div class="form-text">The password is securely hashed before storage.</div></div></div></section><section class="registration-section"><h3>Farm Information</h3><div class="row g-3"><div class="col-md-6"><label class="form-label" for="addFarmName">Farm Name <span class="required-mark">*</span></label><input class="form-control" id="addFarmName" name="farm_name" required maxlength="150"></div><div class="col-md-6"><label class="form-label" for="addFarmType">Farm Type <span class="required-mark">*</span></label><input class="form-control" id="addFarmType" name="farm_type" required maxlength="100" placeholder="e.g. Rice, Vegetable, Livestock"></div><div class="col-md-8"><label class="form-label" for="addFarmLocation">Farm Location <span class="required-mark">*</span></label><input class="form-control" id="addFarmLocation" name="farm_location" required maxlength="255"></div><div class="col-md-4"><label class="form-label" for="addFarmSize">Farm Size (hectares) <span class="required-mark">*</span></label><input class="form-control" type="number" id="addFarmSize" name="farm_size" min="0.01" step="0.01" required></div><div class="col-md-6"><label class="form-label" for="addMainProduct">Main Product <span class="required-mark">*</span></label><input class="form-control" id="addMainProduct" name="main_product" required maxlength="150"></div></div></section><section class="registration-section"><h3>Verification</h3><div class="row g-3 align-items-start"><div class="col-md-7"><label class="form-label" for="validId">Upload Valid ID <span class="required-mark">*</span></label><input class="form-control" type="file" id="validId" name="valid_id" accept="image/jpeg,image/png" required><div class="form-text">Accept JPG or PNG. Maximum file size: 8MB.</div></div><div class="col-md-5"><div class="id-upload-preview" id="newIdPreview"><span>Image preview will appear here.</span></div></div></div></section></div><div class="modal-footer modal-action-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit"><i class="bi bi-person-plus"></i> Add User</button></div></form></div></div></div>
<script src="assets/js/admin-farms.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const escapeHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const text = (value) => escapeHtml(value || '—');
  const row = (label, value) => `<div><dt>${escapeHtml(label)}</dt><dd>${text(value)}</dd></div>`;
  const getUser = (key) => JSON.parse(document.getElementById(`user-data-${key}`).textContent);
  const getModal = (id) => {
    const element = document.getElementById(id);
    return element && window.bootstrap?.Modal ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
  };
  // Mirrors the server: First Middle Last Extension, empty parts omitted, single spaces.
  const composeName = (...parts) => parts.map((part) => String(part || '').trim().replace(/\s+/g, ' ')).filter(Boolean).join(' ');
  const showComposedName = () => { document.getElementById('editComposedName').textContent = composeName(...['editFirstName', 'editMiddleName', 'editLastName', 'editExtensionName'].map((id) => document.getElementById(id).value)); };
  ['editFirstName', 'editMiddleName', 'editLastName', 'editExtensionName'].forEach((id) => document.getElementById(id)?.addEventListener('input', showComposedName));
  const renderEdit = (data) => {
    document.getElementById('editUserType').value = data.user_type;
    document.getElementById('editUserId').value = data.user_id;
    document.getElementById('editFullName').value = data.user_name || '';
    const partsMode = data.user_type === 'farmer' && !!data.first_name && !!data.last_name;
    document.getElementById('editFullNameGroup').hidden = partsMode;
    document.getElementById('editFullName').disabled = partsMode;
    document.getElementById('editNameParts').hidden = !partsMode;
    [['editFirstName', data.first_name], ['editMiddleName', data.middle_name], ['editLastName', data.last_name], ['editExtensionName', data.extension_name]].forEach(([id, value]) => { const input = document.getElementById(id); input.value = value || ''; input.disabled = !partsMode; input.required = partsMode && (id === 'editFirstName' || id === 'editLastName'); });
    showComposedName();
    document.getElementById('editContact').value = data.contact_number || '';
    document.getElementById('editEmail').value = data.email || '';
    document.getElementById('editAddress').value = data.address || '';
    let farms = document.getElementById('editLinkedFarms');
    if (!farms) { farms = document.createElement('section'); farms.id = 'editLinkedFarms'; farms.className = 'linked-farms mt-3'; document.querySelector('#editUserModal .modal-body').appendChild(farms); }
    farms.innerHTML = data.user_type === 'farmer' ? '<h3 class="section-title">All linked farms (read-only here)</h3>' + renderAdminFarms(data.linked_farms) : '';
    getModal('editUserModal')?.show();
  };
  const actionMenu = document.getElementById('userActionsMenu');
  const menuEditUser = document.getElementById('menuEditUser');
  const menuToggleAction = document.getElementById('menuToggleAction');
  const menuBlockAction = document.getElementById('menuBlockAction');
  let activeActionButton = null;
  let activeUserData = null;

  const menuItems = () => Array.from(actionMenu.querySelectorAll('[data-menu-item]')).filter((item) => !item.closest('[hidden]'));
  const closeActionMenu = (restoreFocus = false) => {
    if (actionMenu.hidden) return;
    const previousButton = activeActionButton;
    actionMenu.hidden = true;
    actionMenu.style.removeProperty('left');
    actionMenu.style.removeProperty('top');
    actionMenu.style.removeProperty('max-height');
    actionMenu.removeAttribute('aria-label');
    previousButton?.setAttribute('aria-expanded', 'false');
    activeActionButton = null;
    activeUserData = null;
    if (restoreFocus) previousButton?.focus({ preventScroll: true });
  };
  const positionActionMenu = (button) => {
    const viewportPadding = 8;
    const gap = 6;
    actionMenu.style.visibility = 'hidden';
    actionMenu.hidden = false;
    actionMenu.style.maxHeight = `${Math.max(120, window.innerHeight - (viewportPadding * 2))}px`;
    const buttonRect = button.getBoundingClientRect();
    const menuRect = actionMenu.getBoundingClientRect();
    const left = Math.min(
      window.innerWidth - menuRect.width - viewportPadding,
      Math.max(viewportPadding, buttonRect.right - menuRect.width)
    );
    let top = buttonRect.bottom + gap;
    if (top + menuRect.height > window.innerHeight - viewportPadding) top = buttonRect.top - menuRect.height - gap;
    top = Math.max(viewportPadding, Math.min(top, window.innerHeight - menuRect.height - viewportPadding));
    actionMenu.style.left = `${left}px`;
    actionMenu.style.top = `${top}px`;
    actionMenu.style.visibility = 'visible';
  };
  const openActionMenu = (button, focusLast = false) => {
    if (activeActionButton === button && !actionMenu.hidden) {
      closeActionMenu(true);
      return;
    }
    closeActionMenu(false);
    const data = getUser(button.dataset.userId);
    activeActionButton = button;
    activeUserData = data;
    button.setAttribute('aria-expanded', 'true');
    actionMenu.setAttribute('aria-label', `Actions for ${data.user_name || 'user'}`);
    document.getElementById('menuToggleUserType').value = data.user_type;
    document.getElementById('menuToggleUserId').value = data.user_id;
    document.getElementById('menuBlockUserType').value = data.user_type;
    document.getElementById('menuBlockUserId').value = data.user_id;
    menuToggleAction.querySelector('span').textContent = data.account_status === 'Active' ? 'Deactivate' : 'Activate';
    menuBlockAction.querySelector('span').textContent = data.account_status === 'Blocked' ? 'Unblock' : 'Block';
    // Administrator access is changed only through Settings → Admin Account (single active administrator).
    document.getElementById('menuToggleForm').hidden = data.user_type === 'admin';
    document.getElementById('menuBlockForm').hidden = data.user_type === 'admin';
    positionActionMenu(button);
    const items = menuItems();
    (focusLast ? items.at(-1) : items[0])?.focus({ preventScroll: true });
  };

  document.addEventListener('click', (event) => {
    const button = event.target?.closest?.('.user-actions-toggle');
    if (button) openActionMenu(button);
  });
  document.addEventListener('keydown', (event) => {
    const button = event.target?.closest?.('.user-actions-toggle');
    if (button) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        openActionMenu(button, event.key === 'ArrowUp');
      }
    }
  });
  menuEditUser.addEventListener('click', () => {
    if (!activeActionButton) return;
    const data = getUser(activeActionButton.dataset.userId);
    closeActionMenu(false);
    renderEdit(data);
  });
  actionMenu.addEventListener('keydown', (event) => {
    const items = menuItems();
    const currentIndex = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      closeActionMenu(true);
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      const nextIndex = currentIndex < 0 ? 0 : (currentIndex + direction + items.length) % items.length;
      items[nextIndex]?.focus();
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      (event.key === 'Home' ? items[0] : items.at(-1))?.focus();
    }
  });
  document.getElementById('menuToggleForm').addEventListener('submit', (event) => {
    if (!activeUserData) {
      event.preventDefault();
      return;
    }
    const actionLabel = activeUserData.account_status === 'Active' ? 'deactivate' : 'activate';
    if (!window.confirm(`Are you sure you want to ${actionLabel} ${activeUserData.user_name}?`)) event.preventDefault();
  });
  document.getElementById('menuBlockForm').addEventListener('submit', (event) => {
    if (!activeUserData) {
      event.preventDefault();
      return;
    }
    const actionLabel = activeUserData.account_status === 'Blocked' ? 'unblock' : 'block';
    if (!window.confirm(`Are you sure you want to ${actionLabel} ${activeUserData.user_name}?`)) event.preventDefault();
  });
  document.addEventListener('pointerdown', (event) => {
    if (!actionMenu.hidden && !actionMenu.contains(event.target) && !activeActionButton?.contains(event.target)) closeActionMenu(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !actionMenu.hidden) closeActionMenu(true);
  });
  window.addEventListener('resize', () => closeActionMenu(false));
  window.addEventListener('scroll', (event) => {
    if (!actionMenu.hidden && !actionMenu.contains(event.target)) closeActionMenu(false);
  }, true);
  document.querySelectorAll('.view-user-btn').forEach((button) => button.addEventListener('click', () => { const data = getUser(button.dataset.userId); document.getElementById('userDetailsTitle').textContent = data.user_name || 'User details'; document.getElementById('userPersonalDetails').innerHTML = [row('Full Name', data.user_name), ...(data.user_type === 'farmer' ? [row('First Name', data.first_name), row('Middle Name', data.middle_name), row('Last Name', data.last_name), row('Extension Name', data.extension_name)] : []), row('Contact Number', data.contact_number), row('Email', data.email), row('Address', data.address)].join(''); document.getElementById('userAccountDetails').innerHTML = [row('Role', data.user_role), row('Account Status', data.account_status), row('Verification Status', data.verification_status), row('Date Registered', data.date_registered)].join(''); const farmerSection = document.querySelector('.farmer-only-detail'); farmerSection.hidden = data.user_type !== 'farmer'; document.getElementById('userFarmerDetails').innerHTML = renderAdminFarms(data.linked_farms); getModal('userDetailsModal')?.show(); }));
  const idInput = document.getElementById('validId');
  const idPreview = document.getElementById('newIdPreview');
  const farmSection = document.querySelectorAll('#addUserForm .registration-section')[1];
  const farmTypeInput = document.getElementById('addFarmType');
  if (farmSection && farmTypeInput) {
    farmTypeInput.outerHTML = '<select class="form-select" id="addFarmType" name="farm_type" required><option value="">Select farm type</option><option>Crops</option><option>Livestock</option><option>Poultry</option><option>Fishpond</option></select>';
    document.getElementById('addFarmType').closest('.col-md-6').insertAdjacentHTML('afterend', '<div class="col-md-6"><label class="form-label" for="addAgriculturalCategory">Agricultural Category <span class="required-mark">*</span></label><select class="form-select" id="addAgriculturalCategory" name="agricultural_category" required><option value="">Select category</option><option>Crops</option><option>Livestock</option><option>Poultry</option><option>Fishpond</option></select></div>');
    document.getElementById('addFarmSize').closest('.col-md-4').insertAdjacentHTML('afterend', '<div class="col-md-4"><label class="form-label" for="addLatitude">Latitude</label><input class="form-control" type="number" id="addLatitude" name="latitude" min="-90" max="90" step="0.0000001"></div><div class="col-md-4"><label class="form-label" for="addLongitude">Longitude</label><input class="form-control" type="number" id="addLongitude" name="longitude" min="-180" max="180" step="0.0000001"></div>');
  }
  const personalSection = document.querySelectorAll('#addUserForm .registration-section')[0];
  const passwordInput = document.getElementById('addPassword');
  if (personalSection && passwordInput && !document.getElementById('addConfirmPassword')) {
    const personalRow = personalSection.querySelector('.row');
    const addressColumn = document.getElementById('addAddress').closest('.col-12');
    const dobColumn = document.getElementById('addDateOfBirth').closest('.col-md-6');
    const passwordColumn = passwordInput.closest('.col-md-6');
    personalRow.insertBefore(addressColumn, dobColumn);
    passwordColumn.insertAdjacentHTML('afterend', '<div class="col-md-6"><label class="form-label" for="addConfirmPassword">Confirm Password <span class="required-mark">*</span></label><input class="form-control" type="password" id="addConfirmPassword" name="confirm_password" minlength="8" required placeholder="Re-enter password"></div><div class="col-md-6"><label class="form-label" for="addRole">Role <span class="required-mark">*</span></label><select class="form-select" id="addRole" name="role" required><option value="Farmer" selected>Farmer</option></select></div>');
  }
  const verificationSection = document.querySelectorAll('#addUserForm .registration-section')[2];
  if (verificationSection && idInput) {
    const oldIdLabel = idInput.closest('.col-md-7').querySelector('label[for="validId"]');
    oldIdLabel?.remove();
    idInput.setAttribute('accept', '.jpg,.jpeg,.png,image/jpeg,image/png');
    idInput.classList.add('visually-hidden', 'position-absolute');
    idInput.insertAdjacentHTML('beforebegin', '<label class="upload-dropzone" for="validId"><i class="bi bi-upload"></i><strong>Click to upload ID photo</strong><span class="upload-filename" id="uploadFilename">JPG, JPEG, or PNG up to 8MB</span></label>');
    idInput.closest('.col-md-7').insertAdjacentHTML('afterbegin', '<div class="mb-3"><label class="form-label" for="addIdType">Valid ID Type <span class="required-mark">*</span></label><select class="form-select" id="addIdType" name="id_type" required><option value="">Select ID type</option><option>National ID</option><option>Driver\'s License</option><option>Passport</option><option>Voter\'s ID</option><option>Other</option></select></div><div class="mb-3"><label class="form-label" for="addIdNumber">Valid ID Number</label><input class="form-control" id="addIdNumber" name="id_number" maxlength="100"></div>');
  }
  idInput?.addEventListener('change', () => {
    const file = idInput.files?.[0];
    if (!file) { idPreview.innerHTML = '<span>Image preview will appear here.</span>'; return; }
    document.getElementById('uploadFilename').textContent = file.name;
    if (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 8 * 1024 * 1024) { idInput.setCustomValidity('Please choose a JPG or PNG image no larger than 8MB.'); idPreview.innerHTML = '<span class="text-danger">Invalid image type or size.</span>'; return; }
    idInput.setCustomValidity('');
    const reader = new FileReader();
    reader.addEventListener('load', () => { idPreview.innerHTML = `<img src="${reader.result}" alt="Valid ID preview">`; });
    reader.readAsDataURL(file);
  });
  document.getElementById('addUserForm')?.addEventListener('submit', (event) => {
    const farmSize = Number(document.getElementById('addFarmSize').value);
    const password = document.getElementById('addPassword');
    const confirmPassword = document.getElementById('addConfirmPassword');
    password.setCustomValidity('');
    confirmPassword.setCustomValidity(password.value === confirmPassword.value ? '' : 'Passwords do not match.');
    if (!Number.isFinite(farmSize) || farmSize <= 0) { event.preventDefault(); document.getElementById('addFarmSize').setCustomValidity('Farm size must be a positive number.'); document.getElementById('addFarmSize').reportValidity(); } else { document.getElementById('addFarmSize').setCustomValidity(''); }
  });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
