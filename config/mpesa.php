<?php
/**
 * M-Pesa Integration Configuration and Functions
 * Ultra Harvest Global - M-Pesa STK Push Integration
 */

require_once 'database.php';

class MpesaIntegration {
    private $consumer_key;
    private $consumer_secret;
    private $business_shortcode;
    private $passkey;
    private $environment;
    private $callback_url;
    
    public function __construct() {
        // Get M-Pesa settings from database
        $this->consumer_key = getSystemSetting('mpesa_consumer_key', '');
        $this->consumer_secret = getSystemSetting('mpesa_consumer_secret', '');
        $this->business_shortcode = getSystemSetting('mpesa_shortcode', '');
        $this->passkey = getSystemSetting('mpesa_passkey', '');
        $this->environment = getSystemSetting('mpesa_environment', 'sandbox');
        $this->callback_url = SITE_URL . '/api/mpesa-callback.php';
    }
    
    /**
     * Get access token from M-Pesa API
     */
    private function getAccessToken() {
        $url = $this->environment === 'live' ? 
            'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' :
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
            
        $credentials = base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return $result['access_token'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Generate password for STK push
     */
    private function generatePassword() {
        $timestamp = date('YmdHis');
        $password = base64_encode($this->business_shortcode . $this->passkey . $timestamp);
        return ['password' => $password, 'timestamp' => $timestamp];
    }
    
    /**
     * Initiate STK Push payment
     */
    public function stkPush($phone_number, $amount, $account_reference, $transaction_desc) {
        $access_token = $this->getAccessToken();
        if (!$access_token) {
            return ['success' => false, 'message' => 'Failed to get M-Pesa access token'];
        }
        
        $url = $this->environment === 'live' ? 
            'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' :
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        
        $password_data = $this->generatePassword();
        
        // Ensure phone number is in correct format
        $phone_number = $this->formatPhoneNumber($phone_number);
        
        $curl_post_data = [
            'BusinessShortCode' => $this->business_shortcode,
            'Password' => $password_data['password'],
            'Timestamp' => $password_data['timestamp'],
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (string)$amount,
            'PartyA' => $phone_number,
            'PartyB' => $this->business_shortcode,
            'PhoneNumber' => $phone_number,
            'CallBackURL' => $this->callback_url,
            'AccountReference' => $account_reference,
            'TransactionDesc' => $transaction_desc
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($curl_post_data),
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            
            if ($result['ResponseCode'] === '0') {
                return [
                    'success' => true,
                    'checkout_request_id' => $result['CheckoutRequestID'],
                    'merchant_request_id' => $result['MerchantRequestID'],
                    'message' => $result['ResponseDescription']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $result['ResponseDescription'] ?? 'STK push failed'
                ];
            }
        }
        
        return ['success' => false, 'message' => 'M-Pesa API request failed'];
    }
    
    /**
     * Query STK Push transaction status
     */
    public function queryTransaction($checkout_request_id) {
        $access_token = $this->getAccessToken();
        if (!$access_token) {
            return ['success' => false, 'message' => 'Failed to get access token'];
        }
        
        $url = $this->environment === 'live' ? 
            'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query' :
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
        
        $password_data = $this->generatePassword();
        
        $curl_post_data = [
            'BusinessShortCode' => $this->business_shortcode,
            'Password' => $password_data['password'],
            'Timestamp' => $password_data['timestamp'],
            'CheckoutRequestID' => $checkout_request_id
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($curl_post_data),
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($curl);
        curl_close($curl);
        
        return json_decode($response, true);
    }
    
    /**
     * Format phone number to M-Pesa standard
     */
    private function formatPhoneNumber($phone) {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Convert to international format
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        } elseif (substr($phone, 0, 1) === '7' || substr($phone, 0, 1) === '1') {
            $phone = '254' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Validate M-Pesa configuration
     */
    public function validateConfiguration() {
        $errors = [];
        
        if (empty($this->consumer_key)) {
            $errors[] = 'Consumer Key is required';
        }
        
        if (empty($this->consumer_secret)) {
            $errors[] = 'Consumer Secret is required';
        }
        
        if (empty($this->business_shortcode)) {
            $errors[] = 'Business Shortcode is required';
        }
        
        if (empty($this->passkey)) {
            $errors[] = 'Passkey is required';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Test M-Pesa connection
     */
    public function testConnection() {
        try {
            $token = $this->getAccessToken();
            if ($token) {
                return ['success' => true, 'message' => 'Connection successful'];
            } else {
                return ['success' => false, 'message' => 'Failed to obtain access token'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }
}

/**
 * Helper function to initiate M-Pesa payment
 */
function initiateMpesaPayment($phone, $amount, $transaction_id, $description = 'Ultra Harvest Deposit') {
    try {
        $mpesa = new MpesaIntegration();
        
        // Validate configuration first
        $config_validation = $mpesa->validateConfiguration();
        if (!$config_validation['valid']) {
            return [
                'success' => false,
                'message' => 'M-Pesa not configured: ' . implode(', ', $config_validation['errors'])
            ];
        }
        
        $result = $mpesa->stkPush($phone, $amount, "UH$transaction_id", $description);
        
        if ($result['success']) {
            // Store the checkout request ID for later verification
            global $db;
            $stmt = $db->prepare("UPDATE transactions SET mpesa_request_id = ? WHERE id = ?");
            $stmt->execute([$result['checkout_request_id'], $transaction_id]);
            
            // Log the transaction attempt
            error_log("M-Pesa STK Push initiated: Transaction ID $transaction_id, Amount: $amount, Phone: $phone");
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("M-Pesa Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Payment system temporarily unavailable'
        ];
    }
}

/**
 * Process M-Pesa callback
 */
function processMpesaCallback($callback_data) {
    global $db;
    
    try {
        $result_code = $callback_data['Body']['stkCallback']['ResultCode'];
        $result_desc = $callback_data['Body']['stkCallback']['ResultDesc'];
        $checkout_request_id = $callback_data['Body']['stkCallback']['CheckoutRequestID'];
        
        // Find the transaction
        $stmt = $db->prepare("SELECT * FROM transactions WHERE mpesa_request_id = ?");
        $stmt->execute([$checkout_request_id]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            error_log("M-Pesa Callback: Transaction not found for CheckoutRequestID: $checkout_request_id");
            return false;
        }
        
        if ($result_code == 0) {
            // Payment successful
            $callback_metadata = $callback_data['Body']['stkCallback']['CallbackMetadata']['Item'];
            $mpesa_receipt = '';
            $transaction_date = '';
            $phone_number = '';
            
            foreach ($callback_metadata as $item) {
                if ($item['Name'] === 'MpesaReceiptNumber') {
                    $mpesa_receipt = $item['Value'];
                } elseif ($item['Name'] === 'TransactionDate') {
                    $transaction_date = $item['Value'];
                } elseif ($item['Name'] === 'PhoneNumber') {
                    $phone_number = $item['Value'];
                }
            }
            
            // Update transaction as completed
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                UPDATE transactions 
                SET status = 'completed', mpesa_receipt = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$mpesa_receipt, $transaction['id']]);
            
            // Credit user account for deposits
            if ($transaction['type'] === 'deposit') {
                $stmt = $db->prepare("
                    UPDATE users 
                    SET wallet_balance = wallet_balance + ?, total_deposited = total_deposited + ? 
                    WHERE id = ?
                ");
                $stmt->execute([$transaction['amount'], $transaction['amount'], $transaction['user_id']]);
                
                // Send success notification
                sendNotification(
                    $transaction['user_id'],
                    'Deposit Successful!',
                    formatMoney($transaction['amount']) . " has been credited to your account. Receipt: $mpesa_receipt",
                    'success'
                );
                
                // Process referral commission if user was referred
                $stmt = $db->prepare("SELECT referred_by FROM users WHERE id = ?");
                $stmt->execute([$transaction['user_id']]);
                $referrer = $stmt->fetch();
                
                if ($referrer && $referrer['referred_by']) {
                    $commission_rate = (float)getSystemSetting('referral_commission_l1', 10);
                    $commission = ($transaction['amount'] * $commission_rate) / 100;
                    
                    if ($commission > 0) {
                        // Credit referrer
                        $stmt = $db->prepare("UPDATE users SET referral_earnings = referral_earnings + ? WHERE id = ?");
                        $stmt->execute([$commission, $referrer['referred_by']]);
                        
                        // Create commission transaction
                        $stmt = $db->prepare("
                            INSERT INTO transactions (user_id, type, amount, status, description) 
                            VALUES (?, 'referral_commission', ?, 'completed', ?)
                        ");
                        $stmt->execute([
                            $referrer['referred_by'],
                            $commission,
                            "Referral commission from deposit (Receipt: $mpesa_receipt)"
                        ]);
                        
                        // Notify referrer
                        sendNotification(
                            $referrer['referred_by'],
                            'Referral Commission Earned!',
                            "You earned " . formatMoney($commission) . " commission from a referral deposit.",
                            'success'
                        );
                    }
                }
            }
            
            $db->commit();
            error_log("M-Pesa Payment Successful: Transaction ID {$transaction['id']}, Receipt: $mpesa_receipt");
            
        } else {
            // Payment failed
            $stmt = $db->prepare("
                UPDATE transactions 
                SET status = 'failed', description = CONCAT(description, ' - Failed: ', ?) 
                WHERE id = ?
            ");
            $stmt->execute([$result_desc, $transaction['id']]);
            
            // Send failure notification
            sendNotification(
                $transaction['user_id'],
                'Payment Failed',
                "Your payment of " . formatMoney($transaction['amount']) . " could not be processed. Reason: $result_desc",
                'error'
            );
            
            error_log("M-Pesa Payment Failed: Transaction ID {$transaction['id']}, Reason: $result_desc");
        }
        
        return true;
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("M-Pesa Callback Processing Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send money via M-Pesa B2C (for withdrawals)
 */
function sendMpesaMoney($phone, $amount, $remarks = 'Ultra Harvest Withdrawal') {
    // This would implement B2C API for withdrawals
    // For now, return success for manual processing
    return [
        'success' => true,
        'message' => 'Withdrawal request queued for manual processing',
        'transaction_id' => 'MANUAL_' . time()
    ];
}

/**
 * Get M-Pesa transaction statement
 */
function getMpesaStatement($start_date, $end_date) {
    // This would implement C2B Register URL and Transaction Status APIs
    // to get transaction statements for reconciliation
    return [
        'success' => true,
        'transactions' => [],
        'message' => 'Statement retrieval not yet implemented'
    ];
}
?>