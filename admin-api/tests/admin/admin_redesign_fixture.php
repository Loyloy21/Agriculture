<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';
$connection = db();
if (($argv[1] ?? '') === 'cleanup-orphan-qa-notices') {
    // Only remnants of this harness's earlier uniquely named controlled
    // advisories, after their test admin/advisory were already removed.
    $s = $connection->prepare("DELETE FROM notifications WHERE notification_type = 'Weather Advisory'
        AND title REGEXP '^admin-redesign-[a-f0-9]{12} advisory$'
        AND message LIKE '%Controlled advice' AND created_by IS NULL AND related_advisory_id IS NULL");
    $s->execute();
    echo 'Removed ' . $s->rowCount() . " leftover notices created by this redesign QA harness; no reports or legacy notices removed.\n";
    exit;
}
if (($argv[1] ?? '') === 'setup') {
    $files = []; $connection->beginTransaction();
    try {
    $marker = 'admin-redesign-' . bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(12)) . 'Aa1'; $hash = password_hash($password, PASSWORD_DEFAULT);
    $takeover = test_admin_takeover($connection, 'Controlled Redesign Reviewer', $marker, $hash); $adminId = $takeover['id']; $adminPrevious = $takeover['previous'];
    $email = $marker . '@example.test'; $contact = '+639' . random_int(100000000,999999999);
    $statement = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, password_hash, verification_status, account_status) VALUES ('Controlled Redesign Farmer', ?, ?, 'Controlled test address', ?, 'Approved', 'Active')");
    $statement->execute([$contact, $email, $hash]); $farmerId = (int) $connection->lastInsertId();
    $farms = [];
    // Choose coverage with no pre-existing eligible recipient: QA never sends
    // its advisory to real farmers, including farmers with secondary farms.
    $qaBarangay = $connection->query("SELECT cb.psgc_code FROM cabadbaran_barangays cb WHERE NOT EXISTS (
        SELECT 1 FROM farm_profiles fp INNER JOIN farmers f ON f.id = fp.farmer_id
        WHERE fp.status = 'Active' AND fp.location_review_status = 'Verified'
          AND fp.city_psgc_code = '1600203000' AND fp.agricultural_category = 'Livestock'
          AND fp.barangay_psgc_code = cb.psgc_code
          AND f.verification_status = 'Approved' AND f.account_status = 'Active'
    ) ORDER BY cb.psgc_code LIMIT 1")->fetch()['psgc_code'] ?? null;
    if (!$qaBarangay) throw new RuntimeException('No isolated advisory coverage is available for controlled QA.');
    foreach (['Crops','Livestock','Archived'] as $i => $category) {
        $status = $category === 'Archived' ? 'Archived' : 'Active'; $category = $category === 'Archived' ? 'Crops' : $category;
        $farmName = $marker . ' farm ' . ($i+1); $primary = $i === 0 ? 1 : 0;
        $statement = $connection->prepare("INSERT INTO farm_profiles (farmer_id,farm_name,farm_type,agricultural_category,land_area,farm_address,city_psgc_code,barangay_psgc_code,location_review_status,is_primary,status,latitude,longitude) VALUES (?, ?, ?, ?, 1.25, 'Controlled test address', '1600203000', '1600203040', 'Verified', ?, ?, 9.1172, 125.5296)");
        $statement->execute([$farmerId,$farmName,$category,$category,$primary,$status]); $farms[] = (int) $connection->lastInsertId();
        if ($i === 1) { $s = $connection->prepare('UPDATE farm_profiles SET barangay_psgc_code = ? WHERE id = ?'); $s->execute([$qaBarangay, $farms[$i]]); }
    }
    $code = 'AR-QA-' . strtoupper(substr($marker,-12)); $description = str_repeat('Controlled long-text layout and assessment check. ',20);
    $statement = $connection->prepare("INSERT INTO damage_reports (report_code, farmer_id, farm_profile_id, agricultural_category, damage_type, damage_description, estimated_damage_cost,status,submitted_address,submitted_latitude,submitted_longitude,location_source,location_photo_order,location_confirmed_at) VALUES (?, ?, ?, 'Crops', 'Flood', ?, 12500.50, 'Under Review', 'Controlled test address', 9.1172,125.5296,'photo_exif',1,NOW())");
    $statement->execute([$code,$farmerId,$farms[0],$description]); $reportId = (int) $connection->lastInsertId();
    $images = []; $files = [];
    foreach ([[9.1172,125.5296,'photo_exif'],[9.1128,125.5352,'device_capture'],[null,null,null]] as $i => [$lat,$lon,$source]) {
        $path = 'storage/report-images/' . $marker . '-' . $i . '.jpg';
        $synthetic = imagecreatetruecolor(640, 360);
        imagefill($synthetic, 0, 0, imagecolorallocate($synthetic, 80 + $i * 25, 125, 90));
        imagestring($synthetic, 5, 20, 20, 'CONTROLLED TEST PHOTO ' . ($i + 1), imagecolorallocate($synthetic, 255, 255, 255));
        imagejpeg($synthetic, dirname(__DIR__, 2) . '/' . $path, 85); imagedestroy($synthetic); $files[] = $path;
        $statement = $connection->prepare('INSERT INTO report_images (report_id,image_path,display_order,gps_latitude,gps_longitude,gps_source) VALUES (?,?,?,?,?,?)');
        $statement->execute([$reportId,$path,$i,$lat,$lon,$source]); $images[]=(int) $connection->lastInsertId();
    }
    $title = $marker . ' advisory'; $category = 'Livestock';
    $statement = $connection->prepare("INSERT INTO weather_advisories (title,agricultural_category,weather_condition,location_coverage,coverage_scope,advisory_message,effective_date,status,created_by) VALUES (?,?,'Heavy Rain','Controlled barangay coverage','Barangays','Controlled advice',CURRENT_DATE,'Draft',?)");
    $statement->execute([$title,$category,$adminId]); $advisoryId=(int) $connection->lastInsertId();
    $s=$connection->prepare('INSERT INTO weather_advisory_barangays (advisory_id,barangay_code,barangay_name) SELECT ?, psgc_code, barangay_name FROM cabadbaran_barangays WHERE psgc_code = ?'); $s->execute([$advisoryId,$qaBarangay]);
    $statement = $connection->prepare("INSERT INTO notifications (farmer_id,notification_type,title,message,related_report_id,created_by) VALUES (?,'Admin Response','Controlled QA notification','Controlled review and read-state isolation',?,?)");
    $statement->execute([$farmerId,$reportId,$adminId]); $notificationId=(int) $connection->lastInsertId();
    $json = json_encode(compact('marker','password','adminId','adminPrevious','farmerId','email','contact','farms','code','reportId','images','files','advisoryId','notificationId'),JSON_THROW_ON_ERROR);
    $connection->commit(); echo $json;
    exit;
    } catch (Throwable $exception) {
        $connection->rollBack();
        foreach ($files as $file) {
            if (isset($marker) && preg_match('#^storage/report-images/' . preg_quote($marker,'#') . '-[0-2]\.jpg$#', $file)) {
                $target = dirname(__DIR__, 2) . '/' . $file; if (is_file($target)) unlink($target);
            }
        }
        throw $exception;
    }
}
$fixture = json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
if (!preg_match('/^admin-redesign-[a-f0-9]{12}$/',$fixture['marker'] ?? '')) throw new RuntimeException('Invalid test fixture');
if (($argv[1] ?? '') === 'cleanup') {
    $connection->beginTransaction();
    $s=$connection->prepare('DELETE FROM notifications WHERE related_advisory_id=? AND created_by=?');$s->execute([$fixture['advisoryId'],$fixture['adminId']]);
    $s=$connection->prepare('DELETE FROM weather_advisories WHERE id=? AND created_by=? AND title LIKE ?'); $title=$fixture['marker'].'%'; $s->execute([$fixture['advisoryId'],$fixture['adminId'],$title]);
    $s=$connection->prepare('DELETE FROM farmers WHERE id=? AND email=?');$s->execute([$fixture['farmerId'],$fixture['email']]);
    $s=$connection->prepare('DELETE FROM admins WHERE id=? AND username=?');$s->execute([$fixture['adminId'],$fixture['marker']]);
    test_admin_restore($connection, ['previous' => array_map('intval', $fixture['adminPrevious'] ?? [])]);
    $connection->commit();
    foreach($fixture['files'] as $file) {
        if (!preg_match('#^storage/report-images/' . preg_quote($fixture['marker'],'#') . '-[0-2]\.jpg$#',$file)) throw new RuntimeException('Unsafe fixture cleanup path');
        $path=dirname(__DIR__, 2).'/'.$file; if(is_file($path)) unlink($path);
    }
    echo "Removed only this run's controlled admin/farmer/advisory records and three synthetic images.\n"; exit;
}
throw new RuntimeException('Unknown fixture mode');
