<?php
require_once '../config/config.php';
require_once '../includes/analytics.php';

requireRole('admin');

$pageTitle = 'Profit Monitor';
$current_user = getCurrentUser();
$rows = [];
$summary = [
    'total_earned' => 0.0,
    'available_profit' => 0.0,
    'pending_withdrawals' => 0.0,
    'paid_withdrawals' => 0.0,
    'data_profit' => 0.0,
    'active_agents' => 0,
];

if (function_exists('ensureProfitWithdrawalTables')) {
    ensureProfitWithdrawalTables();
}

try {
    $bundleOrdersTableExists = function_exists('dbh_table_exists') && dbh_table_exists('bundle_orders');
    $withdrawalTableExists = function_exists('dbh_table_exists') && dbh_table_exists('profit_withdrawals');
    $bundleActivityExpression = ($bundleOrdersTableExists && function_exists('dbh_table_has_column') && dbh_table_has_column('bundle_orders', 'delivered_at'))
        ? 'COALESCE(bo.delivered_at, bo.updated_at, bo.created_at)'
        : 'COALESCE(bo.updated_at, bo.created_at)';

    $dataProfitSql = $bundleOrdersTableExists ? "
        SELECT
            bo.agent_id,
            COALESCE(SUM(GREATEST(0, COALESCE(bo.amount, 0) - COALESCE(bo.agent_cost, 0))), 0) AS data_profit,
            COALESCE(SUM(COALESCE(bo.amount, 0)), 0) AS data_revenue,
            COALESCE(SUM(COALESCE(bo.agent_cost, 0)), 0) AS data_cost,
            COUNT(*) AS data_orders,
            MAX({$bundleActivityExpression}) AS last_data_activity
        FROM bundle_orders bo
        WHERE bo.agent_id IS NOT NULL
          AND bo.agent_id > 0
          AND (bo.user_id IS NULL OR bo.user_id <> bo.agent_id)
          AND LOWER(COALESCE(bo.status, '')) IN ('success', 'delivered', 'completed')
          AND COALESCE(bo.agent_cost, 0) > 0
          AND COALESCE(bo.amount, 0) > COALESCE(bo.agent_cost, 0)
        GROUP BY bo.agent_id
    " : "
        SELECT
            0 AS agent_id,
            0 AS data_profit,
            0 AS data_revenue,
            0 AS data_cost,
            0 AS data_orders,
            NULL AS last_data_activity
        WHERE 1 = 0
    ";

    $withdrawalSumColumn = 'pw.amount';
    if ($withdrawalTableExists && function_exists('dbh_table_has_column') && dbh_table_has_column('profit_withdrawals', 'total_debit')) {
        $withdrawalSumColumn = 'CASE WHEN pw.total_debit IS NULL OR pw.total_debit <= 0 THEN pw.amount WHEN pw.total_debit > pw.amount THEN pw.amount ELSE pw.total_debit END';
    }

    $withdrawalSql = $withdrawalTableExists ? "
        SELECT
            pw.agent_id,
            COALESCE(SUM(CASE WHEN pw.status IN ('pending', 'approved', 'processing') THEN {$withdrawalSumColumn} ELSE 0 END), 0) AS pending_withdrawals,
            COALESCE(SUM(CASE WHEN pw.status = 'paid' THEN {$withdrawalSumColumn} ELSE 0 END), 0) AS paid_withdrawals,
            MAX(COALESCE(pw.processed_at, pw.created_at)) AS last_withdrawal_activity
        FROM profit_withdrawals pw
        GROUP BY pw.agent_id
    " : "
        SELECT
            0 AS agent_id,
            0 AS pending_withdrawals,
            0 AS paid_withdrawals,
            NULL AS last_withdrawal_activity
        WHERE 1 = 0
    ";

    $sql = "
        SELECT
            u.id,
            u.full_name,
            u.email,
            COALESCE(dp.data_profit, 0) AS data_profit,
            COALESCE(dp.data_revenue, 0) AS data_revenue,
            COALESCE(dp.data_cost, 0) AS data_cost,
            COALESCE(dp.data_orders, 0) AS data_orders,
            COALESCE(w.pending_withdrawals, 0) AS pending_withdrawals,
            COALESCE(w.paid_withdrawals, 0) AS paid_withdrawals,
            COALESCE(dp.data_profit, 0) AS total_earned,
            GREATEST(
                0,
                COALESCE(dp.data_profit, 0)
                - COALESCE(w.pending_withdrawals, 0)
                - COALESCE(w.paid_withdrawals, 0)
            ) AS available_profit,
            CASE
                WHEN dp.last_data_activity IS NULL
                    AND w.last_withdrawal_activity IS NULL THEN NULL
                ELSE GREATEST(
                    COALESCE(dp.last_data_activity, '1970-01-01 00:00:00'),
                    COALESCE(w.last_withdrawal_activity, '1970-01-01 00:00:00')
                )
            END AS last_activity
        FROM users u
        LEFT JOIN ({$dataProfitSql}) dp ON dp.agent_id = u.id
        LEFT JOIN ({$withdrawalSql}) w ON w.agent_id = u.id
        WHERE u.role = 'agent'
          AND (
                COALESCE(dp.data_profit, 0) <> 0
                OR COALESCE(w.pending_withdrawals, 0) <> 0
                OR COALESCE(w.paid_withdrawals, 0) <> 0
                OR COALESCE(dp.data_orders, 0) <> 0
          )
        ORDER BY total_earned DESC, last_activity DESC, u.full_name ASC
    ";

    $result = $db->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'full_name' => (string) ($row['full_name'] ?? 'Agent'),
                'email' => (string) ($row['email'] ?? ''),
                'total_earned' => (float) ($row['total_earned'] ?? 0),
                'available_profit' => (float) ($row['available_profit'] ?? 0),
                'pending_withdrawals' => (float) ($row['pending_withdrawals'] ?? 0),
                'paid_withdrawals' => (float) ($row['paid_withdrawals'] ?? 0),
                'data_profit' => (float) ($row['data_profit'] ?? 0),
                'data_revenue' => (float) ($row['data_revenue'] ?? 0),
                'data_cost' => (float) ($row['data_cost'] ?? 0),
                'data_orders' => (int) ($row['data_orders'] ?? 0),
                'last_activity' => $row['last_activity'] ?? null,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('Profit monitor query failed: ' . $e->getMessage());
}

foreach ($rows as $row) {
    $summary['total_earned'] += (float) $row['total_earned'];
    $summary['available_profit'] += (float) $row['available_profit'];
    $summary['pending_withdrawals'] += (float) $row['pending_withdrawals'];
    $summary['paid_withdrawals'] += (float) $row['paid_withdrawals'];
    $summary['data_profit'] += (float) $row['data_profit'];
}
$summary['active_agents'] = count($rows);
$topAgents = array_slice($rows, 0, 5);

require_once '../includes/admin_header.php';
?>
<style>
.profit-monitor-page {
    min-width: 0;
    max-width: 100%;
    overflow-x: hidden;
}

.profit-monitor-page .dashboard-grid,
.profit-monitor-page .stats-grid,
.profit-monitor-page .widget,
.profit-monitor-page .widget-body,
.profit-monitor-page .table-responsive {
    min-width: 0;
    max-width: 100%;
}

.profit-monitor-page .profit-note {
    margin-bottom: 1.5rem;
    padding: 1rem 1.25rem;
    border-radius: 14px;
    border: 1px solid var(--border-color);
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(59, 130, 246, 0.06));
    color: var(--text-secondary);
}

.profit-monitor-page .mini-list {
    display: grid;
    gap: 0.9rem;
}

.profit-monitor-page .mini-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 0.9rem 0;
    border-bottom: 1px solid var(--border-color);
}

.profit-monitor-page .mini-list-item:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.profit-monitor-page .mini-list-item:first-child {
    padding-top: 0;
}

.profit-monitor-page .metric-stack {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.profit-monitor-page .metric-subtle {
    font-size: 0.85rem;
    color: var(--text-muted);
}

.profit-monitor-page .table td {
    vertical-align: middle;
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.profit-monitor-page .agent-name {
    font-weight: 600;
}

.profit-monitor-page .summary-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.7rem;
    border-radius: 999px;
    background: rgba(59, 130, 246, 0.08);
    color: var(--text-secondary);
    font-size: 0.85rem;
}

.profit-monitor-page .table-responsive {
    margin-left: 0;
    margin-right: 0;
}

.profit-monitor-page .profit-monitor-table {
    min-width: 0;
}

@media (max-width: 992px) {
    .profit-monitor-page .widget-header {
        align-items: flex-start;
        gap: 0.75rem;
    }

    .profit-monitor-page .widget-actions {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
}

@media (max-width: 768px) {
    .profit-monitor-page .profit-note {
        padding: 0.95rem 1rem;
        font-size: 0.92rem;
    }

    .profit-monitor-page .mini-list-item {
        flex-direction: column;
        align-items: flex-start;
    }

    .profit-monitor-page .mini-list-item > :last-child {
        width: 100%;
        text-align: left !important;
    }

    .profit-monitor-page .summary-chip {
        max-width: 100%;
        white-space: normal;
    }

    .profit-monitor-page .table-responsive {
        border: none;
        margin-left: 0;
        margin-right: 0;
        overflow-x: hidden;
    }

    .profit-monitor-page .responsive-table-stack tr {
        padding: 0.9rem 1rem;
        margin-bottom: 0.85rem;
    }

    .profit-monitor-page .responsive-table-stack td {
        padding: 0.45rem 0;
    }

    .profit-monitor-page .responsive-table-stack td::before {
        font-size: 0.78rem;
    }
}
</style>

<div class="profit-monitor-page">
    <div class="page-title">
        <h1>Profit Monitor</h1>
        <p class="page-subtitle">Admin view of Store Profit earned by agents, what has been withdrawn, and what is still available.</p>
    </div>

    <div class="profit-note">
        Profit Monitor shows Store Profit directly from completed store bundle orders: customer paid amount minus agent base cost. It does not use wallet balances.
    </div>

    <div class="stats-grid" style="margin-bottom: 1.5rem;">
        <div class="stat-card">
            <div class="stat-icon primary">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($summary['total_earned'])); ?></h3>
                <p>Total Store Profit</p>
                <p style="font-size: 0.8rem; color: var(--text-muted);">
                    <?php echo number_format((int) $summary['active_agents']); ?> agents with Store Profit activity
                </p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">
                <i class="fas fa-store"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($summary['available_profit'])); ?></h3>
                <p>Available Store Profit</p>
                <p style="font-size: 0.8rem; color: var(--text-muted);">
                    Completed store orders only, not wallet balance
                </p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon warning">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($summary['pending_withdrawals'])); ?></h3>
                <p>Pending Withdrawals</p>
                <p style="font-size: 0.8rem; color: var(--text-muted);">
                    Requests not fully processed yet
                </p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon secondary">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($summary['paid_withdrawals'])); ?></h3>
                <p>Paid Out</p>
                <p style="font-size: 0.8rem; color: var(--text-muted);">
                    Already approved or settled
                </p>
            </div>
        </div>
    </div>

    <div class="dashboard-grid" style="margin-bottom: 1.5rem;">
        <div class="widget">
            <div class="widget-header">
                <h3 class="widget-title">Top Agents By Store Profit</h3>
                <div class="widget-actions">
                    <span class="summary-chip">
                        <i class="fas fa-layer-group"></i>
                        Store Profit <?php echo htmlspecialchars(formatCurrency($summary['data_profit'])); ?>
                    </span>
                </div>
            </div>
            <div class="widget-body">
                <?php if (!empty($topAgents)): ?>
                    <div class="mini-list">
                        <?php foreach ($topAgents as $agent): ?>
                            <div class="mini-list-item">
                                <div class="metric-stack">
                                    <span class="agent-name"><?php echo htmlspecialchars($agent['full_name']); ?></span>
                                    <span class="metric-subtle"><?php echo htmlspecialchars($agent['email'] ?: 'No email'); ?></span>
                                </div>
                                <div class="metric-stack" style="text-align: right;">
                                    <span class="agent-name"><?php echo htmlspecialchars(formatCurrency($agent['total_earned'])); ?></span>
                                    <span class="metric-subtle">
                                        Available <?php echo htmlspecialchars(formatCurrency($agent['available_profit'])); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <p>No Store Profit has been recorded yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="widget">
            <div class="widget-header">
                <h3 class="widget-title">Store Profit Position</h3>
                <div class="widget-actions">
                    <span class="summary-chip">
                        <i class="fas fa-store"></i>
                        Agent Store Orders
                    </span>
                </div>
            </div>
            <div class="widget-body">
                <div class="mini-list">
                    <div class="mini-list-item">
                        <div class="metric-stack">
                            <span class="agent-name">Store Profit Earned</span>
                            <span class="metric-subtle">Customer paid price minus agent base cost</span>
                        </div>
                        <div class="agent-name"><?php echo htmlspecialchars(formatCurrency($summary['data_profit'])); ?></div>
                    </div>
                    <div class="mini-list-item">
                        <div class="metric-stack">
                            <span class="agent-name">Pending Withdrawal Exposure</span>
                            <span class="metric-subtle">Amounts requested but not fully settled</span>
                        </div>
                        <div class="agent-name"><?php echo htmlspecialchars(formatCurrency($summary['pending_withdrawals'])); ?></div>
                    </div>
                    <div class="mini-list-item">
                        <div class="metric-stack">
                            <span class="agent-name">Settled Withdrawal Total</span>
                            <span class="metric-subtle">Amounts already approved or paid</span>
                        </div>
                        <div class="agent-name"><?php echo htmlspecialchars(formatCurrency($summary['paid_withdrawals'])); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="widget">
        <div class="widget-header">
            <h3 class="widget-title">Agent Profit Table</h3>
            <p class="widget-subtitle">Each row shows Store Profit, withdrawal position, and activity by agent.</p>
        </div>
        <div class="widget-body">
            <?php if (!empty($rows)): ?>
                <div class="table-responsive">
                    <table class="table responsive-table-stack profit-monitor-table">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Store Profit Earned</th>
                                <th>Available Store Profit</th>
                                <th>Pending</th>
                                <th>Paid Out</th>
                                <th>Store Orders</th>
                                <th>Last Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $agent): ?>
                                <tr>
                                    <td data-label="Agent">
                                        <div class="metric-stack">
                                            <span class="agent-name"><?php echo htmlspecialchars($agent['full_name']); ?></span>
                                            <span class="metric-subtle"><?php echo htmlspecialchars($agent['email'] ?: 'No email'); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Store Profit Earned">
                                        <div class="metric-stack">
                                            <span class="agent-name"><?php echo htmlspecialchars(formatCurrency($agent['total_earned'])); ?></span>
                                            <span class="metric-subtle">
                                                Revenue <?php echo htmlspecialchars(formatCurrency($agent['data_revenue'])); ?>
                                                | Cost <?php echo htmlspecialchars(formatCurrency($agent['data_cost'])); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td data-label="Available Store Profit"><?php echo htmlspecialchars(formatCurrency($agent['available_profit'])); ?></td>
                                    <td data-label="Pending"><?php echo htmlspecialchars(formatCurrency($agent['pending_withdrawals'])); ?></td>
                                    <td data-label="Paid Out"><?php echo htmlspecialchars(formatCurrency($agent['paid_withdrawals'])); ?></td>
                                    <td data-label="Store Orders">
                                        <div class="metric-stack">
                                            <span><?php echo htmlspecialchars(formatCurrency($agent['data_profit'])); ?></span>
                                            <span class="metric-subtle"><?php echo number_format($agent['data_orders']); ?> bundle orders</span>
                                        </div>
                                    </td>
                                    <td data-label="Last Activity">
                                        <?php if (!empty($agent['last_activity'])): ?>
                                            <div class="metric-stack">
                                                <span><?php echo htmlspecialchars(date('M j, Y', strtotime($agent['last_activity']))); ?></span>
                                                <span class="metric-subtle"><?php echo htmlspecialchars(date('g:i A', strtotime($agent['last_activity']))); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="metric-subtle">No activity</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-coins"></i>
                    <p>No Store Profit records have been generated for agents yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
