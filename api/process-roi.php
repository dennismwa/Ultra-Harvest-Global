<?php
/**
 * ROI Processing Script
 * This script should be run via cron job every hour to process matured packages
 * Cron: 0 * * * * /usr/bin/php /path/to/your/api/process-roi.php
 */

require_once '../config/database.php';

// Check if auto ROI processing is enabled
if (getSystemSetting('auto_roi_processing', '1') != '1') {
    echo "Auto ROI processing is disabled.\n";
    exit;
}

try {
    // Get all matured packages
    $stmt = $db->prepare("
        SELECT ap.*, u.email, u.full_name, p.name as package_name
        FROM active_packages ap
        JOIN users u ON ap.user_id = u.id
        JOIN packages p ON ap.package_id = p.id
        WHERE ap.status = 'active' 
        AND ap.maturity_date <= NOW()
        ORDER BY ap.maturity_date ASC
    ");
    $stmt->execute();
    $matured_packages = $stmt->fetchAll();

    $processed_count = 0;
    $total_roi_paid = 0;

    foreach ($matured_packages as $package) {
        $db->beginTransaction();
        
        try {
            $user_id = $package['user_id'];
            $package_id = $package['id'];
            $investment_amount = $package['investment_amount'];
            $roi_amount = $package['expected_roi'];
            $total_return = $investment_amount + $roi_amount;

            // Update user wallet balance (return investment + ROI)
            $stmt = $db->prepare("
                UPDATE users 
                SET wallet_balance = wallet_balance + ?,
                    total_roi_earned = total_roi_earned + ?
                WHERE id = ?
            ");
            $stmt->execute([$total_return, $roi_amount, $user_id]);

            // Create ROI payment transaction
            $stmt = $db->prepare("
                INSERT INTO transactions (user_id, type, amount, status, description) 
                VALUES (?, 'roi_payment', ?, 'completed', ?)
            ");
            $description = "ROI payment for {$package['package_name']} package (Investment: " . formatMoney($investment_amount) . ")";
            $stmt->execute([$user_id, $total_return, $description]);

            // Mark package as completed
            $stmt = $db->prepare("
                UPDATE active_packages 
                SET status = 'completed', completed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$package_id]);

            // Send notification to user
            sendNotification(
                $user_id,
                'Package Completed!',
                "Your {$package['package_name']} package has matured. " . formatMoney($total_return) . " has been credited to your wallet.",
                'success'
            );

            // Process referral commissions if user was referred
            $stmt = $db->prepare("SELECT referred_by FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $referrer = $stmt->fetch();

            if ($referrer && $referrer['referred_by']) {
                processReferralCommissions($user_id, $referrer['referred_by'], $roi_amount, $db);
            }

            $db->commit();
            $processed_count++;
            $total_roi_paid += $roi_amount;

            echo "Processed package ID {$package_id} for user {$package['full_name']} - ROI: " . formatMoney($total_return) . "\n";

        } catch (Exception $e) {
            $db->rollBack();
            echo "Error processing package ID {$package_id}: " . $e->getMessage() . "\n";
            
            // Log error for admin review
            error_log("ROI Processing Error - Package ID {$package_id}: " . $e->getMessage());
        }
    }

    // Log system health after processing
    logSystemHealth();

    echo "ROI Processing completed. Processed: {$processed_count} packages, Total ROI paid: " . formatMoney($total_roi_paid) . "\n";

} catch (Exception $e) {
    echo "Fatal error in ROI processing: " . $e->getMessage() . "\n";
    error_log("Fatal ROI Processing Error: " . $e->getMessage());
}

/**
 * Process referral commissions for ROI payments
 */
function processReferralCommissions($user_id, $referrer_id, $roi_amount, $db) {
    try {
        // Level 1 commission (direct referrer)
        $l1_rate = (float)getSystemSetting('referral_commission_l1', 10);
        $l1_commission = ($roi_amount * $l1_rate) / 100;

        if ($l1_commission > 0) {
            // Credit referrer
            $stmt = $db->prepare("UPDATE users SET referral_earnings = referral_earnings + ? WHERE id = ?");
            $stmt->execute([$l1_commission, $referrer_id]);

            // Create commission transaction
            $stmt = $db->prepare("
                INSERT INTO transactions (user_id, type, amount, status, description) 
                VALUES (?, 'referral_commission', ?, 'completed', ?)
            ");
            $stmt->execute([
                $referrer_id,
                $l1_commission,
                "Level 1 referral commission from ROI payment"
            ]);

            // Send notification
            sendNotification(
                $referrer_id,
                'Referral Commission Earned!',
                "You earned " . formatMoney($l1_commission) . " commission from your referral's ROI payment.",
                'success'
            );

            // Check for Level 2 referrer
            $stmt = $db->prepare("SELECT referred_by FROM users WHERE id = ?");
            $stmt->execute([$referrer_id]);
            $l2_referrer = $stmt->fetch();

            if ($l2_referrer && $l2_referrer['referred_by']) {
                $l2_rate = (float)getSystemSetting('referral_commission_l2', 5);
                $l2_commission = ($roi_amount * $l2_rate) / 100;

                if ($l2_commission > 0) {
                    // Credit L2 referrer
                    $stmt = $db->prepare("UPDATE users SET referral_earnings = referral_earnings + ? WHERE id = ?");
                    $stmt->execute([$l2_commission, $l2_referrer['referred_by']]);

                    // Create L2 commission transaction
                    $stmt = $db->prepare("
                        INSERT INTO transactions (user_id, type, amount, status, description) 
                        VALUES (?, 'referral_commission', ?, 'completed', ?)
                    ");
                    $stmt->execute([
                        $l2_referrer['referred_by'],
                        $l2_commission,
                        "Level 2 referral commission from ROI payment"
                    ]);

                    // Send L2 notification
                    sendNotification(
                        $l2_referrer['referred_by'],
                        'L2 Referral Commission!',
                        "You earned " . formatMoney($l2_commission) . " L2 commission from a referral's ROI.",
                        'success'
                    );
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error processing referral commissions: " . $e->getMessage());
    }
}
?>