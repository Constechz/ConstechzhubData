<?php
require_once 'config/config.php';
header('Content-Type: text/plain');

echo "Checking Database connection...\n";
if (isset($db)) {
    echo "DB Object exists.\n";
    $conn = $db->getConnection();
    if ($conn) {
        echo "DB Connection success.\n";
        
        // Check tables
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        if ($result) {
            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }
            echo "Tables in database:\n" . implode(", ", $tables) . "\n\n";
        } else {
            echo "Failed to query tables: " . $conn->error . "\n";
        }
        
        // Describe afa_registrations if exists
        if (in_array('afa_registrations', $tables)) {
            echo "DESCRIBE afa_registrations:\n";
            $res = $conn->query("DESCRIBE afa_registrations");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    print_r($row);
                }
            } else {
                echo "Failed to DESCRIBE afa_registrations: " . $conn->error . "\n";
            }
        } else {
            echo "Table 'afa_registrations' does NOT exist!\n";
        }

        // Describe afa_registration_settings if exists
        if (in_array('afa_registration_settings', $tables)) {
            echo "\nDESCRIBE afa_registration_settings:\n";
            $res = $conn->query("DESCRIBE afa_registration_settings");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    print_r($row);
                }
            } else {
                echo "Failed to DESCRIBE afa_registration_settings: " . $conn->error . "\n";
            }
        } else {
            echo "Table 'afa_registration_settings' does NOT exist!\n";
        }
    } else {
        echo "DB Connection is null.\n";
    }
} else {
    echo "DB Object is NOT set.\n";
}
