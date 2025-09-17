<?php
require_once '../config/database.php';
require_once '../config/mpesa.php';
requireAdmin();

header('Content-Type: application/json');

if ($_POST && isset($_POST['test_mpesa'])) {
    try {
        // Get M-Pesa credentials from POST or database
        $consumer_key = $_POST['mpesa_consumer_key'] ?? getSystemSetting('mpesa_consumer_key', '');
        $consumer_secret = $_POST['mpesa_consumer_secret'] ?? getSystemSetting('mpesa_consumer_secret', '');
        
        if (empty($consumer_key) || empty($consumer_secret)) {
            echo json_encode([
                'success' => false,
                'message' => 'M-Pesa credentials are required for testing'
            ]);
            exit;
        }
        
        // Create temporary M-Pesa instance with provided credentials
        $mpesa = new MpesaIntegration();
        
        // Test the connection
        $result = $mpesa->testConnection();
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'M-Pesa connection successful! Your credentials are working correctly.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'M-Pesa connection failed: ' . $result['message']
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Connection test failed: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
?>
