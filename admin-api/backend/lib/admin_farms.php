<?php
declare(strict_types=1);

/** Admin-only callers attach every linked farm, including explicitly labelled archives. */
function admin_linked_farms(PDO $connection, int $farmerId): array
{
    $statement = $connection->prepare('SELECT id, farm_name, farm_type, agricultural_category, land_area, main_product, farm_address, status, is_primary, location_review_status, latitude, longitude FROM farm_profiles WHERE farmer_id = ? ORDER BY is_primary DESC, status ASC, id ASC');
    $statement->execute([$farmerId]);
    return $statement->fetchAll();
}
