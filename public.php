<?php

declare(strict_types=1);

// Set session timeout to 10 minutes (600 seconds)
ini_set('session.gc_maxlifetime', '600');
session_set_cookie_params(600);
session_start();

require_once __DIR__ . '/vendor/autoload.php';

use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\PluginConfigManager;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;

// Check if admin user is logged in - load admin page instead
try {
    $security = UcrmSecurity::create();
    $user = $security->getUser();
    if ($user) {
        // Admin is logged in, include main.php instead
        require_once __DIR__ . '/main.php';
        exit;
    }
} catch (Exception $e) {
    // Not logged in, continue to public page
}

// Initialize
$api = null;
$config = [];
$message = '';
$messageType = '';
$accountNumber = '';
$amount = '';
$referenceNumber = '';
$step = 1;

// Load plugin configuration
try {
    $configManager = PluginConfigManager::create();
    $config = $configManager->loadConfig();
} catch (Exception $e) {
    $message = 'Configuration error. Please contact support.';
    $messageType = 'error';
}

// Load API
try {
    $api = UcrmApi::create();
} catch (Exception $e) {
    $message = 'System temporarily unavailable.';
    $messageType = 'error';
}

$recaptchaSiteKey = $config['recaptchaSiteKey'] ?? '';
$recaptchaSecretKey = $config['recaptchaSecretKey'] ?? '';

// Function to check if current day matches the configured days
function isDayActive($daysConfig) {
    if (empty($daysConfig)) {
        return true; // Empty means always active
    }
    
    $currentDay = (int)date('j'); // Current day of month (1-31)
    $parts = preg_split('/[,\s]+/', $daysConfig);
    
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        
        // Check for range (e.g., 1-10)
        if (strpos($part, '-') !== false) {
            list($start, $end) = explode('-', $part, 2);
            $start = (int)trim($start);
            $end = (int)trim($end);
            if ($currentDay >= $start && $currentDay <= $end) {
                return true;
            }
        } else {
            // Single day
            if ($currentDay === (int)$part) {
                return true;
            }
        }
    }
    
    return false;
}

// Select active GCash account based on current date
$gcashNumber = '';
$gcashName = '';
$gcashQrCode = '';
$activeAccount = 0;

// Check accounts in order (1, 2, 3)
for ($i = 1; $i <= 3; $i++) {
    $number = $config["gcashNumber{$i}"] ?? '';
    $name = $config["gcashName{$i}"] ?? '';
    $qrCode = $config["gcashQrCode{$i}"] ?? '';
    $days = $config["gcashDays{$i}"] ?? '';
    
    if (!empty($number) && !empty($name) && isDayActive($days)) {
        $gcashNumber = $number;
        $gcashName = $name;
        $gcashQrCode = $qrCode;
        $activeAccount = $i;
        break;
    }
}

// Fallback to Account #1 if no active account found
if (empty($gcashNumber)) {
    $gcashNumber = $config['gcashNumber1'] ?? '';
    $gcashName = $config['gcashName1'] ?? '';
    $gcashQrCode = $config['gcashQrCode1'] ?? '';
    $activeAccount = 1;
}

// Function to verify reCAPTCHA v3
function verifyRecaptcha($token, $secretKey) {
    if (empty($secretKey) || empty($token)) {
        return true; // Skip if not configured
    }
    
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => $secretKey,
        'response' => $token
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    $response = json_decode($result, true);
    
    return isset($response['success']) && $response['success'] && ($response['score'] ?? 0) >= 0.5;
}

// Check for redirects (PRG pattern)
if (isset($_GET['success']) && isset($_GET['ref'])) {
    $message = 'Payment submitted successfully! Reference: ' . htmlspecialchars($_GET['ref']) . '. Please wait for verification.';
    $messageType = 'success';
    $step = 1;
} elseif (isset($_GET['step']) && $_GET['step'] === '2') {
    $step = 2;
} elseif (isset($_GET['error'])) {
    $message = htmlspecialchars($_GET['error']);
    $messageType = 'error';
    $step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $api !== null) {
    $action = $_POST['action'] ?? '';
    $recaptchaToken = $_POST['recaptcha_token'] ?? '';
    
    // Verify reCAPTCHA if configured
    $recaptchaValid = verifyRecaptcha($recaptchaToken, $recaptchaSecretKey);
    
    if (!$recaptchaValid) {
        $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?error=' . urlencode('Security verification failed. Please try again.');
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'verify_account') {
        $accountNumber = trim($_POST['account_number'] ?? '');
        $amount = trim($_POST['amount'] ?? '');
        
        if (empty($accountNumber)) {
            $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?error=' . urlencode('Account number is required.');
            header('Location: ' . $redirectUrl);
            exit;
        } elseif (empty($amount) || !is_numeric($amount) || floatval($amount) <= 0) {
            $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?error=' . urlencode('Please enter a valid amount.');
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            // Find client by account number
            try {
                $clients = $api->get('clients');
                $foundClient = null;
                
                foreach ($clients as $client) {
                    if (isset($client['userIdent']) && $client['userIdent'] === $accountNumber) {
                        $foundClient = $client;
                        break;
                    }
                }
                
                if ($foundClient) {
                    $_SESSION['gcash_client_id'] = $foundClient['id'];
                    $_SESSION['gcash_client_name'] = trim(($foundClient['firstName'] ?? '') . ' ' . ($foundClient['lastName'] ?? ''));
                    $_SESSION['gcash_account_number'] = $accountNumber;
                    $_SESSION['gcash_amount'] = floatval($amount);
                    // Redirect to step 2 (PRG pattern)
                    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?step=2';
                    header('Location: ' . $redirectUrl);
                    exit;
                } else {
                    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?error=' . urlencode('Account number not found. Please check and try again.');
                    header('Location: ' . $redirectUrl);
                    exit;
                }
            } catch (Exception $e) {
                $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?error=' . urlencode('Error verifying account. Please try again.');
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
    } elseif ($action === 'submit_payment') {
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        
        if (empty($referenceNumber)) {
            $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?step=2&error=' . urlencode('GCash reference number is required.');
            header('Location: ' . $redirectUrl);
            exit;
        } elseif (!isset($_SESSION['gcash_client_id'])) {
            $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?error=' . urlencode('Session expired. Please start over.');
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            // Save pending payment
            $pendingPayment = [
                'id' => uniqid('gcash_'),
                'clientId' => $_SESSION['gcash_client_id'],
                'clientName' => $_SESSION['gcash_client_name'],
                'accountNumber' => $_SESSION['gcash_account_number'],
                'amount' => $_SESSION['gcash_amount'],
                'referenceNumber' => $referenceNumber,
                'status' => 'pending',
                'createdAt' => date('Y-m-d H:i:s'),
                'gcashNumber' => $gcashNumber,
                'gcashName' => $gcashName
            ];
            
            // Load existing payments
            $paymentsFile = __DIR__ . '/data/pending_payments.json';
            $payments = [];
            if (file_exists($paymentsFile)) {
                $payments = json_decode(file_get_contents($paymentsFile), true) ?: [];
            }
            
            // Check for duplicate reference
            $isDuplicate = false;
            foreach ($payments as $p) {
                if ($p['referenceNumber'] === $referenceNumber) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if ($isDuplicate) {
                $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?step=2&error=' . urlencode('This reference number has already been submitted.');
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                // Save payment
                $payments[] = $pendingPayment;
                
                if (!is_dir(__DIR__ . '/data')) {
                    mkdir(__DIR__ . '/data', 0755, true);
                }
                file_put_contents($paymentsFile, json_encode($payments, JSON_PRETTY_PRINT));
                
                // Clear session
                unset($_SESSION['gcash_client_id']);
                unset($_SESSION['gcash_client_name']);
                unset($_SESSION['gcash_account_number']);
                unset($_SESSION['gcash_amount']);
                
                // Redirect to prevent resubmission
                $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?') . '?success=1&ref=' . urlencode($referenceNumber);
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
    }
}

// Restore session data for step 2
if ($step === 2 && isset($_SESSION['gcash_client_name'])) {
    $clientName = $_SESSION['gcash_client_name'];
    $sessionAmount = $_SESSION['gcash_amount'];
    $sessionAccountNumber = $_SESSION['gcash_account_number'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay with GCash</title>
    <?php if ($recaptchaSiteKey): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($recaptchaSiteKey) ?>"></script>
    <?php endif; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #007dfe 0%, #0057b8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 480px;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #007dfe 0%, #0057b8 100%);
            padding: 32px;
            text-align: center;
        }
        
        .gcash-logo {
            width: 120px;
            height: auto;
            margin-bottom: 16px;
        }
        
        .card-header h1 {
            color: white;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .card-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
        }
        
        .card-body {
            padding: 32px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .form-group label .required {
            color: #ef4444;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f9fafb;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #007dfe;
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 125, 254, 0.1);
        }
        
        .help-text {
            font-size: 12px;
            color: #6b7280;
            margin-top: 6px;
        }
        
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #007dfe 0%, #0057b8 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 125, 254, 0.4);
        }
        
        .submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .message {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
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
        
        .qr-section {
            text-align: center;
            padding: 20px;
            background: #f3f4f6;
            border-radius: 16px;
            margin-bottom: 20px;
        }
        
        .qr-code {
            max-width: 200px;
            margin: 0 auto 16px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .gcash-info {
            background: #eff6ff;
            border: 2px solid #007dfe;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .gcash-info h3 {
            color: #007dfe;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .gcash-info p {
            color: #1e40af;
            font-size: 18px;
            font-weight: 700;
        }
        
        .gcash-info .name {
            font-size: 14px;
            font-weight: 500;
            color: #3b82f6;
        }
        
        .payment-summary {
            background: #f0fdf4;
            border: 2px solid #22c55e;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .payment-summary h3 {
            color: #166534;
            font-size: 14px;
            margin-bottom: 12px;
        }
        
        .payment-summary .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .payment-summary .label {
            color: #4b5563;
        }
        
        .payment-summary .value {
            font-weight: 600;
            color: #111827;
        }
        
        .payment-summary .amount {
            font-size: 24px;
            color: #166534;
        }
        
        .steps {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        
        .step {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
        }
        
        .step.active {
            background: #007dfe;
            color: white;
        }
        
        .step.inactive {
            background: #e5e7eb;
            color: #9ca3af;
        }
        
        .step-line {
            width: 40px;
            height: 2px;
            background: #e5e7eb;
            align-self: center;
        }
        
        .back-btn {
            display: inline-block;
            color: #007dfe;
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 16px;
        }
        
        .back-btn:hover {
            text-decoration: underline;
        }
        
        .instructions {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .instructions h4 {
            color: #92400e;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .instructions ol {
            color: #78350f;
            font-size: 13px;
            padding-left: 20px;
        }
        
        .instructions li {
            margin-bottom: 4px;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <svg class="gcash-logo" viewBox="0 0 120 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="120" height="40" rx="8" fill="white"/>
                    <text x="60" y="26" text-anchor="middle" fill="#007dfe" font-size="18" font-weight="bold">GCash</text>
                </svg>
                <h1>Pay with GCash</h1>
                <p>Fast, secure, and convenient payment</p>
            </div>
            
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="message <?= $messageType ?>">
                        <span><?= $messageType === 'success' ? '✅' : '❌' ?></span>
                        <span><?= htmlspecialchars($message) ?></span>
                    </div>
                <?php endif; ?>
                
                <div class="steps">
                    <div class="step <?= $step >= 1 ? 'active' : 'inactive' ?>">1</div>
                    <div class="step-line"></div>
                    <div class="step <?= $step >= 2 ? 'active' : 'inactive' ?>">2</div>
                </div>
                
                <?php if ($step === 1): ?>
                    <!-- Step 1: Enter Account Number and Amount -->
                    <form method="POST" id="form-step1">
                        <input type="hidden" name="action" value="verify_account">
                        <input type="hidden" name="recaptcha_token" id="recaptcha_token_1">
                        
                        <div class="form-group">
                            <label>Account Number <span class="required">*</span></label>
                            <input type="text" name="account_number" placeholder="Enter your account number" 
                                   value="<?= htmlspecialchars($accountNumber) ?>" required>
                            <p class="help-text">Your account number from your billing statement</p>
                        </div>
                        
                        <div class="form-group">
                            <label>Amount (PHP) <span class="required">*</span></label>
                            <input type="number" name="amount" placeholder="0.00" step="0.01" min="1"
                                   value="<?= htmlspecialchars($amount) ?>" required>
                            <p class="help-text">Enter the exact amount you will send via GCash</p>
                        </div>
                        
                        <button type="submit" class="submit-btn">Continue to Payment</button>
                    </form>
                    
                <?php elseif ($step === 2): ?>
                    <!-- Step 2: Show QR Code and Enter Reference -->
                    <a href="?" class="back-btn">← Back to Step 1</a>
                    
                    <div class="payment-summary">
                        <h3>Payment Summary</h3>
                        <div class="row">
                            <span class="label">Account:</span>
                            <span class="value"><?= htmlspecialchars($sessionAccountNumber ?? '') ?></span>
                        </div>
                        <div class="row">
                            <span class="label">Amount:</span>
                            <span class="value amount">₱<?= number_format($sessionAmount ?? 0, 2) ?></span>
                        </div>
                    </div>
                    
                    <?php if ($gcashQrCode): ?>
                        <div class="qr-section">
                            <img src="<?= htmlspecialchars($gcashQrCode) ?>" alt="GCash QR Code" class="qr-code">
                            <p style="font-size: 13px; color: #6b7280;">Scan this QR code with your GCash app</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="gcash-info">
                        <h3>Send Payment To:</h3>
                        <p><?= htmlspecialchars($gcashNumber) ?></p>
                        <p class="name"><?= htmlspecialchars($gcashName) ?></p>
                    </div>
                    
                    <div class="instructions">
                        <h4>📱 How to Pay:</h4>
                        <ol>
                            <li>Open your GCash app</li>
                            <li>Tap "Send Money" or scan the QR code</li>
                            <li>Enter amount: <strong>₱<?= number_format($sessionAmount ?? 0, 2) ?></strong></li>
                            <li>Complete the payment</li>
                            <li>Copy the Reference Number from your receipt</li>
                            <li>Enter it below and submit</li>
                        </ol>
                    </div>
                    
                    <form method="POST" id="form-step2">
                        <input type="hidden" name="action" value="submit_payment">
                        <input type="hidden" name="recaptcha_token" id="recaptcha_token_2">
                        
                        <div class="form-group">
                            <label>GCash Reference Number <span class="required">*</span></label>
                            <input type="text" name="reference_number" placeholder="e.g., 1036 573 280143" 
                                   value="<?= htmlspecialchars($referenceNumber) ?>" required
                                   pattern="[0-9\s]+" title="Please enter only numbers">
                            <p class="help-text">Found in your GCash payment confirmation</p>
                        </div>
                        
                        <button type="submit" class="submit-btn">Submit Payment</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer">
            <p>Secure payment processing • Reference numbers are verified</p>
        </div>
    </div>
    
    <script>
        // Clean URL after page load (remove query parameters)
        if (window.location.search) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        
        // Format reference number input (4-3-6 format)
        document.querySelector('input[name="reference_number"]')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 13) value = value.substr(0, 13);
            let formatted = '';
            if (value.length > 0) formatted = value.substr(0, 4);
            if (value.length > 4) formatted += ' ' + value.substr(4, 3);
            if (value.length > 7) formatted += ' ' + value.substr(7, 6);
            e.target.value = formatted;
        });
        
        <?php if ($recaptchaSiteKey): ?>
        // reCAPTCHA v3 form handling
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const tokenInput = form.querySelector('input[name="recaptcha_token"]');
                grecaptcha.ready(function() {
                    grecaptcha.execute('<?= htmlspecialchars($recaptchaSiteKey) ?>', {action: 'submit'}).then(function(token) {
                        tokenInput.value = token;
                        form.submit();
                    });
                });
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>
