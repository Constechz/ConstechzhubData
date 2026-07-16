<?php
require_once '../config/config.php';

if (!isLoggedIn()) {
    jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$payload = $method === 'POST' ? (json_decode($raw, true) ?: []) : $_GET;
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($method === 'POST') {
    if (!$csrfHeader && isset($payload['csrf_token'])) { $csrfHeader = $payload['csrf_token']; }
    if (!validateCSRF($csrfHeader)) { jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419); }
}
$action = $payload['action'] ?? '';
$current = getCurrentUser();

function ensureTables() { /* migrations should create them */ }

try {
    if ($action === 'create_ticket' && $method === 'POST') {
        $subject = trim($payload['subject'] ?? '');
        $category = $payload['category'] ?? 'other';
        $priority = $payload['priority'] ?? 'medium';
        $message = trim($payload['message'] ?? '');
        if ($subject === '' || $message === '') {
            jsonResponse(['status' => 'error', 'message' => 'Subject and message are required']);
        }
        $assigned_to_role = 'admin';
        $assigned_to_user_id = null;
        $agent_id = null;
        if ($current['role'] === 'customer') {
            $linked_agent = getUserAgentId($current['id']);
            if ($linked_agent) {
                $assigned_to_role = 'agent';
                $agent_id = (int)$linked_agent;
            }
        } else if ($current['role'] === 'agent') {
            $assigned_to_role = 'admin';
        } else if ($current['role'] === 'admin') {
            $assigned_to_role = 'admin';
        }
        $stmt = $db->prepare("INSERT INTO support_tickets (user_id, subject, category, status, priority, assigned_to_role, assigned_to_user_id, agent_id) VALUES (?, ?, ?, 'open', ?, ?, ?, ?)");
        $stmt->bind_param('issssii', $current['id'], $subject, $category, $priority, $assigned_to_role, $assigned_to_user_id, $agent_id);
        $stmt->execute();
        $ticket_id = $db->getConnection()->insert_id;
        $stmt = $db->prepare("INSERT INTO support_messages (ticket_id, sender_user_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $ticket_id, $current['id'], $message);
        $stmt->execute();
        logActivity($current['id'], 'support_create_ticket', json_encode(['ticket_id' => $ticket_id]));
        jsonResponse(['status' => 'success', 'ticket_id' => $ticket_id]);
    } elseif ($action === 'add_message' && $method === 'POST') {
        $ticket_id = (int)($payload['ticket_id'] ?? 0);
        $message = trim($payload['message'] ?? '');
        if ($ticket_id <= 0 || $message === '') jsonResponse(['status' => 'error', 'message' => 'Invalid input']);
        // Check permission: user is ticket creator or assigned handler
        $stmt = $db->prepare("SELECT user_id, assigned_to_role, agent_id FROM support_tickets WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        if (!$t) jsonResponse(['status' => 'error', 'message' => 'Ticket not found'], 404);
        $allowed = false;
        if ($t['user_id'] == $current['id']) $allowed = true;
        if ($current['role'] === 'admin' && $t['assigned_to_role'] === 'admin') $allowed = true;
        if ($current['role'] === 'agent' && $t['assigned_to_role'] === 'agent' && (int)$t['agent_id'] === (int)$current['id']) $allowed = true;
        if (!$allowed) jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        $stmt = $db->prepare("INSERT INTO support_messages (ticket_id, sender_user_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $ticket_id, $current['id'], $message);
        $stmt->execute();
        jsonResponse(['status' => 'success']);
    } elseif ($action === 'list_tickets') {
        // List visible tickets for current user
        $type = $payload['type'] ?? '';
        if ($current['role'] === 'customer') {
            $stmt = $db->prepare("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC");
            $stmt->bind_param('i', $current['id']);
        } elseif ($current['role'] === 'agent') {
            if ($type === 'customer') {
                // Customer tickets assigned to this agent
                $stmt = $db->prepare("SELECT * FROM support_tickets WHERE assigned_to_role = 'agent' AND agent_id = ? ORDER BY updated_at DESC");
                $stmt->bind_param('i', $current['id']);
            } elseif ($type === 'my') {
                // Agent's own tickets to admin
                $stmt = $db->prepare("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC");
                $stmt->bind_param('i', $current['id']);
            } else {
                // Default: all tickets (customer + own)
                $stmt = $db->prepare("SELECT * FROM support_tickets WHERE (assigned_to_role = 'agent' AND agent_id = ?) OR user_id = ? ORDER BY updated_at DESC");
                $stmt->bind_param('ii', $current['id'], $current['id']);
            }
        } else { // admin
            $stmt = $db->prepare("SELECT * FROM support_tickets WHERE assigned_to_role = 'admin' ORDER BY updated_at DESC");
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }
        jsonResponse(['status' => 'success', 'tickets' => $rows]);
    } elseif ($action === 'list_messages') {
        $ticket_id = (int)($payload['ticket_id'] ?? 0);
        if ($ticket_id <= 0) jsonResponse(['status' => 'error', 'message' => 'Invalid ticket']);
        // Permission as above
        $stmt = $db->prepare("SELECT user_id, assigned_to_role, agent_id FROM support_tickets WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        if (!$t) jsonResponse(['status' => 'error', 'message' => 'Ticket not found'], 404);
        $allowed = false;
        if ($t['user_id'] == $current['id']) $allowed = true;
        if ($current['role'] === 'admin' && $t['assigned_to_role'] === 'admin') $allowed = true;
        if ($current['role'] === 'agent' && $t['assigned_to_role'] === 'agent' && (int)$t['agent_id'] === (int)$current['id']) $allowed = true;
        if (!$allowed) jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        $stmt = $db->prepare("SELECT sm.*, u.full_name AS sender_name FROM support_messages sm JOIN users u ON sm.sender_user_id = u.id WHERE sm.ticket_id = ? ORDER BY sm.created_at ASC");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }
        jsonResponse(['status' => 'success', 'messages' => $rows]);
    } elseif ($action === 'update_status' && $method === 'POST') {
        $ticket_id = (int)($payload['ticket_id'] ?? 0);
        $status = $payload['status'] ?? '';
        if (!in_array($status, ['open','in_progress','resolved','closed'])) jsonResponse(['status' => 'error', 'message' => 'Invalid status']);
        $stmt = $db->prepare("SELECT assigned_to_role, agent_id FROM support_tickets WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $ticket_id);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        if (!$t) jsonResponse(['status' => 'error', 'message' => 'Ticket not found'], 404);
        $allowed = ($current['role'] === 'admin' && $t['assigned_to_role'] === 'admin') || ($current['role'] === 'agent' && $t['assigned_to_role'] === 'agent' && (int)$t['agent_id'] === (int)$current['id']);
        if (!$allowed) jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        $stmt = $db->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $ticket_id);
        $stmt->execute();
        jsonResponse(['status' => 'success']);
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid action or method'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
}
