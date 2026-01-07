<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\PluginConfigManager;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;

// Security check
$security = UcrmSecurity::create();
$user = $security->getUser();
if (!$user) {
    header("HTTP/1.1 403 Forbidden");
    die('Access denied. Please log in to UISP.');
}

// Initialize
$api = UcrmApi::create();
$configManager = PluginConfigManager::create();
$config = $configManager->loadConfig();

$message = '';
$messageType = '';

// Load pending payments
$paymentsFile = __DIR__ . '/data/pending_payments.json';
$payments = [];
if (file_exists($paymentsFile)) {
    $payments = json_decode(file_get_contents($paymentsFile), true) ?: [];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $paymentId = $_POST['payment_id'] ?? '';
    
    if ($action === 'approve' && $paymentId) {
        // Find the payment
        $paymentIndex = null;
        $payment = null;
        foreach ($payments as $index => $p) {
            if ($p['id'] === $paymentId) {
                $paymentIndex = $index;
                $payment = $p;
                break;
            }
        }
        
        if ($payment && $payment['status'] === 'pending') {
            try {
                // Create payment in UISP
                $paymentData = [
                    'clientId' => (int)$payment['clientId'],
                    'amount' => (float)$payment['amount'],
                    'note' => 'GCash Payment - Ref: ' . $payment['referenceNumber'],
                    'applyToInvoicesAutomatically' => true,
                    'userId' => $user->userId ?? null
                ];
                
                // Add payment method if configured (by name or UUID)
                $methodConfig = $config['paymentMethodId'] ?? '';
                if (!empty($methodConfig)) {
                    // Check if it's a UUID
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $methodConfig)) {
                        $paymentData['methodId'] = $methodConfig;
                    } else {
                        // Lookup payment method by name
                        try {
                            $methods = $api->get('payment-methods');
                            foreach ($methods as $method) {
                                if (strcasecmp($method['name'] ?? '', $methodConfig) === 0) {
                                    $paymentData['methodId'] = $method['id'];
                                    break;
                                }
                            }
                        } catch (Exception $e) {
                            // Ignore - will use default method
                        }
                    }
                }
                
                $response = $api->post('payments', $paymentData);
                
                if (isset($response['id'])) {
                    // Update payment status
                    $payments[$paymentIndex]['status'] = 'approved';
                    $payments[$paymentIndex]['uispPaymentId'] = $response['id'];
                    $payments[$paymentIndex]['approvedAt'] = date('Y-m-d H:i:s');
                    $payments[$paymentIndex]['approvedBy'] = $user->username ?? 'admin';
                    
                    file_put_contents($paymentsFile, json_encode($payments, JSON_PRETTY_PRINT));
                    
                    $message = 'Payment approved and posted to UISP! Payment ID: ' . $response['id'];
                    $messageType = 'success';
                } else {
                    $message = 'Failed to create payment in UISP.';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'reject' && $paymentId) {
        // Find and reject payment
        foreach ($payments as $index => $p) {
            if ($p['id'] === $paymentId && $p['status'] === 'pending') {
                $payments[$index]['status'] = 'rejected';
                $payments[$index]['rejectedAt'] = date('Y-m-d H:i:s');
                $payments[$index]['rejectedBy'] = $user->username ?? 'admin';
                
                file_put_contents($paymentsFile, json_encode($payments, JSON_PRETTY_PRINT));
                
                $message = 'Payment rejected.';
                $messageType = 'info';
                break;
            }
        }
    } elseif ($action === 'delete' && $paymentId) {
        // Delete payment record
        foreach ($payments as $index => $p) {
            if ($p['id'] === $paymentId) {
                unset($payments[$index]);
                $payments = array_values($payments);
                file_put_contents($paymentsFile, json_encode($payments, JSON_PRETTY_PRINT));
                
                $message = 'Payment record deleted.';
                $messageType = 'info';
                break;
            }
        }
    }
    
    // Reload payments after action
    if (file_exists($paymentsFile)) {
        $payments = json_decode(file_get_contents($paymentsFile), true) ?: [];
    }
}

// Filter payments
$filter = $_GET['filter'] ?? 'pending';
$filteredPayments = array_filter($payments, function($p) use ($filter) {
    if ($filter === 'all') return true;
    return $p['status'] === $filter;
});

// Sort by date (newest first)
usort($filteredPayments, function($a, $b) {
    return strtotime($b['createdAt']) - strtotime($a['createdAt']);
});

// Count by status
$counts = [
    'pending' => count(array_filter($payments, fn($p) => $p['status'] === 'pending')),
    'approved' => count(array_filter($payments, fn($p) => $p['status'] === 'approved')),
    'rejected' => count(array_filter($payments, fn($p) => $p['status'] === 'rejected')),
    'all' => count($payments)
];

// Calculate totals
$totalPending = array_sum(array_map(fn($p) => $p['status'] === 'pending' ? $p['amount'] : 0, $payments));
$totalApproved = array_sum(array_map(fn($p) => $p['status'] === 'approved' ? $p['amount'] : 0, $payments));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash Payments - Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            padding: 24px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .header h1 {
            color: #111827;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header h1 span {
            background: linear-gradient(135deg, #007dfe 0%, #0057b8 100%);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card.pending {
            border-left: 4px solid #f59e0b;
        }
        
        .stat-card.approved {
            border-left: 4px solid #22c55e;
        }
        
        .stat-card.rejected {
            border-left: 4px solid #ef4444;
        }
        
        .stat-card.total {
            border-left: 4px solid #007dfe;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }
        
        .stat-amount {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .filters {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }
        
        .filter-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .filter-btn.active {
            background: #007dfe;
            color: white;
        }
        
        .filter-btn:not(.active) {
            background: white;
            color: #374151;
        }
        
        .filter-btn:hover:not(.active) {
            background: #e5e7eb;
        }
        
        .message {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        
        .message.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        
        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .message.info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }
        
        .payments-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f9fafb;
            padding: 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        td {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            color: #374151;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-badge.approved {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-badge.rejected {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .amount {
            font-weight: 600;
            color: #059669;
        }
        
        .actions {
            display: flex;
            gap: 8px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-approve {
            background: #22c55e;
            color: white;
        }
        
        .btn-approve:hover {
            background: #16a34a;
        }
        
        .btn-reject {
            background: #ef4444;
            color: white;
        }
        
        .btn-reject:hover {
            background: #dc2626;
        }
        
        .btn-delete {
            background: #6b7280;
            color: white;
        }
        
        .btn-delete:hover {
            background: #4b5563;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .ref-number {
            font-family: monospace;
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .client-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .client-name {
            font-weight: 500;
            color: #111827;
        }
        
        .account-number {
            font-size: 12px;
            color: #6b7280;
        }
        
        .config-notice {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .config-notice h3 {
            color: #92400e;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .config-notice p {
            color: #78350f;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>
            <span>💳</span>
            GCash Payments
        </h1>
    </div>
    
    <?php if (empty($config['gcashNumber'])): ?>
        <div class="config-notice">
            <h3>⚠️ Configuration Required</h3>
            <p>Please configure your GCash number and account name in the plugin settings before accepting payments.</p>
        </div>
    <?php endif; ?>
    
    <?php if ($message): ?>
        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <div class="stats">
        <div class="stat-card pending">
            <div class="stat-label">Pending Verification</div>
            <div class="stat-value"><?= $counts['pending'] ?></div>
            <div class="stat-amount">₱<?= number_format($totalPending, 2) ?></div>
        </div>
        <div class="stat-card approved">
            <div class="stat-label">Approved</div>
            <div class="stat-value"><?= $counts['approved'] ?></div>
            <div class="stat-amount">₱<?= number_format($totalApproved, 2) ?></div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-label">Rejected</div>
            <div class="stat-value"><?= $counts['rejected'] ?></div>
        </div>
        <div class="stat-card total">
            <div class="stat-label">Total Records</div>
            <div class="stat-value"><?= $counts['all'] ?></div>
        </div>
    </div>
    
    <div class="filters">
        <a href="?filter=pending" class="filter-btn <?= $filter === 'pending' ? 'active' : '' ?>">
            Pending (<?= $counts['pending'] ?>)
        </a>
        <a href="?filter=approved" class="filter-btn <?= $filter === 'approved' ? 'active' : '' ?>">
            Approved (<?= $counts['approved'] ?>)
        </a>
        <a href="?filter=rejected" class="filter-btn <?= $filter === 'rejected' ? 'active' : '' ?>">
            Rejected (<?= $counts['rejected'] ?>)
        </a>
        <a href="?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">
            All (<?= $counts['all'] ?>)
        </a>
    </div>
    
    <div class="payments-table">
        <?php if (empty($filteredPayments)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <p>No <?= $filter !== 'all' ? $filter : '' ?> payments found</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Amount</th>
                        <th>Reference</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filteredPayments as $payment): ?>
                        <tr>
                            <td><?= date('M d, Y H:i', strtotime($payment['createdAt'])) ?></td>
                            <td>
                                <div class="client-info">
                                    <span class="client-name"><?= htmlspecialchars($payment['clientName']) ?></span>
                                    <span class="account-number">#<?= htmlspecialchars($payment['accountNumber']) ?></span>
                                </div>
                            </td>
                            <td class="amount">₱<?= number_format($payment['amount'], 2) ?></td>
                            <td><span class="ref-number"><?= htmlspecialchars($payment['referenceNumber']) ?></span></td>
                            <td>
                                <span class="status-badge <?= $payment['status'] ?>">
                                    <?= ucfirst($payment['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($payment['status'] === 'pending'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                            <button type="submit" class="btn btn-approve" 
                                                    onclick="return confirm('Approve this payment and post to UISP?')">
                                                ✓ Approve
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                            <button type="submit" class="btn btn-reject"
                                                    onclick="return confirm('Reject this payment?')">
                                                ✗ Reject
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                            <button type="submit" class="btn btn-delete"
                                                    onclick="return confirm('Delete this record permanently?')">
                                                🗑 Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
