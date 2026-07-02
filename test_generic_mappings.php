<?php
require 'api/db.php';
$conn = get_db();
$stmt = $conn->query("SELECT brand_name, batch_number, generic_name FROM generic_mappings LIMIT 10");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
