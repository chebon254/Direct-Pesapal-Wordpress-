 <?php
/**
 * Plugin Name: Pesapal Donations
 * Description: Simple donation plugin using Pesapal payment gateway for Kenya
 * Version: 1.0.2
 * Author: Kelvin Chebon
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PESAPAL_DONATIONS_VERSION', '1.0.0');
define('PESAPAL_DONATIONS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PESAPAL_DONATIONS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Main plugin class
class PesapalDonations {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Add admin menu
        add_action('admin_menu', array($this, 'admin_menu'));
        
        // Add shortcodes
        add_shortcode('pesapal_donation_form', array($this, 'donation_form_shortcode'));
        add_shortcode('pesapal_donation_callback', array($this, 'donation_callback_shortcode'));
        
        // Handle AJAX requests
        add_action('wp_ajax_process_donation', array($this, 'process_donation'));
        add_action('wp_ajax_nopriv_process_donation', array($this, 'process_donation'));
        
        // Handle Pesapal callback and IPN
        add_action('wp_ajax_pesapal_callback', array($this, 'handle_callback'));
        add_action('wp_ajax_nopriv_pesapal_callback', array($this, 'handle_callback'));
        add_action('wp_ajax_pesapal_ipn', array($this, 'handle_ipn'));
        add_action('wp_ajax_nopriv_pesapal_ipn', array($this, 'handle_ipn'));
        
        // Admin AJAX handlers
        add_action('wp_ajax_check_payment_status', array($this, 'ajax_check_payment_status'));
        add_action('wp_ajax_get_donation_stats', array($this, 'ajax_get_donation_stats'));
        add_action('wp_ajax_export_donations', array($this, 'export_donations'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    public function activate() {
        $this->create_tables();
        $this->create_pages();
    }
    
    public function deactivate() {
        // Cleanup if needed
    }
    
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pesapal_donations';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            donor_name varchar(255) NOT NULL,
            donor_email varchar(255) NOT NULL,
            donor_phone varchar(20) NOT NULL,
            donor_id_number varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) DEFAULT 'KES',
            merchant_reference varchar(50) NOT NULL,
            order_tracking_id varchar(255) DEFAULT NULL,
            payment_status varchar(20) DEFAULT 'PENDING',
            confirmation_code varchar(255) DEFAULT NULL,
            payment_method varchar(50) DEFAULT NULL,
            created_date datetime DEFAULT CURRENT_TIMESTAMP,
            updated_date datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY merchant_reference (merchant_reference)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function create_pages() {
        // Create callback page
        $callback_page = array(
            'post_title'    => 'Donation Callback',
            'post_content'  => '[pesapal_donation_callback]',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_name'     => 'donation-callback'
        );
        
        if (!get_page_by_path('donation-callback')) {
            wp_insert_post($callback_page);
        }
    }
    
    public function admin_menu() {
        add_menu_page(
            'Pesapal Donations',
            'Donations',
            'manage_options',
            'pesapal-donations',
            array($this, 'admin_page'),
            'dashicons-heart',
            30
        );
        
        add_submenu_page(
            'pesapal-donations',
            'Settings',
            'Settings',
            'manage_options',
            'pesapal-donations-settings',
            array($this, 'settings_page')
        );
    }
    
    public function admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pesapal_donations';
        
        // Handle status filter
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $where_clause = '';
        if ($status_filter) {
            $where_clause = $wpdb->prepare(" WHERE payment_status = %s", $status_filter);
        }
        
        $donations = $wpdb->get_results("SELECT * FROM $table_name $where_clause ORDER BY created_date DESC");
        $total_amount = $wpdb->get_var("SELECT SUM(amount) FROM $table_name WHERE payment_status = 'COMPLETED'");
        $total_donations = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE payment_status = 'COMPLETED'");
        
        ?>
        <div class="wrap" id="pesapal-donations-page">
            <h1>Pesapal Donations</h1>
            
            <input type="hidden" id="pesapal_admin_nonce" value="<?php echo wp_create_nonce('pesapal_admin_nonce'); ?>" />
            
            <div class="notice notice-info">
                <p><strong>Total Donations:</strong> <?php echo number_format($total_donations); ?> | 
                   <strong>Total Amount:</strong> KES <?php echo number_format($total_amount, 2); ?></p>
            </div>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="status_filter" id="status_filter">
                        <option value="">All Statuses</option>
                        <option value="PENDING" <?php selected($status_filter, 'PENDING'); ?>>Pending</option>
                        <option value="COMPLETED" <?php selected($status_filter, 'COMPLETED'); ?>>Completed</option>
                        <option value="FAILED" <?php selected($status_filter, 'FAILED'); ?>>Failed</option>
                    </select>
                    <button type="button" class="button" onclick="filterDonations()">Filter</button>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Donor Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>ID Number</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Payment Method</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($donations): ?>
                        <?php foreach ($donations as $donation): ?>
                            <tr>
                                <td><?php echo $donation->id; ?></td>
                                <td><?php echo esc_html($donation->donor_name); ?></td>
                                <td><?php echo esc_html($donation->donor_email); ?></td>
                                <td><?php echo esc_html($donation->donor_phone); ?></td>
                                <td><?php echo esc_html($donation->donor_id_number); ?></td>
                                <td>KES <?php echo number_format($donation->amount, 2); ?></td>
                                <td>
                                    <span class="status-<?php echo strtolower($donation->payment_status); ?>">
                                        <?php echo $donation->payment_status; ?>
                                    </span>
                                </td>
                                <td><?php echo $donation->payment_method ?: 'N/A'; ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($donation->created_date)); ?></td>
                                <td>
                                    <?php if ($donation->payment_status === 'PENDING' && $donation->order_tracking_id): ?>
                                        <button type="button" class="button button-small" 
                                                onclick="checkPaymentStatus('<?php echo $donation->order_tracking_id; ?>', <?php echo $donation->id; ?>)">
                                            Check Status
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">No donations found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        A receipt has been sent to
        <script>
        function filterDonations() {
            const status = document.getElementById('status_filter').value;
            const url = new URL(window.location);
            if (status) {
                url.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
            }
            window.location = url;
        }
        
        function checkPaymentStatus(orderTrackingId, donationId) {
            if (confirm('Check payment status for this donation?')) {
                const data = new FormData();
                data.append('action', 'check_payment_status');
                data.append('order_tracking_id', orderTrackingId);
                data.append('donation_id', donationId);
                data.append('nonce', '<?php echo wp_create_nonce('pesapal_admin_nonce'); ?>');
                
                fetch(ajaxurl, {
                    method: 'POST',
                    body: data
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Status updated successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.data || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Network error: ' + error.message);
                });
            }
        }
        </script>
        
        <style>
        .status-completed { color: green; font-weight: bold; }
        .status-pending { color: orange; font-weight: bold; }
        .status-failed { color: red; font-weight: bold; }
        </style>
        <?php
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            update_option('pesapal_consumer_key', sanitize_text_field($_POST['consumer_key']));
            update_option('pesapal_consumer_secret', sanitize_text_field($_POST['consumer_secret']));
            update_option('pesapal_environment', sanitize_text_field($_POST['environment']));
            update_option('pesapal_ipn_url', sanitize_text_field($_POST['ipn_url']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $consumer_key = get_option('pesapal_consumer_key', '');
        $consumer_secret = get_option('pesapal_consumer_secret', '');
        $environment = get_option('pesapal_environment', 'sandbox');
        $ipn_url = get_option('pesapal_ipn_url', '');
        
        ?>
        <div class="wrap">
            <h1>Pesapal Settings</h1>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Environment</th>
                        <td>
                            <select name="environment">
                                <option value="sandbox" <?php selected($environment, 'sandbox'); ?>>Sandbox</option>
                                <option value="live" <?php selected($environment, 'live'); ?>>Live</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Consumer Key</th>
                        <td><input type="text" name="consumer_key" value="<?php echo esc_attr($consumer_key); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row">Consumer Secret</th>
                        <td><input type="text" name="consumer_secret" value="<?php echo esc_attr($consumer_secret); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row">IPN URL</th>
                        <td>
                            <input type="url" name="ipn_url" value="<?php echo esc_attr($ipn_url); ?>" class="regular-text" />
                            <p class="description">Your IPN endpoint URL (leave empty to auto-register)</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Shortcode Usage</h2>
            <p>Use this shortcode to display the donation form:</p>
            <code>[pesapal_donation_form]</code>
            
            <h2>IPN URL</h2>
            <p>Use this URL for Pesapal IPN notifications:</p>
            <code><?php echo admin_url('admin-ajax.php?action=pesapal_ipn'); ?></code>
            
            <h2>Callback URL</h2>
            <p>Use this URL for Pesapal callback:</p>
            <code><?php echo home_url('/donation-callback/'); ?></code>
        </div>
        <?php
    }
    
    public function donation_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'min_amount' => 100,
            'currency' => 'KES'
        ), $atts);
        
        ob_start();
        ?>
        <div id="pesapal-donation-form">
            <form id="donation-form" class="pesapal-form">
                <div class="form-group">
                    <label for="donor_name">Full Name *</label>
                    <input type="text" id="donor_name" name="donor_name" required />
                </div>
                
                <div class="form-group">
                    <label for="donor_email">Email Address *</label>
                    <input type="email" id="donor_email" name="donor_email" required />
                </div>
                
                <div class="form-group">
                    <label for="donor_phone">Phone Number *</label>
                    <input type="tel" id="donor_phone" name="donor_phone" placeholder="0712345678" required />
                </div>
                
                <div class="form-group">
                    <label for="donor_id_number">ID Number *</label>
                    <input type="text" id="donor_id_number" name="donor_id_number" required />
                </div>
                
                <div class="form-group">
                    <label for="amount">Donation Amount (<?php echo $atts['currency']; ?>) *</label>
                    <input type="number" id="amount" name="amount" min="<?php echo $atts['min_amount']; ?>" step="0.01" required />
                </div>
                
                <div class="form-group">
                    <button type="submit" class="pesapal-btn">Donate Now</button>
                </div>
                
                <div id="donation-message" class="message" style="display:none;"></div>
            </form>
        </div>
        
        <style>
        .pesapal-form {
            max-width: 500px;
            margin: 20px 0;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .pesapal-btn {
            background-color: #0073aa;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
        }
        .pesapal-btn:hover {
            background-color: #005a87;
        }
        .pesapal-btn:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    public function donation_callback_shortcode($atts) {
        // Get URL parameters
        $donation_id = isset($_GET['donation_id']) ? intval($_GET['donation_id']) : 0;
        $order_tracking_id = isset($_GET['OrderTrackingId']) ? sanitize_text_field($_GET['OrderTrackingId']) : '';
        $merchant_reference = isset($_GET['OrderMerchantReference']) ? sanitize_text_field($_GET['OrderMerchantReference']) : '';
        $notification_type = isset($_GET['OrderNotificationType']) ? sanitize_text_field($_GET['OrderNotificationType']) : '';
        
        // Update payment status if we have tracking ID
        if ($order_tracking_id) {
            $this->update_payment_status($order_tracking_id);
        }
        
        // Get donation details from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'pesapal_donations';
        $donation = null;
        
        if ($donation_id) {
            $donation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $donation_id
            ));
        } elseif ($merchant_reference) {
            $donation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE merchant_reference = %s",
                $merchant_reference
            ));
        }
        
        ob_start();
        ?>
        <div class="pesapal-callback-container">
            <?php if ($donation): ?>
                <div class="callback-content">
                    <?php 
                    $status = strtoupper(trim($donation->payment_status));
                    
                    // Temporary debug - remove this after checking
                    if (current_user_can('manage_options')) {
                        echo "<!-- Debug Info: Raw Status = '" . $donation->payment_status . "', Cleaned Status = '" . $status . "' -->";
                    }
                    
                    if (in_array($status, ['COMPLETED', 'COMPLETE', 'SUCCESS', 'SUCCESSFUL'])): 
                    ?>
                        <div class="success-message">
                            <div class="success-icon">✅</div>
                            <h2>Thank You for Your Donation!</h2>
                            <p>Your donation has been successfully processed.</p>
                            
                            <div class="donation-details">
                                <h3>Donation Details:</h3>
                                <div class="detail-row">
                                    <span class="label">Amount:</span>
                                    <span class="value">KES <?php echo number_format($donation->amount, 2); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="label">Reference:</span>
                                    <span class="value"><?php echo esc_html($donation->merchant_reference); ?></span>
                                </div>
                                <?php if ($donation->confirmation_code): ?>
                                <div class="detail-row">
                                    <span class="label">Confirmation Code:</span>
                                    <span class="value"><?php echo esc_html($donation->confirmation_code); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($donation->payment_method): ?>
                                <div class="detail-row">
                                    <span class="label">Payment Method:</span>
                                    <span class="value"><?php echo esc_html($donation->payment_method); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="detail-row">
                                    <span class="label">Date:</span>
                                    <span class="value"><?php echo date('F j, Y \a\t g:i A', strtotime($donation->created_date)); ?></span>
                                </div>
                            </div>
                            
                            <p class="receipt-note" style="display: none;">
                                📧 A receipt has been sent to <strong><?php echo esc_html($donation->donor_email); ?></strong>
                            </p>
                        </div>
                        
                    <?php elseif (in_array($status, ['FAILED', 'FAIL', 'DECLINED', 'REJECTED', 'ERROR'])): ?>
                        <div class="error-message">
                            <div class="error-icon">❌</div>
                            <h2>Payment Failed</h2>
                            <p>Unfortunately, your payment could not be processed.</p>
                            
                            <div class="donation-details">
                                <div class="detail-row">
                                    <span class="label">Reference:</span>
                                    <span class="value"><?php echo esc_html($donation->merchant_reference); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="label">Amount:</span>
                                    <span class="value">KES <?php echo number_format($donation->amount, 2); ?></span>
                                </div>
                            </div>
                            
                            <div class="retry-section">
                                <p>You can try again or contact us for assistance.</p>
                                <a href="<?php echo home_url('/donate/'); ?>" class="retry-button">Try Again</a>
                            </div>
                        </div>
                        
                    <?php else: // PENDING or other status ?>
                        <div class="pending-message">
                            <div class="pending-icon">⏳</div>
                            <h2>Payment Being Processed</h2>
                            <p>Your payment is currently being processed. Please wait...</p>
                            
                            <div class="donation-details">
                                <div class="detail-row">
                                    <span class="label">Reference:</span>
                                    <span class="value"><?php echo esc_html($donation->merchant_reference); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="label">Amount:</span>
                                    <span class="value">KES <?php echo number_format($donation->amount, 2); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="label">Status:</span>
                                    <span class="value"><?php echo esc_html($donation->payment_status); ?></span>
                                </div>
                            </div>
                            
                            <div class="auto-refresh">
                                <p>This page will automatically refresh in <span id="countdown">30</span> seconds to check for updates.</p>
                                <button type="button" id="refresh-status" class="refresh-button">Check Now</button>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="navigation-links">
                        <a href="<?php echo home_url(); ?>" class="home-button">← Back to Home</a>
                        <?php if (!in_array($status, ['COMPLETED', 'COMPLETE', 'SUCCESS', 'SUCCESSFUL'])): ?>
                            <a href="<?php echo home_url('/donate/'); ?>" class="donate-button">Make Another Donation</a>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="error-message">
                    <div class="error-icon">⚠️</div>
                    <h2>Donation Not Found</h2>
                    <p>We couldn't find the donation details. Please contact support if you believe this is an error.</p>
                    <a href="<?php echo home_url(); ?>" class="home-button">← Back to Home</a>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .pesapal-callback-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 20px;
            font-family: Arial, sans-serif;
        }
        
        .callback-content {
            text-align: center;
        }
        
        .success-message, .error-message, .pending-message {
            background: #fff;
            border-radius: 10px;
            padding: 40px 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .success-message {
            border-left: 5px solid #46b450;
        }
        
        .error-message {
            border-left: 5px solid #dc3232;
        }
        
        .pending-message {
            border-left: 5px solid #ffb900;
        }
        
        .success-icon, .error-icon, .pending-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .callback-content h2 {
            margin: 20px 0;
            color: #333;
        }
        
        .donation-details {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
        }
        
        .donation-details h3 {
            margin: 0 0 15px 0;
            color: #333;
            text-align: center;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
        }
        
        .detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .label {
            font-weight: bold;
            color: #666;
        }
        
        .value {
            color: #333;
        }
        
        .receipt-note {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            font-size: 14px;
        }
        
        .navigation-links {
            margin-top: 30px;
        }
        
        .home-button, .retry-button, .refresh-button, .donate-button {
            display: inline-block;
            padding: 12px 24px;
            margin: 0 10px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        
        .home-button {
            background-color: #0073aa;
            color: white;
        }
        
        .home-button:hover {
            background-color: #005a87;
        }
        
        .retry-button, .refresh-button {
            background-color: #dc3232;
            color: white;
            border: none;
            cursor: pointer;
        }
        
        .retry-button:hover, .refresh-button:hover {
            background-color: #a00;
        }
        
        .donate-button {
            background-color: #46b450;
            color: white;
        }
        
        .donate-button:hover {
            background-color: #2e7d32;
        }
        
        .auto-refresh {
            background: #fff3cd;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        #countdown {
            font-weight: bold;
            color: #856404;
        }
        
        @media (max-width: 600px) {
            .pesapal-callback-container {
                margin: 20px;
                padding: 10px;
            }
            
            .success-message, .error-message, .pending-message {
                padding: 30px 20px;
            }
            
            .navigation-links a {
                display: block;
                margin: 10px 0;
            }
        }
        </style>
        
        <?php if ($donation && in_array($status, ['PENDING', 'PROCESSING', 'INITIATED', 'SUBMITTED'])): ?>
        <script>
        // Auto-refresh for pending payments
        let countdown = 30;
        const countdownElement = document.getElementById('countdown');
        const refreshButton = document.getElementById('refresh-status');
        
        function updateCountdown() {
            countdown--;
            if (countdownElement) {
                countdownElement.textContent = countdown;
            }
            
            if (countdown <= 0) {
                window.location.reload();
            }
        }
        
        // Update countdown every second
        const countdownInterval = setInterval(updateCountdown, 1000);
        
        // Manual refresh button
        if (refreshButton) {
            refreshButton.addEventListener('click', function() {
                clearInterval(countdownInterval);
                window.location.reload();
            });
        }
        </script>
        <?php endif; ?>
        
        <?php
        return ob_get_clean();
    }
    
    public function process_donation() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pesapal_donation_nonce')) {
            wp_die('Security check failed');
        }
        
        // Sanitize input
        $donor_name = sanitize_text_field($_POST['donor_name']);
        $donor_email = sanitize_email($_POST['donor_email']);
        $donor_phone = sanitize_text_field($_POST['donor_phone']);
        $donor_id_number = sanitize_text_field($_POST['donor_id_number']);
        $amount = floatval($_POST['amount']);
        
        // Validate input
        if (empty($donor_name) || empty($donor_email) || empty($donor_phone) || empty($donor_id_number) || $amount <= 0) {
            wp_send_json_error('All fields are required and amount must be greater than 0');
        }
        
        // Generate unique merchant reference
        $merchant_reference = 'DON_' . time() . '_' . wp_rand(1000, 9999);
        
        // Save donation to database
        global $wpdb;
        $table_name = $wpdb->prefix . 'pesapal_donations';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'donor_name' => $donor_name,
                'donor_email' => $donor_email,
                'donor_phone' => $donor_phone,
                'donor_id_number' => $donor_id_number,
                'amount' => $amount,
                'merchant_reference' => $merchant_reference,
                'payment_status' => 'PENDING'
            ),
            array('%s', '%s', '%s', '%s', '%f', '%s', '%s')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to save donation');
        }
        
        $donation_id = $wpdb->insert_id;
        
        // Process with Pesapal
        $pesapal_response = $this->submit_order_to_pesapal($donation_id, $merchant_reference, $amount, $donor_name, $donor_email, $donor_phone);
        
        if ($pesapal_response && isset($pesapal_response['redirect_url'])) {
            // Update donation with order tracking ID
            $wpdb->update(
                $table_name,
                array('order_tracking_id' => $pesapal_response['order_tracking_id']),
                array('id' => $donation_id),
                array('%s'),
                array('%d')
            );
            
            wp_send_json_success(array(
                'redirect_url' => $pesapal_response['redirect_url'],
                'message' => 'Redirecting to payment...'
            ));
        } else {
            wp_send_json_error('Failed to initialize payment. Please try again.');
        }
    }
    
    private function submit_order_to_pesapal($donation_id, $merchant_reference, $amount, $donor_name, $donor_email, $donor_phone) {
        // Get Pesapal access token
        $token = $this->get_pesapal_token();
        if (!$token) {
            return false;
        }
        
        // Get or register IPN URL
        $notification_id = $this->get_notification_id($token);
        if (!$notification_id) {
            return false;
        }
        
        $environment = get_option('pesapal_environment', 'sandbox');
        $base_url = ($environment === 'live') 
            ? 'https://pay.pesapal.com/v3' 
            : 'https://cybqa.pesapal.com/pesapalv3';
        
        $callback_url = home_url('/donation-callback/') . '?donation_id=' . $donation_id;
        
        $order_data = array(
            'id' => $merchant_reference,
            'currency' => 'KES',
            'amount' => $amount,
            'description' => 'Donation from ' . $donor_name,
            'callback_url' => $callback_url,
            'notification_id' => $notification_id,
            'billing_address' => array(
                'email_address' => $donor_email,
                'phone_number' => $donor_phone,
                'country_code' => 'KE',
                'first_name' => explode(' ', $donor_name)[0],
                'last_name' => isset(explode(' ', $donor_name)[1]) ? explode(' ', $donor_name)[1] : '',
                'line_1' => 'Online Donation',
                'city' => 'Nairobi',
                'state' => 'Nairobi',
                'postal_code' => '00100',
                'zip_code' => '00100'
            )
        );
        
        $response = wp_remote_post($base_url . '/api/Transactions/SubmitOrderRequest', array(
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body' => json_encode($order_data),
            'timeout' => 45
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['redirect_url'])) {
            return $data;
        }
        
        return false;
    }
    
    private function get_pesapal_token() {
        $consumer_key = get_option('pesapal_consumer_key');
        $consumer_secret = get_option('pesapal_consumer_secret');
        $environment = get_option('pesapal_environment', 'sandbox');
        
        if (empty($consumer_key) || empty($consumer_secret)) {
            return false;
        }
        
        $base_url = ($environment === 'live') 
            ? 'https://pay.pesapal.com/v3' 
            : 'https://cybqa.pesapal.com/pesapalv3';
        
        $auth_data = array(
            'consumer_key' => $consumer_key,
            'consumer_secret' => $consumer_secret
        );
        
        $response = wp_remote_post($base_url . '/api/Auth/RequestToken', array(
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($auth_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['token'])) {
            return $data['token'];
        }
        
        return false;
    }
    
    private function get_notification_id($token) {
        $environment = get_option('pesapal_environment', 'sandbox');
        $base_url = ($environment === 'live') 
            ? 'https://pay.pesapal.com/v3' 
            : 'https://cybqa.pesapal.com/pesapalv3';
        
        $ipn_url = admin_url('admin-ajax.php?action=pesapal_ipn');
        
        $ipn_data = array(
            'url' => $ipn_url,
            'ipn_notification_type' => 'GET'
        );
        
        $response = wp_remote_post($base_url . '/api/URLSetup/RegisterIPN', array(
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body' => json_encode($ipn_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['ipn_id'])) {
            return $data['ipn_id'];
        }
        
        return false;
    }
    
    public function handle_callback() {
        $order_tracking_id = sanitize_text_field($_GET['OrderTrackingId'] ?? '');
        $merchant_reference = sanitize_text_field($_GET['OrderMerchantReference'] ?? '');
        
        if ($order_tracking_id) {
            $this->update_payment_status($order_tracking_id);
        }
        
        // Redirect to a thank you page or show status
        wp_redirect(home_url('/?donation_status=processing'));
        exit;
    }
    
    public function handle_ipn() {
        $order_tracking_id = sanitize_text_field($_GET['OrderTrackingId'] ?? '');
        
        if ($order_tracking_id) {
            $this->update_payment_status($order_tracking_id);
        }
        
        // Respond to Pesapal
        wp_send_json(array(
            'orderNotificationType' => 'IPNCHANGE',
            'orderTrackingId' => $order_tracking_id,
            'orderMerchantReference' => sanitize_text_field($_GET['OrderMerchantReference'] ?? ''),
            'status' => 200
        ));
    }
    
    private function update_payment_status($order_tracking_id) {
        $token = $this->get_pesapal_token();
        if (!$token) {
            return false;
        }
        
        $environment = get_option('pesapal_environment', 'sandbox');
        $base_url = ($environment === 'live') 
            ? 'https://pay.pesapal.com/v3' 
            : 'https://cybqa.pesapal.com/pesapalv3';
        
        $response = wp_remote_get($base_url . '/api/Transactions/GetTransactionStatus?orderTrackingId=' . $order_tracking_id, array(
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['payment_status_description'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'pesapal_donations';
            
            $status = $data['payment_status_description'];
            $payment_method = $data['payment_method'] ?? '';
            $confirmation_code = $data['confirmation_code'] ?? '';
            
            $wpdb->update(
                $table_name,
                array(
                    'payment_status' => $status,
                    'payment_method' => $payment_method,
                    'confirmation_code' => $confirmation_code
                ),
                array('order_tracking_id' => $order_tracking_id),
                array('%s', '%s', '%s'),
                array('%s')
            );
            
            return true;
        }
        
        return false;
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('pesapal-donations', PESAPAL_DONATIONS_PLUGIN_URL . 'assets/donations.js', array('jquery'), PESAPAL_DONATIONS_VERSION, true);
        wp_localize_script('pesapal-donations', 'pesapal_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pesapal_donation_nonce')
        ));
    }
    
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'pesapal-donations') !== false) {
            wp_enqueue_script('pesapal-admin', PESAPAL_DONATIONS_PLUGIN_URL . 'assets/admin.js', array('jquery'), PESAPAL_DONATIONS_VERSION, true);
        }
    }
    
    // Admin AJAX handlers
    public function ajax_check_payment_status() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pesapal_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $order_tracking_id = sanitize_text_field($_POST['order_tracking_id']);
        $donation_id = intval($_POST['donation_id']);
        
        if (empty($order_tracking_id) || empty($donation_id)) {
            wp_send_json_error('Missing required parameters');
        }
        
        $result = $this->update_payment_status($order_tracking_id);
        
        if ($result) {
            wp_send_json_success('Payment status updated successfully');
        } else {
            wp_send_json_error('Failed to update payment status');
        }
    }
    
    public function ajax_get_donation_stats() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'pesapal_donations';
        
        $stats = array();
        $stats['total_donations'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE payment_status = 'COMPLETED'");
        $stats['total_amount'] = number_format($wpdb->get_var("SELECT SUM(amount) FROM $table_name WHERE payment_status = 'COMPLETED'"), 2);
        $stats['completed'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE payment_status = 'COMPLETED'");
        $stats['pending'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE payment_status = 'PENDING'");
        $stats['failed'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE payment_status = 'FAILED'");
        
        // This month's donations
        $start_of_month = date('Y-m-01 00:00:00');
        $stats['this_month'] = number_format($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM $table_name WHERE payment_status = 'COMPLETED' AND created_date >= %s",
            $start_of_month
        )), 2);
        
        wp_send_json_success($stats);
    }
    
    public function export_donations() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'pesapal_donations';
        
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $where_clause = '';
        if ($status_filter) {
            $where_clause = $wpdb->prepare(" WHERE payment_status = %s", $status_filter);
        }
        
        $donations = $wpdb->get_results("SELECT * FROM $table_name $where_clause ORDER BY created_date DESC");
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="donations_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($output, array(
            'ID', 'Donor Name', 'Email', 'Phone', 'ID Number', 'Amount', 'Currency',
            'Status', 'Payment Method', 'Confirmation Code', 'Created Date'
        ));
        
        // Add data rows
        foreach ($donations as $donation) {
            fputcsv($output, array(
                $donation->id,
                $donation->donor_name,
                $donation->donor_email,
                $donation->donor_phone,
                $donation->donor_id_number,
                $donation->amount,
                $donation->currency,
                $donation->payment_status,
                $donation->payment_method,
                $donation->confirmation_code,
                $donation->created_date
            ));
        }
        
        fclose($output);
        exit;
    }
}

// Initialize the plugin
new PesapalDonations();