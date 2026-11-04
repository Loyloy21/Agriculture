<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$farmer = authenticated_farmer();
api_response(200, ['success' => true, 'farmer' => farmer_public_data($farmer)]);
