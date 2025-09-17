<?php
/**
 * M-Pesa STK Push Callback Handler
 * This file processes callbacks from M-Pesa after payment attempts
 */

require_once '../config/database.php';
require_once '../config/mpesa.php';

// Set content type
header('Content-Type: application/json');

// Log all incoming requests for debugging
$callback_data = file_get_contents('php://input');
$log_entry = date('Y-m-d H:i:s') . " - M-Pesa Callback: " . $callback_data . PHP_EOL;
error_log($log_entry, 3, __DIR__ . '/../logs/mpesa_callbacks.log');

try {
    // Decode the JSON callback data
    $callback_json = json_decode($callback_data, true);
    
    if (!$callback_json) {
        throw new Exception('Invalid JSON data received');
    }
    
    // Validate callback structure
    if (!isset($callback_json['Body']['stkCallback'])) {
        throw new Exception('Invalid callback structure');
    }
    
    $callback = $callback_json['Body']['stkCallback'];
    $checkout_request_id = $callback['CheckoutRequestID'] ?? '';
    $result_code = $callback['ResultCode'] ?? '';
    $result_desc = $callback['ResultDesc'] ?? '';
    
    if (empty($checkout_request_id)) {
        throw new Exception('Missing CheckoutRequestID');
    }
    
    // Find the transaction in our database
    $stmt = $db->prepare("
        SELECT t.*, u.full_name, u.email 
        FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.mpesa_request_id = ?
    ");
    $stmt->execute([$checkout_request_id]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        error_log("M-Pesa Callback: Transaction not found for CheckoutRequestID: $checkout_request_id");
        http_response_code(404);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
        exit;
    }
    
    $db->beginTransaction();
    
    if ($result_code == 0) {
        // Payment was successful
        $mpesa_receipt = '';
        $transaction_date = '';
        $phone_number = '';
        $amount_paid = 0;
        
        // Extract callback metadata
        if (isset($callback['CallbackMetadata']['Item'])) {
            foreach ($callback['CallbackMetadata']['Item'] as $item) {
                switch ($item['Name']) {
                    case 'MpesaReceiptNumber':
                        $mpesa_receipt = $item['Value'] ?? '';
                        break;
                    case 'TransactionDate':
                        $transaction_date = $item['Value'] ?? '';
                        break;
                    case 'PhoneNumber':
                        $phone_number = $item['Value'] ?? '';
                        break;
                    case 'Amount':
                        $amount_paid = (float)($item['Value'] ?? 0);
                        break;
                }
            }
        }
        
        // Verify amount matches
        if ($amount_paid != $transaction['amount']) {
            error_log("M-Pesa Callback: Amount mismatch. Expected: {$transaction['amount']}, Received: $amount_paid");
            // Still process but log the discrepancy
        }
        
        // Update transaction status
        $stmt = $db->prepare("
            UPDATE transactions 
            SET status = 'completed', 
                mpesa_receipt = ?, 
                description = CONCAT(description, ' - M-Pesa Receipt: ', ?),
                updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$mpesa_receipt, $mpesa_receipt, $transaction['id']]);
        
        // Process based on transaction type
        if ($transaction['type'] === 'deposit') {
            // Credit user's wallet
            $stmt = $db->prepare("
                UPDATE users 
                SET wallet_balance = wallet_balance + ?, 
                    total_deposited = total_deposited + ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$transaction['amount'], $transaction['amount'], $transaction['user_id']]);
            
            // Send success notification
            sendNotification(
                $transaction['user_id'],
                'Deposit Successful! 🎉',
                "Your deposit of " . formatMoney($transaction['amount']) . " has been credited to your wallet. M-Pesa Receipt: $mpesa_receipt. You can now start trading!",
                'success'
            );
            
            // Process referral commissions
            processReferralCommissions($transaction, $db);
        }
        
        $db->commit();
        
        // Log successful payment
        error_log("M-Pesa Payment Success: User {$transaction['full_name']}, Amount: {$transaction['amount']}, Receipt: $mpesa_receipt");
        
        // Send email notification (if email system is configured)
        sendEmailNotification($transaction, $mpesa_receipt, 'success');
        
    } else {
        // Payment failed or was cancelled
        $stmt = $db->prepare("
            UPDATE transactions 
            SET status = 'failed', 
                description = CONCAT(description, ' - M-Pesa Error: ', ?),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$result_desc, $transaction['id']]);
        
        // Send failure notification
        sendNotification(
            $transaction['user_id'],
            'Payment Failed ❌',
            "Your payment of " . formatMoney($transaction['amount']) . " could not be processed. Reason: $result_desc. Please try again or contact support.",
            'error'
        );
        
        $db->commit();
        
        // Log failed payment
        error_log("M-Pesa Payment Failed: User {$transaction['full_name']}, Amount: {$transaction['amount']}, Reason: $result_desc");
        
        // Send email notification
        sendEmailNotification($transaction, null, 'failed', $result_desc);
    }
    
    // Respond to M-Pesa with success
    http_response_code(200);
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Callback processed successfully'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    // Log the error
    error_log("M-Pesa Callback Error: " . $e->getMessage() . " - Data: " . $callback_data);
    
    // Respond with error
    http_response_code(500);
    echo json_encode([
        'ResultCode' => 1,
        'ResultDesc' => 'Internal server error'
    ]);
}

/**
 * Process referral commissions for deposits
 */
function processReferralCommissions($transaction, $db) {
    try {
        // Get referrer information
        $stmt = $db->prepare("SELECT referred_by FROM users WHERE id = ?");
        $stmt->execute([$transaction['user_id']]);
        $user_data = $stmt->fetch();
        
        if ($user_data && $user_data['referred_by']) {
            $referrer_id = $user_data['referred_by'];
            $deposit_amount = $transaction['amount'];
            
            // Level 1 commission
            $l1_rate = (float)getSystemSetting('referral_commission_l1', 10);
            $l1_commission = ($deposit_amount * $l1_rate) / 100;
            
            if ($l1_commission > 0) {
                // Credit Level 1 referrer
                $stmt = $db->prepare("
                    UPDATE users 
                    SET referral_earnings = referral_earnings + ?, 
                        wallet_balance = wallet_balance + ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$l1_commission, $l1_commission, $referrer_id]);
                
                // Create commission transaction
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, status, description, created_at) 
                    VALUES (?, 'referral_commission', ?, 'completed', ?, NOW())
                ");
                $description = "Level 1 referral commission from deposit (Receipt: {$transaction['mpesa_receipt']})";
                $stmt->execute([$referrer_id, $l1_commission, $description]);
                
                // Notify Level 1 referrer
                sendNotification(
                    $referrer_id,
                    'Referral Commission Earned! 💰',
                    "You earned " . formatMoney($l1_commission) . " ({$l1_rate}%) commission from a referral deposit. Keep sharing to earn more!",
                    'success'
                );
                
                // Check for Level 2 referrer
                $stmt = $db->prepare("SELECT referred_by FROM users WHERE id = ?");
                $stmt->execute([$referrer_id]);
                $l1_referrer_data = $stmt->fetch();
                
                if ($l1_referrer_data && $l1_referrer_data['referred_by']) {
                    $l2_referrer_id = $l1_referrer_data['referred_by'];
                    $l2_rate = (float)getSystemSetting('referral_commission_l2', 5);
                    $l2_commission = ($deposit_amount * $l2_rate) / 100;
                    
                    if ($l2_commission > 0) {
                        // Credit Level 2 referrer
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET referral_earnings = referral_earnings + ?, 
                                wallet_balance = wallet_balance + ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$l2_commission, $l2_commission, $l2_referrer_id]);
                        
                        // Create L2 commission transaction
                        $stmt = $db->prepare("
                            INSERT INTO transactions (user_id, type, amount, status, description, created_at) 
                            VALUES (?, 'referral_commission', ?, 'completed', ?, NOW())
                        ");
                        $l2_description = "Level 2 referral commission from deposit (Receipt: {$transaction['mpesa_receipt']})";
                        $stmt->execute([$l2_referrer_id, $l2_commission, $l2_description]);
                        
                        // Notify Level 2 referrer
                        sendNotification(
                            $l2_referrer_id,
                            'L2 Referral Commission! 🌟',
                            "You earned " . formatMoney($l2_commission) . " ({$l2_rate}%) Level 2 commission from an indirect referral.",
                            'success'
                        );
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Referral Commission Processing Error: " . $e->getMessage());
        // Don't fail the main transaction for referral errors
    }
}

/**
 * Send email notification (if email system is configured)
 */
function sendEmailNotification($transaction, $receipt, $status, $error_message = null) {
    // This is a placeholder for email functionality
    // You can integrate with services like PHPMailer, SendGrid, etc.
    
    $to = $transaction['email'];
    $subject = $status === 'success' ? 'Payment Successful - Ultra Harvest' : 'Payment Failed - Ultra Harvest';
    
    if ($status === 'success') {
        $message = "
        Dear {$transaction['full_name']},
        
        Your payment has been processed successfully!
        
        Details:
        - Amount: " . formatMoney($transaction['amount']) . "
        - M-Pesa Receipt: $receipt
        - Date: " . date('Y-m-d H:i:s') . "
        
        Your wallet has been credited and you can now start trading.
        
        Thank you for choosing Ultra Harvest Global!
        
        Best regards,
        Ultra Harvest Team
        ";
    } else {
        $message = "
        Dear {$transaction['full_name']},
        
        Unfortunately, your payment could not be processed.
        
        Details:
        - Amount: " . formatMoney($transaction['amount']) . "
        - Reason: $error_message
        - Date: " . date('Y-m-d H:i:s') . "
        
        Please try again or contact our support team for assistance.
        
        Best regards,
        Ultra Harvest Team
        ";
    }
    
    // Log email (replace with actual email sending)
    error_log("Email Notification: To: $to, Subject: $subject, Status: $status");
    
    // Uncomment and configure for actual email sending:
    // mail($to, $subject, $message);
}

/**
 * Handle webhook validation from M-Pesa
 */
function validateMpesaWebhook($data) {
    // M-Pesa may send validation requests
    // Implement signature verification if required
    return true;
}

/**
 * Log callback for debugging and audit purposes
 */
function logCallback($data, $processed_successfully = true) {
    $log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $data,
        'processed' => $processed_successfully,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $log_file = __DIR__ . '/../logs/mpesa_callbacks.log';
    file_put_contents($log_file, json_encode($log_data) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Security check for M-Pesa callbacks
 */
function validateCallbackSecurity() {
    // Check if request is from M-Pesa servers
    // You can implement IP whitelisting or other security measures
    
    $allowed_ips = [
        '196.201.214.200', // M-Pesa callback IP (example)
        '196.201.214.206', // M-Pesa callback IP (example)
        // Add actual M-Pesa IP addresses
    ];
    
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // For development, allow localhost
    if (in_array($client_ip, ['127.0.0.1', '::1']) || 
        strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
        return true;
    }
    
    // In production, you might want to validate IP addresses
    // return in_array($client_ip, $allowed_ips);
    
    return true; // Allow all for now
}

// Validate security (uncomment in production)
// if (!validateCallbackSecurity()) {
//     http_response_code(403);
//     echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Unauthorized']);
//     exit;
// }

// Log this callback
logCallback($callback_data, true);
?>