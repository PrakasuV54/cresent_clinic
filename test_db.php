<?php
try {
    $conn = new PDO("mysql:host=127.0.0.1;dbname=u526658771_crescent", "u526658771_nnp", "Namaraja@4");
    echo "Connected!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
