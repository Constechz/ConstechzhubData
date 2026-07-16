<?php
/**
 * SEO Helper Functions
 * Handles dynamic SEO meta tags and settings
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Get SEO setting value
 */
function getSeoSetting($setting_name, $default = '') {
    global $db;
    static $settingsCache = null;

    if ($settingsCache === null) {
        $settingsCache = [];

        try {
            if ($db && ($db instanceof Database)) {
                $result = $db->query("SELECT setting_name, setting_value FROM seo_settings");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $settingsCache[$row['setting_name']] = $row['setting_value'];
                    }
                    $result->free();
                }
            }
        } catch (Exception $e) {
            error_log("SEO Settings Cache Error: " . $e->getMessage());
        }
    }

    if (array_key_exists($setting_name, $settingsCache)) {
        return $settingsCache[$setting_name];
    }
    
    try {
        // Ensure we have a valid database connection
        if (!$db || !($db instanceof Database)) {
            error_log("SEO Get Setting Error: Database connection is not available");
            return $default;
        }
        
        $stmt = $db->prepare("SELECT setting_value FROM seo_settings WHERE setting_name = ?");
        if (!$stmt) {
            error_log("SEO Get Setting Error: Prepare statement failed");
            return $default;
        }
        
        $stmt->bind_param("s", $setting_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['setting_value'];
        }
        
        $stmt->close();
        return $default;
        
    } catch (Exception $e) {
        error_log("SEO Get Setting Exception: " . $e->getMessage());
        return $default;
    }
}

/**
 * Update SEO setting
 */
function updateSeoSetting($setting_name, $setting_value, $description = null) {
    global $db;
    
    try {
        // Validate input parameters
        if (empty($setting_name)) {
            error_log("SEO Update Error: Setting name cannot be empty");
            return false;
        }
        
        // Handle NULL and empty values properly
        if ($setting_value === null) {
            $setting_value = '';
        }
        
        // Ensure we have a database connection
        if (!$db || !($db instanceof Database)) {
            error_log("SEO Update Error: Database connection is not available");
            return false;
        }
        
        // Use upsert query with proper error handling
        if ($description !== null) {
            $stmt = $db->prepare("
                INSERT INTO seo_settings (setting_name, setting_value, description) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value), 
                    description = VALUES(description), 
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            if (!$stmt) {
                error_log("SEO Update Error: Prepare statement failed");
                return false;
            }
            
            $stmt->bind_param("sss", $setting_name, $setting_value, $description);
        } else {
            $stmt = $db->prepare("
                INSERT INTO seo_settings (setting_name, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value), 
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            if (!$stmt) {
                error_log("SEO Update Error: Prepare statement failed");
                return false;
            }
            
            $stmt->bind_param("ss", $setting_name, $setting_value);
        }
        
        $result = $stmt->execute();
        
        if (!$result) {
            error_log("SEO Update Error: Execute failed - " . $stmt->error);
            error_log("SEO Update Debug: setting_name=" . $setting_name . ", setting_value=" . $setting_value);
            return false;
        }
        
        $stmt->close();
        return true;
        
    } catch (Exception $e) {
        error_log("SEO Update Exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Ignore placeholder hostnames when resolving public URLs.
 */
function isSeoPlaceholderUrl($url) {
    if (!is_string($url) || trim($url) === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    return in_array($host, ['yourdomain.com', 'www.yourdomain.com', 'example.com', 'www.example.com'], true);
}

/**
 * Resolve the most reliable public base URL for the current request.
 */
function getSeoBaseUrl($preferred = '') {
    $candidates = [];

    if (function_exists('dbh_detect_site_url')) {
        $detected = dbh_detect_site_url();
        if (is_string($detected) && trim($detected) !== '') {
            $candidates[] = trim($detected);
        }
    }

    if (is_string($preferred) && trim($preferred) !== '') {
        $candidates[] = trim($preferred);
    }

    if (defined('SITE_URL') && is_string(SITE_URL) && trim(SITE_URL) !== '') {
        $candidates[] = trim((string) SITE_URL);
    }

    $settingSiteUrl = trim((string) getSeoSetting('site_url', ''));
    if ($settingSiteUrl !== '') {
        $candidates[] = $settingSiteUrl;
    }

    $placeholderCandidate = '';
    foreach ($candidates as $candidate) {
        if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
            continue;
        }

        if (!isSeoPlaceholderUrl($candidate)) {
            return rtrim($candidate, '/');
        }

        if ($placeholderCandidate === '') {
            $placeholderCandidate = rtrim($candidate, '/');
        }
    }

    return $placeholderCandidate;
}

/**
 * Resolve a public asset URL from a relative path with a safe local fallback.
 */
function getSeoAssetUrl($asset_path, $base_url = '') {
    $normalized_path = ltrim(str_replace('\\', '/', (string) $asset_path), '/');
    $project_root = realpath(__DIR__ . '/..');

    if ($normalized_path === '') {
        return '/';
    }

    if ($project_root) {
        $local_file = $project_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_path);
        if (!is_file($local_file)) {
            return '/' . $normalized_path;
        }
    }

    $resolved_base_url = getSeoBaseUrl($base_url);
    if ($resolved_base_url !== '') {
        return $resolved_base_url . '/' . $normalized_path;
    }

    return '/' . $normalized_path;
}

/**
 * Check whether a relative asset path exists inside the project root.
 */
function seoLocalAssetExists($asset_path) {
    $normalized_path = ltrim(str_replace('\\', '/', (string) $asset_path), '/');
    $project_root = realpath(__DIR__ . '/..');

    if ($normalized_path === '' || !$project_root) {
        return false;
    }

    $local_file = $project_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_path);
    return is_file($local_file);
}

/**
 * Resolve favicon URL from settings with a safe local fallback.
 */
function getSeoFaviconUrl($site_url = '') {
    $favicon_setting = trim((string) getSeoSetting('favicon_url', ''));
    $fallback_relative = 'assets/images/icon-192.png';

    if ($favicon_setting === '') {
        $favicon_setting = $fallback_relative;
    }

    if (!filter_var($favicon_setting, FILTER_VALIDATE_URL)) {
        $normalized_path = ltrim(str_replace('\\', '/', $favicon_setting), '/');
        if ($normalized_path === '' || !seoLocalAssetExists($normalized_path)) {
            $normalized_path = $fallback_relative;
        }

        return getSeoAssetUrl($normalized_path, $site_url);
    }

    if (isSeoPlaceholderUrl($favicon_setting)) {
        return getSeoAssetUrl($fallback_relative, $site_url);
    }

    return $favicon_setting;
}

/**
 * Generate SEO meta tags
 */
function generateSeoMeta($page_title = '', $page_description = '', $page_image = '', $page_url = '') {
    $site_name = getSeoSetting('site_name', 'Constechzhub');
    $site_description = getSeoSetting('site_description', 'Your trusted partner for affordable data bundles');
    $site_url = getSeoBaseUrl(getSeoSetting('site_url', ''));
    $default_image = getSeoSetting('seo_image', '/assets/images/seo-default.jpg');
    $twitter_handle = getSeoSetting('twitter_handle', '@databundlehub');
    $facebook_app_id = getSeoSetting('facebook_app_id', '');
    
    // Use page-specific values or fallback to defaults
    $title = $page_title ? $page_title . ' - ' . $site_name : $site_name;
    $description = $page_description ?: $site_description;
    $image = $page_image ?: $default_image;
    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
    $url = $page_url ?: ($site_url !== '' ? $site_url . $request_uri : $request_uri);
    $favicon_url = getSeoFaviconUrl($site_url);

    // Make image URL absolute
    if ($image && !filter_var($image, FILTER_VALIDATE_URL)) {
        $image = getSeoAssetUrl($image, $site_url);
    }
    
    $meta_tags = [
        // Basic meta tags
        '<meta charset="UTF-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
        '<title>' . htmlspecialchars($title) . '</title>',
        '<meta name="description" content="' . htmlspecialchars($description) . '">',
        '<meta name="keywords" content="' . htmlspecialchars(getSeoSetting('site_keywords', '')) . '">',
        '<link rel="canonical" href="' . htmlspecialchars($url) . '">',
        
        // Open Graph tags
        '<meta property="og:title" content="' . htmlspecialchars($title) . '">',
        '<meta property="og:description" content="' . htmlspecialchars($description) . '">',
        '<meta property="og:image" content="' . htmlspecialchars($image) . '">',
        '<meta property="og:url" content="' . htmlspecialchars($url) . '">',
        '<meta property="og:type" content="website">',
        '<meta property="og:site_name" content="' . htmlspecialchars($site_name) . '">',
        
        // Twitter Card tags
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . htmlspecialchars($title) . '">',
        '<meta name="twitter:description" content="' . htmlspecialchars($description) . '">',
        '<meta name="twitter:image" content="' . htmlspecialchars($image) . '">',
        '<meta name="twitter:site" content="' . htmlspecialchars($twitter_handle) . '">',
        
        // Favicon
        '<link rel="icon" type="image/png" href="' . htmlspecialchars($favicon_url) . '">',
        '<link rel="shortcut icon" href="' . htmlspecialchars($favicon_url) . '">',
        '<link rel="apple-touch-icon" href="' . htmlspecialchars($favicon_url) . '">'
    ];
    
    // Add Facebook App ID if available
    if ($facebook_app_id) {
        $meta_tags[] = '<meta property="fb:app_id" content="' . htmlspecialchars($facebook_app_id) . '">';
    }
    
    // Add Google Analytics if available
    $ga_id = getSeoSetting('google_analytics_id', '');
    if ($ga_id) {
        $meta_tags[] = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . htmlspecialchars($ga_id) . '"></script>';
        $meta_tags[] = '<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag("js", new Date()); gtag("config", "' . htmlspecialchars($ga_id) . '");</script>';
    }
    
    // Add Google Site Verification if available
    $site_verification = getSeoSetting('google_site_verification', '');
    if ($site_verification) {
        $meta_tags[] = '<meta name="google-site-verification" content="' . htmlspecialchars($site_verification) . '">';
    }
    
    return implode("\n    ", $meta_tags);
}

/**
 * Get all SEO settings for admin interface
 */
function getAllSeoSettings() {
    global $db;
    
    try {
        // Ensure we have a valid database connection
        if (!$db || !($db instanceof Database)) {
            error_log("SEO Get All Settings Error: Database connection is not available");
            return [];
        }
        
        $result = $db->query("SELECT * FROM seo_settings ORDER BY setting_name");
        if (!$result) {
            error_log("SEO Get All Settings Error: Query failed");
            return [];
        }
        
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_name']] = $row;
        }
        
        return $settings;
        
    } catch (Exception $e) {
        error_log("SEO Get All Settings Exception: " . $e->getMessage());
        return [];
    }
}

/**
 * Generate JSON-LD structured data
 */
function generateJsonLd($type = 'Organization') {
    $site_name = getSeoSetting('site_name', 'Constechzhub');
    $site_description = getSeoSetting('site_description', '');
    $site_url = getSeoSetting('site_url', 'https://yourdomain.com');
    $site_image = getSeoSetting('seo_image', '/assets/images/seo-default.jpg');
    
    if (!filter_var($site_image, FILTER_VALIDATE_URL)) {
        $site_image = rtrim($site_url, '/') . '/' . ltrim($site_image, '/');
    }
    
    $json_ld = [
        "@context" => "https://schema.org",
        "@type" => $type,
        "name" => $site_name,
        "description" => $site_description,
        "url" => $site_url,
        "image" => $site_image,
        "sameAs" => []
    ];
    
    return '<script type="application/ld+json">' . json_encode($json_ld, JSON_UNESCAPED_SLASHES) . '</script>';
}
?>
