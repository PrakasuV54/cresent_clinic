<?php
require 'db.php';
$conn = get_db();
try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 1. Sync from agency_items to generic_mappings
    $conn->exec("
        INSERT INTO generic_mappings (brand_name, batch_number, generic_name, agency_name, stock, mrp, row_location, col_location, purchase_rate, selling_rate, category, pack_size, expiry_date, min_stock)
        SELECT 
            ai.item_name, 
            ai.batch_number, 
            ai.generic_name, 
            (SELECT name FROM agency_suppliers WHERE id = ai.supplier_id LIMIT 1),
            ai.stock, 
            ai.mrp, 
            ai.row_location, 
            ai.col_location,
            ai.purchase_price,
            ai.selling_price,
            ai.category,
            ai.unit,
            ai.expiry_date,
            ai.min_stock
        FROM agency_items ai
        WHERE ai.generic_name IS NOT NULL AND TRIM(ai.generic_name) != ''
        ON DUPLICATE KEY UPDATE
            generic_name = VALUES(generic_name),
            agency_name = VALUES(agency_name),
            stock = VALUES(stock),
            mrp = VALUES(mrp),
            row_location = VALUES(row_location),
            col_location = VALUES(col_location),
            purchase_rate = VALUES(purchase_rate),
            selling_rate = VALUES(selling_rate),
            category = VALUES(category),
            pack_size = VALUES(pack_size),
            expiry_date = VALUES(expiry_date),
            min_stock = VALUES(min_stock)
    ");
    echo "Success!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
