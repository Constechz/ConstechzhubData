<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/email.php';

/**
 * Apply dynamic placeholders to the email template.
 */
function buildBroadcastEmailBody($template, array $recipient, $allow_html) {
    $replacements = [
        '{{name}}' => !empty($recipient['name']) ? $recipient['name'] : 'Valued User',
        '{{role}}' => !empty($recipient['role']) ? ucfirst($recipient['role']) : 'User',
        '{{site}}' => SITE_NAME,
    ];

    $content = strtr($template, $replacements);

    if ($allow_html) {
        $body_html = $content;
    } else {
        $body_html = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
    }

    $body_text = trim(strip_tags($content));

    $wrapped_html = '<div style="font-family: Arial, sans-serif; line-height:1.6; color:#2E294E;">'
        . $body_html
        . '<hr style="margin:24px 0;border:none;border-top:1px solid #F1E9DA;">'
        . '<p style="font-size:12px;color:#541388;">'
        . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8')
        . '</p></div>';

    return [$wrapped_html, $body_text];
}

/**
 * Process a batch of pending email broadcast jobs.
 */
function processEmailBroadcastQueue($limit = 50) {
    global $db;

    $limit = (int) $limit;
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 500) {
        $limit = 500;
    }

    $jobs = [];
    $job_stmt = $db->prepare("
        SELECT 
            j.id,
            j.broadcast_id,
            j.user_id,
            j.recipient_email,
            j.recipient_name,
            j.recipient_role,
            j.attempts,
            b.subject,
            b.message,
            b.allow_html,
            b.scheduled_at
        FROM email_broadcast_jobs j
        JOIN email_broadcasts b ON b.id = j.broadcast_id
        WHERE j.status = 'pending'
          AND (b.scheduled_at IS NULL OR b.scheduled_at <= NOW())
        ORDER BY j.id ASC
        LIMIT ?
    ");

    if (!$job_stmt) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'error' => 'Failed to prepare queue query.'];
    }

    $job_stmt->bind_param('i', $limit);
    $job_stmt->execute();
    $rs = $job_stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $jobs[] = $row;
    }

    if (empty($jobs)) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0];
    }

    $sent = 0;
    $failed = 0;
    $broadcast_ids = [];
    $broadcast_schedule = [];

    $update_stmt = $db->prepare("UPDATE email_broadcast_jobs SET status = ?, attempts = ?, last_error = ?, sent_at = ? WHERE id = ?");

    foreach ($jobs as $job) {
        $broadcast_ids[] = (int) $job['broadcast_id'];
        $broadcast_schedule[(int) $job['broadcast_id']] = $job['scheduled_at'];

        $recipient = [
            'name' => $job['recipient_name'] ?? '',
            'role' => $job['recipient_role'] ?? '',
        ];
        [$body_html, $body_text] = buildBroadcastEmailBody($job['message'], $recipient, (bool) $job['allow_html']);

        $success = sendEmail($job['recipient_email'], $job['subject'], $body_html, $body_text, 'admin_broadcast');
        $attempts = ((int) $job['attempts']) + 1;

        if ($success) {
            $status = 'sent';
            $sent++;
            $error = null;
            $sent_at = date('Y-m-d H:i:s');
        } else {
            $failed++;
            $status = $attempts >= 3 ? 'failed' : 'pending';
            $error = 'Send failed';
            $sent_at = null;
        }

        if ($update_stmt) {
            $update_stmt->bind_param(
                'sissi',
                $status,
                $attempts,
                $error,
                $sent_at,
                $job['id']
            );
            $update_stmt->execute();
        }
    }

    $broadcast_ids = array_values(array_unique($broadcast_ids));
    foreach ($broadcast_ids as $broadcast_id) {
        $counts_stmt = $db->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'sent') AS sent_count,
                SUM(status = 'failed') AS failed_count,
                SUM(status = 'pending') AS pending_count
            FROM email_broadcast_jobs
            WHERE broadcast_id = ?
        ");
        if (!$counts_stmt) {
            continue;
        }
        $counts_stmt->bind_param('i', $broadcast_id);
        $counts_stmt->execute();
        $counts = $counts_stmt->get_result()->fetch_assoc();

        $total = (int) ($counts['total'] ?? 0);
        $sent_count = (int) ($counts['sent_count'] ?? 0);
        $failed_count = (int) ($counts['failed_count'] ?? 0);
        $pending_count = (int) ($counts['pending_count'] ?? 0);

        $scheduled_at = $broadcast_schedule[$broadcast_id] ?? null;
        $status = 'pending';
        if (!empty($scheduled_at) && strtotime($scheduled_at) > time()) {
            $status = 'scheduled';
        } elseif ($pending_count === 0) {
            if ($sent_count === 0) {
                $status = 'failed';
            } elseif ($failed_count > 0) {
                $status = 'partial';
            } else {
                $status = 'completed';
            }
        } else {
            $status = 'processing';
        }

        $processed_at = $pending_count === 0 ? date('Y-m-d H:i:s') : null;

        $update_broadcast = $db->prepare("
            UPDATE email_broadcasts
            SET total_recipients = ?,
                successful_recipients = ?,
                failed_recipients = ?,
                status = ?,
                processed_at = COALESCE(?, processed_at)
            WHERE id = ?
        ");
        if ($update_broadcast) {
            $update_broadcast->bind_param(
                'iiissi',
                $total,
                $sent_count,
                $failed_count,
                $status,
                $processed_at,
                $broadcast_id
            );
            $update_broadcast->execute();
        }
    }

    return ['processed' => count($jobs), 'sent' => $sent, 'failed' => $failed];
}
