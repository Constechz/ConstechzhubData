<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$app_id = (int)($_GET['id'] ?? 0);

if (!$app_id) {
    echo json_encode(['success' => false, 'error' => 'Application ID required']);
    exit();
}

// Get application details
$stmt = $db->prepare("
    SELECT aa.*, u.full_name as agent_name, u.email as agent_email, u.phone as agent_phone,
           reviewer.full_name as reviewed_by_name
    FROM agent_api_applications aa 
    JOIN users u ON aa.agent_id = u.id 
    LEFT JOIN users reviewer ON aa.reviewed_by = reviewer.id 
    WHERE aa.id = ?
");
$stmt->bind_param('i', $app_id);
$stmt->execute();
$application = $stmt->get_result()->fetch_assoc();

if (!$application) {
    echo json_encode(['success' => false, 'error' => 'Application not found']);
    exit();
}

// Generate HTML content
$html = '
<div class="application-details">
    <div class="detail-section">
        <h4>Agent Information</h4>
        <table class="detail-table">
            <tr>
                <td><strong>Name:</strong></td>
                <td>' . htmlspecialchars($application['agent_name']) . '</td>
            </tr>
            <tr>
                <td><strong>Email:</strong></td>
                <td>' . htmlspecialchars($application['agent_email']) . '</td>
            </tr>
            <tr>
                <td><strong>Phone:</strong></td>
                <td>' . htmlspecialchars($application['agent_phone'] ?? 'Not provided') . '</td>
            </tr>
        </table>
    </div>
    
    <div class="detail-section">
        <h4>Business Information</h4>
        <table class="detail-table">
            <tr>
                <td><strong>Business Name:</strong></td>
                <td>' . htmlspecialchars($application['business_name']) . '</td>
            </tr>
            <tr>
                <td><strong>Website:</strong></td>
                <td>' . ($application['website_url'] ? '<a href="' . htmlspecialchars($application['website_url']) . '" target="_blank">' . htmlspecialchars($application['website_url']) . '</a>' : 'Not provided') . '</td>
            </tr>
            <tr>
                <td><strong>Expected Volume:</strong></td>
                <td><span class="badge badge-' . $application['expected_volume'] . '">' . ucfirst($application['expected_volume']) . '</span></td>
            </tr>
        </table>
    </div>
    
    <div class="detail-section">
        <h4>Business Description</h4>
        <div class="description-box">
            ' . ($application['business_description'] ? nl2br(htmlspecialchars($application['business_description'])) : '<em>No description provided</em>') . '
        </div>
    </div>
    
    <div class="detail-section">
        <h4>Use Case</h4>
        <div class="description-box">
            ' . nl2br(htmlspecialchars($application['use_case'])) . '
        </div>
    </div>
    
    <div class="detail-section">
        <h4>Application Status</h4>
        <table class="detail-table">
            <tr>
                <td><strong>Status:</strong></td>
                <td><span class="status-badge status-' . $application['status'] . '">' . ucfirst($application['status']) . '</span></td>
            </tr>
            <tr>
                <td><strong>Applied Date:</strong></td>
                <td>' . date('F j, Y \a\t g:i A', strtotime($application['applied_at'])) . '</td>
            </tr>';

if ($application['reviewed_at']) {
    $html .= '
            <tr>
                <td><strong>Reviewed Date:</strong></td>
                <td>' . date('F j, Y \a\t g:i A', strtotime($application['reviewed_at'])) . '</td>
            </tr>
            <tr>
                <td><strong>Reviewed By:</strong></td>
                <td>' . htmlspecialchars($application['reviewed_by_name']) . '</td>
            </tr>';
}

$html .= '
        </table>
    </div>';

if ($application['admin_notes']) {
    $html .= '
    <div class="detail-section">
        <h4>Admin Notes</h4>
        <div class="description-box">
            ' . nl2br(htmlspecialchars($application['admin_notes'])) . '
        </div>
    </div>';
}

$html .= '
</div>

<style>
.application-details {
    max-height: 70vh;
    overflow-y: auto;
}

.detail-section {
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #F1E9DA;
}

.detail-section:last-child {
    border-bottom: none;
}

.detail-section h4 {
    color: #2E294E;
    margin-bottom: 0.75rem;
    font-size: 1.1rem;
}

.detail-table {
    width: 100%;
    border-collapse: collapse;
}

.detail-table td {
    padding: 0.5rem 0;
    vertical-align: top;
}

.detail-table td:first-child {
    width: 30%;
    color: #541388;
}

.description-box {
    background: #F1E9DA;
    border: 1px solid #F1E9DA;
    border-radius: 4px;
    padding: 1rem;
    line-height: 1.6;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.25rem;
    text-transform: uppercase;
}

.badge-low { background: #F1E9DA; color: #2E294E; }
.badge-medium { background: #F1E9DA; color: #2E294E; }
.badge-high { background: #F1E9DA; color: #2E294E; }

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 500;
}

.status-pending { background: #F1E9DA; color: #2E294E; }
.status-approved { background: #F1E9DA; color: #2E294E; }
.status-rejected { background: #F1E9DA; color: #2E294E; }
.status-suspended { background: #F1E9DA; color: #541388; }
</style>';

echo json_encode(['success' => true, 'html' => $html]);
?>
