<?php
// Clean output buffer to prevent any unwanted output
if (ob_get_level()) {
    ob_clean();
}
ob_start();

require_once 'config/config.php';

// Get PWA settings from database
$stmt = $db->prepare("SELECT * FROM pwa_settings WHERE id = 1 LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$pwa = $result->fetch_assoc();

if (!$pwa) {
    $pwa = [
        'app_name' => 'Constechzhub',
        'app_short_name' => 'CTZH',
        'app_description' => 'Affordable data bundles for all networks',
        'theme_color' => '#541388',
        'background_color' => '#F1E9DA',
        'start_url' => '/',
        'display_mode' => 'standalone',
        'orientation' => 'portrait'
    ];
}

// Build icons array with multiple sizes for better compatibility
$icons = [];

// Add custom icons if uploaded
if (!empty($pwa['icon_192'])) {
    $icons[] = [
        "src" => "uploads/pwa/" . $pwa['icon_192'],
        "sizes" => "192x192",
        "type" => "image/png",
        "purpose" => "any"
    ];
    $icons[] = [
        "src" => "uploads/pwa/" . $pwa['icon_192'],
        "sizes" => "192x192",
        "type" => "image/png",
        "purpose" => "maskable"
    ];
}

if (!empty($pwa['icon_512'])) {
    $icons[] = [
        "src" => "uploads/pwa/" . $pwa['icon_512'],
        "sizes" => "512x512",
        "type" => "image/png",
        "purpose" => "any"
    ];
    $icons[] = [
        "src" => "uploads/pwa/" . $pwa['icon_512'],
        "sizes" => "512x512",
        "type" => "image/png",
        "purpose" => "maskable"
    ];
}

// Default icons with multiple sizes if none uploaded
if (empty($icons)) {
    // Multiple sizes for better device support
    $sizes = [
        ["size" => "72x72", "file" => "icon-72.png"],
        ["size" => "96x96", "file" => "icon-96.png"],
        ["size" => "128x128", "file" => "icon-128.png"],
        ["size" => "144x144", "file" => "icon-144.png"],
        ["size" => "152x152", "file" => "icon-152.png"],
        ["size" => "192x192", "file" => "icon-192.png"],
        ["size" => "384x384", "file" => "icon-384.png"],
        ["size" => "512x512", "file" => "icon-512.png"]
    ];
    
    foreach ($sizes as $iconSize) {
        // Check if file exists, if not use 192 or 512 as fallback
        $iconFile = "assets/images/" . $iconSize['file'];
        if (!file_exists($iconFile)) {
            $iconFile = file_exists("assets/images/icon-192.png") ? "assets/images/icon-192.png" : "assets/images/icon-512.png";
        }
        
        $icons[] = [
            "src" => $iconFile,
            "sizes" => $iconSize['size'],
            "type" => "image/png",
            "purpose" => "any"
        ];
        
        // Add maskable version for Android
        $icons[] = [
            "src" => $iconFile,
            "sizes" => $iconSize['size'],
            "type" => "image/png",
            "purpose" => "maskable"
        ];
    }
}

// Enhanced manifest for cross-platform compatibility
$manifest = [
    "name" => $pwa['app_name'],
    "short_name" => $pwa['app_short_name'],
    "description" => $pwa['app_description'],
    "start_url" => $pwa['start_url'],
    "display" => $pwa['display_mode'],
    "orientation" => $pwa['orientation'],
    "theme_color" => $pwa['theme_color'],
    "background_color" => $pwa['background_color'],
    "icons" => $icons,
    "categories" => ["business", "finance", "utilities"],
    "lang" => "en",
    "dir" => "ltr",
    "scope" => "/",
    "prefer_related_applications" => false,
    // Enhanced Android support
    "display_override" => ["window-controls-overlay", "minimal-ui"],
    "edge_side_panel" => [
        "preferred_width" => 412
    ],
    // iOS specific enhancements
    "related_applications" => [],
    "shortcuts" => [
        [
            "name" => "Buy Data",
            "short_name" => "Buy Data",
            "description" => "Quick access to purchase data bundles",
            "url" => "/customer/buy-data.php",
            "icons" => [
                [
                    "src" => "assets/images/icon-192.png",
                    "sizes" => "192x192",
                    "type" => "image/png"
                ]
            ]
        ],
        [
            "name" => "Dashboard",
            "short_name" => "Dashboard",
            "description" => "Access your dashboard",
            "url" => "/",
            "icons" => [
                [
                    "src" => "assets/images/icon-192.png",
                    "sizes" => "192x192",
                    "type" => "image/png"
                ]
            ]
        ]
    ],
    // Enhanced feature declarations
    "features" => [
        "Cross Origin Embedder Policy credentialless",
        "Cross Origin Opener Policy same-origin"
    ],
    // Protocol handlers for better integration
    "protocol_handlers" => [
        [
            "protocol" => "web+databundle",
            "url" => "/customer/buy-data.php?package=%s"
        ]
    ]
];

// Set headers and output JSON
ob_end_clean();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Ensure clean output
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>

