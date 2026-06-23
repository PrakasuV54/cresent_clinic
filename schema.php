<?php
require_once __DIR__ . '/db.php';
$db = get_db();
$stmt = $db->query("PRAGMA table_info(direct_sales)");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['name'] . " - " . $row['type'] . "\n";
}
