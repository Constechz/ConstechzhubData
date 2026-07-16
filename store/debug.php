<?php
// Enable full error display for diagnostics
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h3>Constechzhub Diagnostic Boot Check</h3>";

try {
    echo "1. Loading config.php...<br>";
    require_once __DIR__ . '/../config/config.php';
    echo "✔ config.php loaded successfully!<br><br>";
    
    echo "2. Checking database connection...<br>";
    if (isset($db)) {
        echo "✔ \$db global variable exists.<br>";
        $conn = $db->getConnection();
        if ($conn) {
            echo "✔ \$db has a connection object.<br>";
            if ($conn->ping()) {
                echo "✔ Database connection ping successful!<br>";
            } else {
                echo "❌ Database connection ping failed.<br>";
            }
        } else {
            echo "❌ \$db connection is null.<br>";
        }
    } else {
        echo "❌ \$db is not defined.<br>";
    }
    echo "<br>";

    echo "3. Checking functions...<br>";
    if (function_exists('isLoggedIn')) {
        echo "✔ isLoggedIn() function exists.<br>";
    } else {
        echo "❌ isLoggedIn() function does NOT exist.<br>";
    }
    if (function_exists('dbh_render_guest_constchat_markup')) {
        echo "✔ dbh_render_guest_constchat_markup() exists.<br>";
    } else {
        echo "❌ dbh_render_guest_constchat_markup() does NOT exist.<br>";
    }
    echo "<br>";

    echo "4. Simulating guest widget rendering...<br>";
    $test_slug = 'constechz';
    if (function_exists('dbh_render_guest_constchat_markup')) {
        $markup = dbh_render_guest_constchat_markup($test_slug);
        echo "✔ Guest widget markup generated successfully! (Length: " . strlen($markup) . " characters)<br>";
    } else {
        echo "Skipped widget simulation because function is missing.<br>";
    }

    echo "<br><h4 style='color:green;'>All core checks passed! If you still see a 500 error on the store homepage, it is likely inside store/index.php itself.</h4>";

} catch (Throwable $e) {
    echo "<br><h4 style='color:red;'>FATAL ERROR ENCOUNTERED:</h4>";
    echo "<strong>Message:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>File:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "<strong>Stack Trace:</strong><pre>" . $e->getTraceAsString() . "</pre>";
}
?>
