<?php
/*
Plugin Name: Patient Documents
Description: Patient management system with document templates
Version: 3.9.1
Author: Ayoub FATIHI
*/

if (!defined('ABSPATH')) exit;

// Add near the top of the file after plugin header
if (!defined('PD_DEBUG')) {
    define('PD_DEBUG', WP_DEBUG);
}

function pd_log($message, $level = 'error') {
    if (PD_DEBUG) {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        error_log("Patient Documents ($level): $message");
    }
}

// Define constants
define('PD_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PD_PLUGIN_URL', plugin_dir_url(__FILE__));

// path for shortcode for documents
require_once PD_PLUGIN_PATH . 'includes/shortcodes/generate-documents.php';

// Near the top with other requires
require_once PD_PLUGIN_PATH . 'includes/shortcodes/patient-profile.php';

// Add this near the top of the file with other requires
require_once PD_PLUGIN_PATH . 'includes/shortcodes/receptionist-dashboard.php';

// Activation/Deactivation hooks
register_activation_hook(__FILE__, 'pd_create_tables');
register_deactivation_hook(__FILE__, 'pd_deactivation_cleanup');

function pd_create_tables() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // This would contain the SQL from step 2
    $sql_file = PD_PLUGIN_PATH . 'sql/create-tables.sql';
    dbDelta(file_get_contents($sql_file));
}

function pd_deactivation_cleanup() {
    // Clear any transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pd_%'");
    
    // Clear scheduled events
    wp_clear_scheduled_hook('pd_daily_cleanup');
    
    // Log deactivation
    error_log('Patient Documents plugin deactivated');
}

// Add this to the pd_frontend_scripts function
function pd_frontend_scripts() {
    // Check if the current page is 'encounters', 'patient-profile', or if 'patient_id' is set in the query string
    if (is_page('encounters') || is_page('patient-profile') || (isset($_GET['patient_id']) && !empty($_GET['patient_id']))) {
        // Styles
        wp_enqueue_style('dashicons');
        wp_enqueue_style('pd-encounter-list', PD_PLUGIN_URL . 'assets/css/encounter-list.css', [], filemtime(PD_PLUGIN_PATH . 'assets/css/encounter-list.css'));

        // Core WP editors
        wp_enqueue_editor();
        wp_enqueue_media();

        // TinyMCE and your script
        wp_enqueue_script('tinymce');
        wp_enqueue_script(
            'pd-encounter-list',
            PD_PLUGIN_URL . 'assets/js/encounter-list.js',
            ['jquery', 'tinymce'],
            filemtime(PD_PLUGIN_PATH . 'assets/js/encounter-list.js'),
            true
        );

        // Localize script
        wp_localize_script('pd-encounter-list', 'pdEncounterData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pd-nonce'), // Ensure this nonce is used consistently
            'printCss' => PD_PLUGIN_URL . 'assets/css/print.css'
        ]);
    }
}
add_action('wp_enqueue_scripts', 'pd_frontend_scripts');


// Enqueue assets
add_action('wp_enqueue_scripts', 'pd_enqueue_assets');
// Update enqueue function to include WordPress editor dependencies
function pd_enqueue_assets() {
    // Don't load script.js on generate documents page
    if (!is_page('generate-documents') && !has_shortcode(get_post()->post_content, 'generate_documents')) {
        // Always enqueue base styles
        wp_enqueue_style('pd-style', PD_PLUGIN_URL . 'assets/css/style.css');
        wp_enqueue_style('dashicons');  // Ensure dashicons are always loaded
        
        // Load scripts if user has permission
        if (pd_can_view_buttons()) {
            wp_enqueue_script('pd-script', 
                PD_PLUGIN_URL . 'assets/js/script.js', 
                ['jquery', 'wp-editor', 'wp-data'], 
                filemtime(PD_PLUGIN_PATH . 'assets/js/script.js'), 
                true
            );
            
            wp_localize_script('pd-script', 'pdData', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pd-nonce'),
                'userCaps' => array(
                    'viewButtons' => true,
                    'managePatients' => current_user_can('pd_manage_patients'),
                    'manageEncounters' => current_user_can('pd_manage_encounters')
                )
            ));
        }
    }
}

// Verify patient-doctor relationship
add_filter('pd_verify_patient_access', 'pd_check_patient_relationship', 10, 2);
// Verify patient-doctor relationship
add_filter('pd_verify_patient_access', 'pd_check_patient_relationship', 10, 2);
function pd_check_patient_relationship($allowed, $patient_id) {
    $current_user = wp_get_current_user();
    
    // If user is administrator, always allow
    if(current_user_can('manage_options')) return true;
    
    // Check if patient belongs to current doctor's clinic
    $doctor_patients = get_transient('doctor_patients_' . $current_user->ID);
    
    if(!$doctor_patients) {
        global $wpdb;
        $doctor_patients = $wpdb->get_col($wpdb->prepare(
            "SELECT patient_id FROM {$wpdb->prefix}kc_appointments WHERE doctor_id = %d",
            $current_user->ID
        ));
        set_transient('doctor_patients_' . $current_user->ID, $doctor_patients, HOUR_IN_SECONDS);
    }
    
    return in_array($patient_id, $doctor_patients);
}

// Add admin menu
// Update admin menu registration with correct capabilities
add_action('admin_menu', 'pd_add_admin_menu');
function pd_add_admin_menu() {
    add_menu_page(
        'Patient Encounters',
        'Encounters',
        'manage_options', // Only users with manage_options capability can access
        'patient-encounters',
        'pd_render_encounters_page',
        'dashicons-list-view',
        6
    );
    
    // Add hidden subpages for actions
    add_submenu_page(
        null, // Hide from menu
        'Edit Patient',
        'Edit Patient',
        'manage_options',
        'patient-edit',
        'pd_render_edit_page'
    );
    
    add_submenu_page(
        null,
        'View Patient',
        'View Patient',
        'manage_options',
        'patient-view',
        'pd_render_view_page'
    );
    
    add_submenu_page(
        null,
        'Bill Details',
        'Bill Details',
        'manage_options',
        'bill-details',
        'pd_render_bill_page'
    );
    
    add_submenu_page(
        null,
        'Patient View',
        'Patient View',
        'manage_options',
        'patient-view',
        'pd_render_patient_view_page'
    );
}

// Stub functions for action pages
function pd_render_edit_page() {
    if(!current_user_can('manage_options')) wp_die('Access denied');
    include PD_PLUGIN_PATH . 'includes/templates/admin-edit-patient.php';
}

function pd_render_view_page() {
    if(!current_user_can('manage_options')) wp_die('Access denied');
    include PD_PLUGIN_PATH . 'includes/templates/admin-view-patient.php';
}

function pd_render_bill_page() {
    if(!current_user_can('manage_options')) wp_die('Access denied');
    include PD_PLUGIN_PATH . 'includes/templates/admin-bill-details.php';
}

function pd_render_patient_view_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }
    
    $patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
    $encounter_id = isset($_GET['encounter_id']) ? intval($_GET['encounter_id']) : 0;
    
    if (!$patient_id || !$encounter_id) {
        wp_die('Invalid parameters');
    }
    
    $db = PD_Database::get_instance();
    $patient = $db->get_patient_details($patient_id);
    $encounter = $db->get_full_encounter_details($encounter_id);
    
    // Add debug logging
    if(defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Loading prescriptions for encounter: ' . $encounter_id);
    }
    
    $prescriptions = $db->get_encounter_prescriptions($encounter_id);
    
    // Add debug logging
    if(defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Found prescriptions: ' . print_r($prescriptions, true));
    }
    
    if (!$patient || !$encounter) {
        wp_die('Patient or encounter not found');
    }
    
    include PD_PLUGIN_PATH . 'includes/templates/admin-patient-view.php';
}

// Render encounters page
function pd_render_encounters_page() {
    $db = PD_Database::get_instance();
    $encounters = $db->get_encounters();
    
    include PD_PLUGIN_PATH . 'includes/templates/admin-encounters.php';
}

// Include dependencies first
require_once PD_PLUGIN_PATH . 'includes/class-database.php';
require_once PD_PLUGIN_PATH . 'includes/shortcodes.php';

// Handle status changes
add_action('wp_ajax_update_encounter_status', 'pd_update_encounter_status');
function pd_update_encounter_status() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    global $wpdb;
    $result = $wpdb->update(
        "{$wpdb->prefix}kc_appointments",
        ['status' => sanitize_text_field($_POST['status'])],
        ['id' => intval($_POST['appointment_id'])],
        ['%s'],
        ['%d']
    );
    
    wp_send_json_success(['message' => 'Status updated']);
}

// Handle deletion
add_action('wp_ajax_delete_encounter', 'pd_delete_encounter');
function pd_delete_encounter() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    global $wpdb;
    $result = $wpdb->delete(
        "{$wpdb->prefix}kc_appointments",
        ['id' => intval($_POST['appointment_id'])],
        ['%d']
    );
    
    wp_send_json_success(['message' => 'Encounter deleted']);
}

// Add admin scripts
add_action('admin_enqueue_scripts', 'pd_admin_scripts');
function pd_admin_scripts($hook) {
    if('toplevel_page_patient-encounters' === $hook) {
        wp_enqueue_style('pd-admin-css', PD_PLUGIN_URL . 'assets/css/admin-encounters.css');
        wp_enqueue_script('pd-admin-js', PD_PLUGIN_URL . 'assets/js/admin-encounters.js', ['jquery']);
        
        wp_localize_script('pd-admin-js', 'pd_admin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pd-admin-nonce')
        ]);
    }
}

// Add AJAX for frontend filtering
add_action('wp_ajax_pd_filter_encounters', 'pd_filter_encounters');
add_action('wp_ajax_nopriv_pd_filter_encounters', 'pd_filter_encounters');
function pd_filter_encounters() {
    check_ajax_referer('pd-frontend-nonce', 'nonce');
    
    if(!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied', 403);
    }

    $status = sanitize_text_field($_POST['status']);
    ob_start();
    pd_render_encounter_list($status);
    $html = ob_get_clean();
    
    wp_send_json_success(['html' => $html]);
}

// Patient View Handler
add_action('wp_ajax_pd_get_patient_view', 'pd_get_patient_view');
add_action('wp_ajax_nopriv_pd_get_patient_view', 'pd_check_auth');
function pd_get_patient_view() {
    check_ajax_referer('pd-frontend-nonce', 'nonce');
    
    if(!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied', 403);
    }

    $patient_id = intval($_POST['patient_id']);
    
    // Start output buffer
    ob_start();
    
    // Simulate shortcode output
    echo '<div class="patient-documents-wrapper">';
    echo do_shortcode("[patient_documents patient_id='{$patient_id}']");
    echo '</div>';
    
    $html = ob_get_clean();
    
    wp_send_json_success(['html' => $html]);
}

// Bill Details Handler
add_action('wp_ajax_pd_get_bill_details', 'pd_get_bill_details');
add_action('wp_ajax_nopriv_pd_get_bill_details', 'pd_check_auth');
function pd_get_bill_details() {
    check_ajax_referer('pd-frontend-nonce', 'nonce');
    
    if(!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied', 403);
    }

    $db = PD_Database::get_instance();
    $appointment_id = intval($_GET['appointment_id']);
    
    ob_start();
    include PD_PLUGIN_PATH . 'includes/templates/bill-popup.php';
    $html = ob_get_clean();
    
    wp_send_json_success(['html' => $html]);
}

function pd_check_auth() {
    wp_send_json_error('Authentication required', 401);
}

// Filter handler
add_action('wp_ajax_pd_filter_encounters_v2', 'pd_filter_encounters_v2');
function pd_filter_encounters_v2() {
    try {
        check_ajax_referer('pd-encounter-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $date_filter = isset($_POST['date_filter']) ? sanitize_text_field($_POST['date_filter']) : 'all';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : null;
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : null;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';

        $db = PD_Database::get_instance();
        $encounters = $db->get_filtered_encounters($search, $date_filter, $date_from, $date_to, $status);

        ob_start();
        if (!empty($encounters)) {
            foreach ($encounters as $encounter) : ?>
                <tr data-appointment="<?php echo esc_attr($encounter->appointment_id); ?>">
                    <td><?php echo esc_html($encounter->doctor_name); ?></td>
                    <td><?php echo esc_html($encounter->patient_name); ?></td>
                    <td><?php echo esc_html($encounter->service_name ?: 'N/A'); ?></td>
                    <td><?php echo date_i18n('M j, Y H:i', strtotime($encounter->encounter_date)); ?></td>
                    <td>
                        <span class="status-label status-<?php echo esc_attr($encounter->status); ?>">
                            <?php echo ($encounter->status == 1) ? 'Active' : 'Closed'; ?>
                        </span>
                    </td>
                    <td class="actions">
                        <div class="action-buttons">
                            <!-- Profile KC -->
                            <a href="<?php echo admin_url("/admin.php?page=dashboard#/patient/edit/{$encounter->patient_id}"); ?>" 
                               class="pd-action profile" 
                               target="_blank"
                               title="Profile View">
                                <span class="dashicons dashicons-edit"></span>
                            </a>

                            <!-- Profile View -->
                            <a href="<?php echo admin_url("/patient-profile/?patient_id={$encounter->patient_id}"); ?>" 
                               class="pd-action profile" 
                               target="_blank"
                               title="Profile View">
                                <span class="dashicons dashicons-admin-users"></span>
                            </a>

                            <!-- Patient View -->
                            <a href="<?php 
                                echo esc_url(site_url('/patient-view-page/') . '?' . http_build_query([
                                    'patient_id' => $encounter->patient_id,
                                    'encounter_id' => $encounter->encounter_id
                                ])); ?>" 
                               class="pd-action view"
                               title="Patient View">
                                <span class="dashicons dashicons-visibility"></span>
                            </a>

                            <!-- Invoice Button -->
                            <button class="pd-action invoice" 
                                    data-encounter="<?php echo esc_attr($encounter->encounter_id); ?>"
                                    data-patient="<?php echo esc_attr($encounter->patient_id); ?>"
                                    data-clinic="<?php echo esc_attr($encounter->clinic_id); ?>"
                                    title="View Invoice">
                                <span class="dashicons dashicons-media-text"></span>
                            </button>

                            <!-- Delete Button -->
                            <button class="pd-action delete" 
                                    data-appointment="<?php echo esc_attr($encounter->appointment_id); ?>"
                                    title="Delete">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach;
        } else {
            echo '<tr><td colspan="6" class="no-records">No encounters found</td></tr>';
        }
        
        wp_send_json_success([
            'html' => ob_get_clean(),
            'count' => count($encounters)
        ]);

    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'debug' => WP_DEBUG ? $_POST : null
        ]);
    }
}

// Delete handler
add_action('wp_ajax_pd_delete_encounter_v2', 'pd_delete_encounter_v2');
function pd_delete_encounter_v2() {
    check_ajax_referer('pd-encounter-nonce', 'nonce');
    
    if(!current_user_can('delete_posts')) {
        wp_send_json_error('Access denied', 403);
    }

    global $wpdb;
    $result = $wpdb->delete(
        "{$wpdb->prefix}kc_appointments",
        ['id' => intval($_POST['appointment_id'])],
        ['%d']
    );
    
    wp_send_json_success(['message' => 'Encounter deleted']);
}

// Add after existing code
add_action('wp_ajax_pd_get_patient_view_v2', 'pd_get_patient_view_v2');
function pd_get_patient_view_v2() {
    check_ajax_referer('pd-encounter-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $patient_id = intval($_POST['patient_id']);
    $db = PD_Database::get_instance();
    $data = $db->get_patient_data($patient_id);
    
    ob_start();
    include PD_PLUGIN_PATH . 'includes/templates/patient-view.php';
    wp_send_json_success(['html' => ob_get_clean()]);
}

// Register assets
add_action('wp_enqueue_scripts', 'pd_register_encounter_assets');
function pd_register_encounter_assets() {
    wp_register_style(
        'pd-encounter-list',
        PD_PLUGIN_URL . 'assets/css/encounter-list.css',
        [],
        filemtime(PD_PLUGIN_PATH . 'assets/css/encounter-list.css')
    );
    
    wp_register_script(
        'pd-encounter-list',
        PD_PLUGIN_URL . 'assets/js/encounter-list.js',
        ['jquery'],
        filemtime(PD_PLUGIN_PATH . 'assets/js/encounter-list.js'),
        true
    );

    // Register patient view script
    wp_register_script(
        'pd-patient-view',
        PD_PLUGIN_URL . 'assets/js/script.js',
        ['jquery', 'tinymce'],
        filemtime(PD_PLUGIN_PATH . 'assets/js/script.js'),
        true
    );
}

// Add this function after your existing code
function pd_customize_tinymce_plugins($plugins) {
    // Remove 'code' plugin if it's causing issues
    $plugins = array(
        'lists' => includes_url('js/tinymce/plugins/lists/plugin.min.js'),
        'link' => includes_url('js/tinymce/plugins/link/plugin.min.js'),
        'image' => includes_url('js/tinymce/plugins/image/plugin.min.js'),
        'table' => includes_url('js/tinymce/plugins/table/plugin.min.js'),
    );
    return $plugins;
}
add_filter('mce_external_plugins', 'pd_customize_tinymce_plugins');

// Update the enqueue function

function pd_enqueue_editor_assets() {
    if (is_page('patient-view-page')) {
        wp_enqueue_editor();
        wp_enqueue_media();
        add_filter('tiny_mce_before_init', function($settings) {
            $settings['content_css'] = PD_PLUGIN_URL . 'assets/css/editor-style.css';
            return $settings;
        });
    }
}
add_action('wp_enqueue_scripts', 'pd_enqueue_editor_assets', 9);

// ...existing code...

add_action('wp_ajax_pd_get_invoice_v2', 'pd_get_invoice_v2');
function pd_get_invoice_v2() {
    try {
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Invoice request received: ' . print_r($_POST, true));
        }

        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = isset($_POST['encounter_id']) ? intval($_POST['encounter_id']) : 0;
        if (!$encounter_id) {
            throw new Exception('Invalid encounter ID');
        }

        $db = PD_Database::get_instance();
        $invoice = $db->get_bill_details($encounter_id);
        
        if (!$invoice) {
            throw new Exception($db->get_last_error() ?: "No invoice found for encounter #$encounter_id");
        }
        
        ob_start();
        include PD_PLUGIN_PATH . 'includes/templates/invoice-popup.php';
        wp_send_json_success(['html' => ob_get_clean()]);

    } catch (Exception $e) {
        error_log('Invoice error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => $e->getMessage(),
            'encounter_id' => $encounter_id ?? 0
        ]);
    }
}

// Optional: Add handler for sending invoice to patient
add_action('wp_ajax_pd_send_invoice', 'pd_send_invoice');
function pd_send_invoice() {
    check_ajax_referer('pd-encounter-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $appointment_id = intval($_POST['appointment_id']);
    $db = PD_Database::get_instance();
    $bill = $db->get_bill_details($appointment_id);
    
    if (!$bill || !$bill->patient_email) {
        wp_send_json_error(['message' => 'Invalid bill or missing patient email']);
        return;
    }
    
    // Generate PDF or HTML email content
    ob_start();
    include PD_PLUGIN_PATH . 'includes/templates/invoice-email.php';
    $content = ob_get_clean();
    
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $subject = sprintf('Invoice #%d from %s', $bill->id, $bill->clinic_name);
    
    $sent = wp_mail($bill->patient_email, $subject, $content, $headers);
    
    if ($sent) {
        wp_send_json_success(['message' => 'Invoice sent successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to send invoice']);
    }
}

// ...existing code...

// Remove the first declaration of pd_get_bill_details_v2 (around line 381)
// and keep only this one version:

add_action('wp_ajax_pd_get_bill_details_v2', 'pd_get_bill_details_v2');
function pd_get_bill_details_v2() {
    try {
        check_ajax_referer('pd-encounter-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = intval($_POST['encounter_id']);
        if (!$encounter_id) {
            throw new Exception('Invalid encounter ID');
        }

        $db = PD_Database::get_instance();
        $bill = $db->get_bill_details($encounter_id);
        
        if (!$bill) {
            wp_send_json_error([
                'message' => $db->get_last_error() ?: "No bill found for encounter #$encounter_id",
                'encounter_id' => $encounter_id
            ]);
            return;
        }
        
        ob_start();
        include PD_PLUGIN_PATH . 'includes/templates/bill-popup.php';
        wp_send_json_success(['html' => ob_get_clean()]);

    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'encounter_id' => $encounter_id ?? 0
        ]);
    }
}

// Add this action to check plugin health
add_action('admin_init', 'pd_check_plugin_health');
function pd_check_plugin_health() {
    $db = PD_Database::get_instance();
    if (!$db->is_connected()) {
        add_action('admin_notices', function() use ($db) {
            $error = $db->get_last_error();
            echo '<div class="error"><p>';
            echo '<strong>Patient Documents Plugin Error:</strong> Database connection failed.<br>';
            echo 'Error: ' . esc_html($error);
            echo '</p></div>';
        });
    }
}

// Add system health check
add_action('wp_ajax_pd_health_check', 'pd_health_check');
function pd_health_check() {
    $status = array(
        'status' => 'ok',
        'database' => false,
        'editor' => false,
        'files' => false
    );

    // Check database
    $db = PD_Database::get_instance();
    $status['database'] = $db->is_connected();

    // Check required files
    $required_files = [
        'encounter-list.js',
        'encounter-list.css',
        'print.css'
    ];
    
    $status['files'] = true;
    foreach ($required_files as $file) {
        $file_path = PD_PLUGIN_PATH . 'assets/' . dirname($file) . '/' . basename($file);
        if (!file_exists($file_path)) {
            $status['files'] = false;
            error_log("Missing required file: $file_path");
        }
    }

    // Check editor availability
    $status['editor'] = function_exists('wp_enqueue_editor');

    if (!$status['database'] || !$status['editor'] || !$status['files']) {
        $status['status'] = 'error';
    }

    wp_send_json($status);
}

// Add critical requirements check
add_action('admin_notices', 'pd_check_critical_requirements');
function pd_check_critical_requirements() {
    $critical_errors = array();

    // Check PHP version
    if (version_compare(PHP_VERSION, '7.0.0', '<')) {
        $critical_errors[] = 'Patient Documents requires PHP 7.0 or higher.';
    }

    // Check WordPress version
    if (version_compare($GLOBALS['wp_version'], '5.0', '<')) {
        $critical_errors[] = 'Patient Documents requires WordPress 5.0 or higher.';
    }

    // Check database tables
    $db = PD_Database::get_instance();
    if (!$db->is_connected()) {
        $critical_errors[] = 'Database connection failed.';
    }

    if (!empty($critical_errors)) {
        echo '<div class="error"><p>';
        echo '<strong>Patient Documents Plugin Critical Errors:</strong><br>';
        echo implode('<br>', $critical_errors);
        echo '</p></div>';
    }
}

// ...existing code...

// Document Handler
add_action('wp_ajax_pd_get_document_v2', 'pd_get_document_v2');
function pd_get_document_v2() {
    try {
        check_ajax_referer('pd-encounter-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = intval($_POST['encounter_id']);
        $patient_id = intval($_POST['patient_id']);
        $type = sanitize_text_field($_POST['type']);
        
        if (!$encounter_id || !$patient_id || !$type) {
            throw new Exception('Invalid parameters');
        }

        $db = PD_Database::get_instance();
        $document = $db->get_patient_document($encounter_id, $type);
        $patient = get_userdata($patient_id);
        $encounter = $db->get_full_encounter_details($encounter_id);
        
        ob_start();
        include PD_PLUGIN_PATH . 'includes/templates/document-popup.php';
        wp_send_json_success(['html' => ob_get_clean()]);

    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage()
        ]);
    }
}

// Add these handlers to your main plugin file

add_action('wp_ajax_pd_save_consultation', 'pd_save_consultation');
function pd_save_consultation() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = intval($_POST['encounter_id']);
        $patient_id = intval($_POST['patient_id']);
        
        if (!$encounter_id || !$patient_id) {
            throw new Exception('Missing required IDs');
        }
        
        if (!isset($_POST['data']) || !is_array($_POST['data'])) {
            throw new Exception('Invalid data format');
        }
        
        $db = PD_Database::get_instance();
        $result = $db->save_consultation($encounter_id, $patient_id, $_POST['data']);
        
        if ($result) {
            wp_send_json_success(['message' => 'Consultation saved successfully']);
        } else {
            throw new Exception($db->get_last_error() ?: 'Failed to save consultation');
        }
        
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'debug' => WP_DEBUG ? [
                'encounter_id' => $encounter_id ?? null,
                'patient_id' => $patient_id ?? null,
                'data' => $_POST['data'] ?? null
            ] : null
        ]);
    }
}

// ...existing code...

// Keep ONLY this version of pd_add_prescription and remove ALL others
add_action('wp_ajax_pd_add_prescription', 'pd_add_prescription');
function pd_add_prescription() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        // Debug log the incoming data
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Prescription Data Received: ' . print_r($_POST, true));
        }
        
        $encounter_id = isset($_POST['encounter_id']) ? intval($_POST['encounter_id']) : 0;
        $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        
        // Debug log the parsed IDs
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Parsed IDs - Encounter: $encounter_id, Patient: $patient_id");
        }
        
        // Validate required fields first
        if (!$encounter_id || !$patient_id) {
            // Debug log the validation failure
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Missing IDs - Encounter: ' . $encounter_id . ', Patient: ' . $patient_id);
            }
            wp_send_json_error(['message' => 'Missing encounter_id or patient_id']);
            return; // Exit early
        }
        
        $data = array(
            'encounter_id' => $encounter_id,
            'patient_id' => $patient_id,
            'medication_name' => sanitize_text_field($_POST['medication_name']),
            'dosage' => sanitize_text_field($_POST['dosage']),
            'frequency' => sanitize_text_field($_POST['frequency']),
            'duration' => sanitize_text_field($_POST['duration']),
            'instructions' => sanitize_textarea_field($_POST['instructions']),
            'doctor_id' => get_current_user_id(),
            'clinic_id' => intval($_POST['clinic_id']),
            'created_at' => current_time('mysql')
        );
        
        $db = PD_Database::get_instance();
        $result = $db->add_prescription($data);
        
        if ($result) {
            wp_send_json_success(['message' => 'Prescription added successfully']);
            return; // Make sure to return after sending success
        } 
        
        throw new Exception($db->get_last_error() ?: 'Failed to add prescription');
        
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Prescription Error: ' . $e->getMessage());
        }
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// Add after plugin activation hook
function pd_create_required_pages() {
    // Create Patient View page if it doesn't exist
    $patient_view_page = get_page_by_path('patient-view-page');
    if (!$patient_view_page) {
        $page_id = wp_insert_post([
            'post_title' => 'Patient View',
            'post_name' => 'patient-view-page', // This sets the slug
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[patient_view]'
        ]);
        
        if (!is_wp_error($page_id)) {
            update_option('pd_patient_view_page', $page_id);
        }
    }
}

// Run this on plugin activation
register_activation_hook(__FILE__, 'pd_create_required_pages');

// Add after plugin activation hook
function pd_register_page_templates($templates) {
    $templates[PD_PLUGIN_PATH . 'templates/page-patient-view.php'] = 'Patient View Template';
    return $templates;
}
add_filter('theme_page_templates', 'pd_register_page_templates');

// Add template loader
function pd_load_page_template($template) {
    if(is_page()) {
        $meta = get_post_meta(get_the_ID(), '_wp_page_template', true);
        if('page-patient-view.php' === basename($meta)) {
            $template = PD_PLUGIN_PATH . 'templates/page-patient-view.php';
        }
    }
    return $template;
}
add_filter('template_include', 'pd_load_page_template');

// Add document save handler
add_action('wp_ajax_pd_save_document', 'pd_save_document');
function pd_save_document() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = intval($_POST['encounter_id']);
        $type = sanitize_text_field($_POST['type']);
        $content = wp_kses_post($_POST['content']);

        if (!$encounter_id) {
            throw new Exception('Missing encounter ID');
        }

        $db = PD_Database::get_instance();
        $result = $db->save_patient_document($encounter_id, $type, $content);

        if ($result) {
            wp_send_json_success(['message' => 'Document saved successfully']);
        } else {
            throw new Exception($db->get_last_error() ?: 'Failed to save document');
        }

    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'debug' => WP_DEBUG ? [
                'encounter_id' => $encounter_id ?? null,
                'type' => $type ?? null
            ] : null
        ]);
    }
}

// ...existing code...

function pd_enqueue_patient_view_scripts() {
    if (is_page('patient-view-page') || isset($_GET['patient_id'])) {
        // Enqueue Select2
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery']);
        
        // Your existing enqueues...
        wp_enqueue_style('dashicons');
        wp_enqueue_style('pd-patient-view', PD_PLUGIN_URL . 'assets/css/patient-view.css');
        wp_enqueue_script('pd-patient-view', 
            PD_PLUGIN_URL . 'assets/js/script.js',
            ['jquery', 'select2'],
            filemtime(PD_PLUGIN_PATH . 'assets/js/script.js'),
            true
        );
        
        // Rest of your code...
    }
}
add_action('wp_enqueue_scripts', 'pd_enqueue_patient_view_scripts', 100); // Higher priority

// Update general script enqueue to exclude patient view functionality
function pd_enqueue_general_scripts() {
    // Don't load on generate documents page
    if (!is_page('generate-documents')) {
        wp_enqueue_script('pd-script', 
            PD_PLUGIN_URL . 'assets/js/script.js',
            ['jquery'],
            filemtime(PD_PLUGIN_PATH . 'assets/js/script.js'),
            true
        );
        
        wp_localize_script('pd-script', 'pdData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pd-nonce')
        ));
    }
}
add_action('wp_enqueue_scripts', 'pd_enqueue_general_scripts');

function pd_enqueue_document_scripts() {
    if (is_page('generate-documents')) {
        // Dequeue general script if it was somehow loaded
        wp_dequeue_script('pd-script');
        
        // Enqueue document specific scripts
        wp_enqueue_editor();
        wp_enqueue_media();
        
        wp_enqueue_script('pd-documents', 
            PD_PLUGIN_URL . 'assets/js/documents.js',
            ['jquery', 'tinymce'],
            filemtime(PD_PLUGIN_PATH . 'assets/js/documents.js'),
            true
        );

        wp_localize_script('pd-documents', 'pdData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pd-template-nonce'),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ));
    }
}
add_action('wp_enqueue_scripts', 'pd_enqueue_document_scripts', 100); // Higher priority

// Add prescription print handler
add_action('wp_ajax_pd_get_prescription_print', 'pd_get_prescription_print');
function pd_get_prescription_print() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $prescription_id = intval($_POST['prescription_id']);
    if (!$prescription_id) {
        wp_send_json_error('Invalid prescription ID');
        return;
    }
    
    $db = PD_Database::get_instance();
    $prescription = $db->get_prescription($prescription_id);
    
    if (!$prescription) {
        wp_send_json_error('Prescription not found');
        return;
    }
    
    ob_start();
    include PD_PLUGIN_PATH . 'includes/templates/prescription-print.php';
    wp_send_json_success(['html' => ob_get_clean()]);
}

// Add all prescriptions print handler
add_action('wp_ajax_pd_get_all_prescriptions_print', 'pd_get_all_prescriptions_print');
function pd_get_all_prescriptions_print() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $encounter_id = intval($_POST['encounter_id']);
    if (!$encounter_id) {
        wp_send_json_error('Invalid encounter ID');
        return;
    }
    
    $db = PD_Database::get_instance();
    $prescriptions = $db->get_encounter_prescriptions($encounter_id);
    
    if (empty($prescriptions)) {
        wp_send_json_error('No prescriptions found');
        return;
    }
    
    ob_start();
    foreach ($prescriptions as $prescription) {
        include PD_PLUGIN_PATH . 'includes/templates/prescription-print.php';
    }
    wp_send_json_success(['html' => ob_get_clean()]);
}

// Add prescription delete handler
add_action('wp_ajax_pd_delete_prescription', 'pd_delete_prescription');
function pd_delete_prescription() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $prescription_id = intval($_POST['prescription_id']);
    if (!$prescription_id) {
        wp_send_json_error('Invalid prescription ID');
        return;
    }
    
    $db = PD_Database::get_instance();
    $result = $db->delete_prescription($prescription_id);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error($db->get_last_error() ?: 'Failed to delete prescription');
    }
}

/* ...existing code... */

// Add document view handler
add_action('wp_ajax_pd_get_document_content', 'pd_get_document_content');
function pd_get_document_content() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
        if (!$document_id) {
            throw new Exception('Invalid document ID');
        }
        
        $db = PD_Database::get_instance();
        $document = $db->get_document_by_id($document_id);
        
        if (!$document) {
            throw new Exception('Document not found');
        }
        
        wp_send_json_success([
            'content' => $document->content,
            'type' => $document->document_type,
            'date' => $document->created_at
        ]);
        
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'document_id' => $document_id ?? 0
        ]);
    }
}

// ...existing code...

// Add print encounter prescriptions handler
add_action('wp_ajax_pd_print_encounter_prescriptions', 'pd_print_encounter_prescriptions');
function pd_print_encounter_prescriptions() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = isset($_POST['encounter_id']) ? intval($_POST['encounter_id']) : 0;
        if (!$encounter_id) {
            throw new Exception('Invalid encounter ID');
        }
        
        $db = PD_Database::get_instance();
        $prescriptions = $db->get_encounter_prescriptions($encounter_id);
        $encounter = $db->get_full_encounter_details($encounter_id);
        
        if (empty($prescriptions)) {
            throw new Exception('No prescriptions found for this encounter');
        }
        
        ob_start();
        include PD_PLUGIN_PATH . 'templates/print-all-prescriptions.php';
        wp_send_json_success(['html' => ob_get_clean()]);
        
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'encounter_id' => $encounter_id ?? 0
        ]);
    }
}

// ...existing code...

// Add prescriptions list reload handler
add_action('wp_ajax_pd_get_encounter_prescriptions', 'pd_get_encounter_prescriptions_html');
function pd_get_encounter_prescriptions_html() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = isset($_POST['encounter_id']) ? intval($_POST['encounter_id']) : 0;
        if (!$encounter_id) {
            throw new Exception('Invalid encounter ID');
        }

        $db = PD_Database::get_instance();
        $prescriptions = $db->get_encounter_prescriptions($encounter_id);
        
        ob_start();
        include PD_PLUGIN_PATH . 'templates/partials/prescriptions-list.php';
        wp_send_json_success(['html' => ob_get_clean()]);
        
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'encounter_id' => $encounter_id ?? 0
        ]);
    }
}
add_action('wp_ajax_pd_print_all_prescriptions', 'pd_print_all_prescriptions_handler');
function pd_print_all_prescriptions_handler() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = isset($_POST['encounter_id']) ? intval($_POST['encounter_id']) : 0;
        if (!$encounter_id) {
            throw new Exception('Invalid encounter ID');
        }
        
        $db = PD_Database::get_instance();
        $prescriptions = $db->get_encounter_prescriptions($encounter_id);
        $encounter = $db->get_full_encounter_details($encounter_id);
        
        if (empty($prescriptions)) {
            throw new Exception('No prescriptions found for this encounter');
        }
        
        ob_start();
        include PD_PLUGIN_PATH . 'templates/print-prescriptions.php';
        wp_send_json_success(['html' => ob_get_clean()]);
        
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'encounter_id' => $encounter_id ?? 0
        ]);
    }
}



add_action('wp_ajax_pd_get_templates', 'pd_get_templates_handler');
function pd_get_templates_handler() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $type = sanitize_text_field($_POST['type']);
        if (!$type) {
            throw new Exception('Missing template type');
        }
        
        $db = PD_Database::get_instance();
        $templates = $db->get_templates($type);
        
        wp_send_json_success($templates);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

/* ...existing code... */

// Add document loading handler
add_action('wp_ajax_pd_get_document', 'pd_get_document_handler');
function pd_get_document_handler() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = intval($_POST['encounter_id']);
        $type = sanitize_text_field($_POST['type']);
        
        if (!$encounter_id || !$type) {
            throw new Exception('Missing required parameters');
        }
        
        $db = PD_Database::get_instance();
        $document = $db->get_patient_document($encounter_id, $type);
        
        if ($document) {
            wp_send_json_success([
                'content' => $document->content,
                'type' => $document->document_type,
                'created_at' => $document->created_at
            ]);
        } else {
            // Return empty content to trigger default template
            wp_send_json_success(['content' => '']);
        }
        
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'debug' => WP_DEBUG ? [
                'encounter_id' => $encounter_id ?? null,
                'type' => $type ?? null
            ] : null
        ]);
    }
}

/* ...existing code... */

/* ...existing code... */



// Add medication handler

/* ...existing code... */

add_action('wp_ajax_pd_add_medication', 'pd_add_medication_handler');
function pd_add_medication_handler() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        if (!$name) {
            throw new Exception('Medication name is required');
        }
        
        $db = PD_Database::get_instance();
        $result = $db->add_medication($name);
        
        if (!$result) {
            throw new Exception($db->get_last_error() ?: 'Failed to add medication');
        }
        
        wp_send_json_success([
            'id' => $result,
            'name' => $name
        ]);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// Get medications handler
add_action('wp_ajax_pd_get_medications', 'pd_get_medications_handler');
function pd_get_medications_handler() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $db = PD_Database::get_instance();
        $medications = $db->get_medications();
        
        wp_send_json_success($medications);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

/* ...existing code... */

/* ...existing code... */

add_action('wp_ajax_pd_save_driver_license_report', 'pd_save_driver_license_report');
function pd_save_driver_license_report() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        if (!isset($_FILES['medical_report'])) {
            throw new Exception('No file uploaded');
        }
        
        $file = $_FILES['medical_report'];
        $allowed_types = ['application/pdf', 'image/png'];
        
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid file type. Only PDF and PNG files are allowed.');
        }
        
        // Upload the file
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $upload = wp_handle_upload($file, ['test_form' => false]);
        
        if (isset($upload['error'])) {
            throw new Exception($upload['error']);
        }
        
        // Save to database
        $db = PD_Database::get_instance();
        $result = $db->save_driver_license_report([
            'patient_id' => intval($_POST['patient_id']),
            'encounter_id' => intval($_POST['encounter_id']),
            'file_name' => $file['name'],
            'file_path' => $upload['url'],
            'file_type' => pathinfo($file['name'], PATHINFO_EXTENSION)
        ]);
        
        if (!$result) {
            throw new Exception($db->get_last_error());
        }
        
        wp_send_json_success(['message' => 'Report saved successfully']);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

/* ...existing code... */

add_action('wp_ajax_pd_save_medical_report', 'pd_save_medical_report');
function pd_save_medical_report() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        if (!isset($_FILES['medical_report'])) {
            throw new Exception('No file uploaded');
        }
        
        $file = $_FILES['medical_report'];
        $allowed_types = ['application/pdf', 'image/png'];
        
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid file type. Only PDF and PNG files are allowed.');
        }
        
        // Upload the file
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $upload = wp_handle_upload($file, ['test_form' => false]);
        
        if (isset($upload['error'])) {
            throw new Exception($upload['error']);
        }
        
        // Save to database
        $db = PD_Database::get_instance();
        $result = $db->save_medical_report([
            'patient_id' => intval($_POST['patient_id']),
            'encounter_id' => intval($_POST['encounter_id']),
            'file_name' => $file['name'],
            'file_path' => $upload['url'],
            'file_type' => pathinfo($file['name'], PATHINFO_EXTENSION)
        ]);
        
        if (!$result) {
            throw new Exception($db->get_last_error());
        }
        
        wp_send_json_success(['message' => 'Report saved successfully']);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

/* ...existing code... */

add_action('wp_ajax_pd_delete_patient', 'pd_delete_patient');
function pd_delete_patient() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            throw new Exception('Access denied');
        }
        
        $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        if (!$patient_id) {
            throw new Exception('Invalid patient ID');
        }
        
        // Delete patient
        if (!wp_delete_user($patient_id)) {
            throw new Exception('Failed to delete patient');
        }
        
        wp_send_json_success(['message' => 'Patient deleted successfully']);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

/* ...existing code... */

/* ...existing code... */

// Add shortcode for patients list
add_shortcode('pd_patients_list', 'pd_render_patients_list_shortcode');
function pd_render_patients_list_shortcode($atts) {
 // Check for either admin or clinic admin permissions
 if (!current_user_can('pd_manage_patients') && !current_user_can('manage_options')) {
    return '<p>You do not have permission to view this content.</p>';
}

// Enqueue required styles for icons
wp_enqueue_style('dashicons');

    // Start output buffering
    ob_start();

    // Include the patients list template
    include PD_PLUGIN_PATH . 'includes/templates/patients-list-frontend.php';

    // Return the buffered content
    return ob_get_clean();
}

/* ...existing code... */

/* ...existing code... */

// Add Accounting shortcode
add_shortcode('accounting_page', 'pd_render_accounting_page');
function pd_render_accounting_page() {
    if (!current_user_can('pd_manage_accounting') && !current_user_can('manage_options')) {
        return '<p>Access denied.</p>';
    }
    
    wp_enqueue_style('dashicons');
    
    wp_enqueue_style('pd-accounting', PD_PLUGIN_URL . 'assets/css/accounting.css');
    wp_enqueue_script('pd-accounting', PD_PLUGIN_URL . 'assets/js/accounting.js', ['jquery']);
    wp_localize_script('pd-accounting', 'pd_accounting', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('pd-accounting-nonce')
    ]);
    
    ob_start();
    include PD_PLUGIN_PATH . 'includes/templates/accounting-page.php';
    return ob_get_clean();
}

// Add AJAX handlers
add_action('wp_ajax_pd_save_accounting', 'pd_save_accounting');
function pd_save_accounting() {
    check_ajax_referer('pd-accounting-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
    }
    
    $data = [
        'date' => sanitize_text_field($_POST['date']),
        'invoice_number' => sanitize_text_field($_POST['invoice_number']),
        'beneficiary' => sanitize_text_field($_POST['beneficiary']),
        'payment_method' => sanitize_text_field($_POST['payment_method']),
        'payment_reference' => sanitize_text_field($_POST['payment_reference']),
        'amount' => floatval($_POST['amount'])
    ];
    
    $db = PD_Database::get_instance();
    $result = $db->save_accounting($data, isset($_POST['id']) ? intval($_POST['id']) : null);
    
    if ($result) {
        wp_send_json_success(['message' => 'Entry saved successfully']);
    } else {
        wp_send_json_error(['message' => $db->get_last_error() ?: 'Failed to save entry']);
    }
}

// Get accounting entry
add_action('wp_ajax_pd_get_accounting', 'pd_get_accounting');
function pd_get_accounting() {
    check_ajax_referer('pd-accounting-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
    }
    
    $id = intval($_POST['id']);
    if (!$id) {
        wp_send_json_error(['message' => 'Invalid ID']);
    }
    
    $db = PD_Database::get_instance();
    $entry = $db->get_accounting_entry($id);
    
    if ($entry) {
        wp_send_json_success($entry);
    } else {
        wp_send_json_error(['message' => 'Entry not found']);
    }
}

// Get accounting list
add_action('wp_ajax_pd_get_accounting_list', 'pd_get_accounting_list');
function pd_get_accounting_list() {
    check_ajax_referer('pd-accounting-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
    }
    
    $db = PD_Database::get_instance();
    $entries = $db->get_accounting_list();
    
    wp_send_json_success($entries);
}

// Delete accounting entry
add_action('wp_ajax_pd_delete_accounting', 'pd_delete_accounting');
function pd_delete_accounting() {
    check_ajax_referer('pd-accounting-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
    }
    
    $id = intval($_POST['id']);
    if (!$id) {
        wp_send_json_error(['message' => 'Invalid ID']);
    }
    
    $db = PD_Database::get_instance();
    $result = $db->delete_accounting($id);
    
    if ($result) {
        wp_send_json_success(['message' => 'Entry deleted successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to delete entry']);
    }
}

/* ...existing code... */

/* ...existing code... */

// Add shortcode for driver license report
add_shortcode('driver_license_report', 'pd_driver_license_report_shortcode');
function pd_driver_license_report_shortcode() {
    if (!current_user_can('edit_posts')) {
        return '<p>Access denied</p>';
    }

    // Enqueue CSS and JS
    wp_enqueue_style('pd-driver-license-report', PD_PLUGIN_URL . 'assets/css/driver-license-report.css');
    wp_enqueue_script('pd-driver-license-report', PD_PLUGIN_URL . 'assets/js/driver-license-report.js', ['jquery']);

    // Localize script with AJAX URL and nonce
    wp_localize_script('pd-driver-license-report', 'pd_driver_license', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('pd-driver-license-nonce')
    ]);

    // Render the template
    ob_start();
    include PD_PLUGIN_PATH . 'includes/templates/driver-license-report.php';
    return ob_get_clean();
}

// Save or update driver license record
add_action('wp_ajax_pd_save_driver_license_record', 'pd_save_driver_license_record');
function pd_save_driver_license_record() {
    // Verify nonce
    check_ajax_referer('pd-driver-license-nonce', 'nonce');

    // Check user permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Access denied']);
    }

    try {
        // Sanitize input data
        $data = [
            'order_number'   => sanitize_text_field($_POST['order_number']),
            'date'           => sanitize_text_field($_POST['date']),
            'patient_name'   => sanitize_text_field($_POST['patient_name']),
            'cin'            => sanitize_text_field($_POST['cin']),
            'license_type'   => sanitize_text_field($_POST['license_type']),
            'interest_status'=> sanitize_text_field($_POST['interest_status']),
        ];

        // Debugging (optional)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Saving driver license record with data: ' . print_r($data, true));
        }

        // Get database instance
        $db = PD_Database::get_instance();

        // Save or update record
        $result = $db->save_driver_license_record(
            $data,
            isset($_POST['id']) ? intval($_POST['id']) : null
        );

        // Respond with success or error
        if ($result) {
            wp_send_json_success(['message' => 'Record saved successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to save record']);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Record save error: ' . $e->getMessage()]);
    }
}

// Get driver license record by ID
add_action('wp_ajax_pd_get_driver_license_record', 'pd_get_driver_license_record');
function pd_get_driver_license_record() {
    // Verify nonce
    check_ajax_referer('pd-driver-license-nonce', 'nonce');

    // Check user permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Access denied']);
    }

    // Validate ID
    $id = intval($_POST['id']);
    if (!$id) {
        wp_send_json_error(['message' => 'Invalid ID']);
    }

    // Get database instance
    $db = PD_Database::get_instance();

    // Fetch record
    $record = $db->get_driver_license_record($id);

    // Respond with success or error
    if ($record) {
        wp_send_json_success($record);
    } else {
        wp_send_json_error(['message' => 'Record not found']);
    }
}

// Get driver license records list
add_action('wp_ajax_pd_get_driver_license_records', 'pd_get_driver_license_records');
function pd_get_driver_license_records() {
    // Verify nonce
    check_ajax_referer('pd-driver-license-nonce', 'nonce');

    // Check user permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Access denied']);
    }

    // Sanitize input filters
    $search = sanitize_text_field($_POST['search'] ?? '');
    $date_filter = sanitize_text_field($_POST['date_filter'] ?? 'all');
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to = sanitize_text_field($_POST['date_to'] ?? '');

    // Get database instance
    $db = PD_Database::get_instance();

    // Fetch records
    $records = $db->get_driver_license_records($search, $date_filter, $date_from, $date_to);

    // Respond with success
    wp_send_json_success($records);
}

// Delete driver license record
add_action('wp_ajax_pd_delete_driver_license_record', 'pd_delete_driver_license_record');
function pd_delete_driver_license_record() {
    // Verify nonce
    check_ajax_referer('pd-driver-license-nonce', 'nonce');

    // Check user permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Access denied']);
    }

    // Validate ID
    $id = intval($_POST['id']);
    if (!$id) {
        wp_send_json_error(['message' => 'Invalid ID']);
    }

    // Get database instance
    $db = PD_Database::get_instance();

    // Delete record
    $result = $db->delete_driver_license_record($id);

    // Respond with success or error
    if ($result) {
        wp_send_json_success(['message' => 'Record deleted successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to delete record']);
    }
}


/* ...existing code... */

// ...existing code...

function pd_enqueue_profile_scripts() {
    if (is_page('patient-profile') || isset($_GET['patient_id'])) {
        wp_enqueue_style('dashicons');
        wp_enqueue_style('pd-patient-profile', PD_PLUGIN_URL . 'assets/css/patient-profile.css');
        wp_enqueue_script('pd-patient-profile', 
            PD_PLUGIN_URL . 'assets/js/patient-profile.js',
            ['jquery'],
            filemtime(PD_PLUGIN_PATH . 'assets/js/patient-profile.js'),
            true
        );
        
        // Update the localized script data to include both nonces
        wp_localize_script('pd-patient-profile', 'pdData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pd-nonce'),
            'encounterNonce' => wp_create_nonce('pd-nonce'), // Changed to use same nonce
            'printCss' => PD_PLUGIN_URL . 'assets/css/print.css'
        ]);
    }
}
add_action('wp_enqueue_scripts', 'pd_enqueue_profile_scripts');

// Also add this function to localize the encounter list script with appropriate data
function pd_enqueue_encounter_scripts() {
    if (is_page('encounters') || isset($_GET['encounter_id'])) {
        wp_enqueue_style('pd-encounter-list');
        wp_enqueue_script('pd-encounter-list');
        
        wp_localize_script('pd-encounter-list', 'pdEncounterData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pd-nonce'),
            'printCss' => PD_PLUGIN_URL . 'assets/css/print.css',
            'isLoggedIn' => is_user_logged_in(),
            'userCaps' => array(
                'read' => current_user_can('read'),
                'edit_posts' => current_user_can('edit_posts')
            )
        ]);
    }
}
add_action('wp_enqueue_scripts', 'pd_enqueue_encounter_scripts');

// ...existing code...

add_action('wp_ajax_pd_get_template', 'pd_get_template_handler');
function pd_get_template_handler() {
    try {
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Received nonce: ' . $_POST['nonce']);
        }

        // Accept both nonces for compatibility
        $nonce_valid = check_ajax_referer('pd-documents-nonce', 'nonce', false) || 
                      check_ajax_referer('pd-nonce', 'nonce', false);
        
        if (!$nonce_valid) {
            throw new Exception('Invalid security token');
        }
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        if (!$template_id) {
            throw new Exception('Invalid template ID');
        }
        
        $db = PD_Database::get_instance();
        $template = $db->get_template($template_id);
        
        if (!$template) {
            throw new Exception('Template not found');
        }
        
        wp_send_json_success([
            'content' => $template->content,
            'type' => $template->document_type,
            'name' => $template->template_name
        ]);
        
    } catch (Exception $e) {
        error_log('Template load error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => $e->getMessage(),
            'template_id' => $template_id ?? 0
        ]);
    }
}

add_action('wp_ajax_pd_save_template', 'pd_save_template_handler');
function pd_save_template_handler() {
    try {
        // Accept both nonces for compatibility
        $nonce_valid = check_ajax_referer('pd-documents-nonce', 'nonce', false) || 
                      check_ajax_referer('pd-nonce', 'nonce', false);
        
        if (!$nonce_valid) {
            throw new Exception('Invalid security token');
        }
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        if (!isset($_POST['type'], $_POST['name'], $_POST['content'])) {
            throw new Exception('Missing required fields');
        }
        
        $db = PD_Database::get_instance();
        $result = $db->save_template([
            'document_type' => sanitize_text_field($_POST['type']),
            'template_name' => sanitize_text_field($_POST['name']),
            'content' => wp_kses_post($_POST['content']),
            'is_template' => 1,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ]);
        
        if (!$result) {
            throw new Exception($db->get_last_error() ?: 'Failed to save template');
        }
        
        wp_send_json_success(['message' => 'Template saved successfully']);
        
    } catch (Exception $e) {
        error_log('Template save error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => $e->getMessage()
        ]);
    }
}

// ...existing code...

// ... existing code ...

// Add Ultrasound AJAX handlers
add_action('wp_ajax_pd_add_ultrasound', 'pd_add_ultrasound_handler');
function pd_add_ultrasound_handler() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $name = sanitize_text_field($_POST['name']);
    $description = sanitize_textarea_field($_POST['description'] ?? '');
    
    $db = PD_Database::get_instance();
    $result = $db->add_ultrasound($name, $description);
    
    if ($result) {
        wp_send_json_success(['id' => $db->wpdb->insert_id]);
    } else {
        wp_send_json_error('Failed to add ultrasound');
    }
}

add_action('wp_ajax_pd_add_patient_ultrasound', 'pd_add_patient_ultrasound_handler');
function pd_add_patient_ultrasound_handler() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $patient_id = intval($_POST['patient_id']);
    $encounter_id = intval($_POST['encounter_id']);
    $ultrasound_id = intval($_POST['ultrasound_id']);
    
    $db = PD_Database::get_instance();
    $result = $db->add_patient_ultrasound($patient_id, $encounter_id, $ultrasound_id);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to add patient ultrasound');
    }
}

// Add Ultrasound delete handler
add_action('wp_ajax_pd_delete_ultrasound', 'pd_delete_ultrasound_handler');
function pd_delete_ultrasound_handler() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Access denied']);
            return;
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'Invalid ID']);
            return;
        }
        
        $db = PD_Database::get_instance();
        $result = $db->delete_patient_ultrasound($id);
        
        if ($result !== false) {
            wp_send_json_success(['message' => 'Ultrasound deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete ultrasound']);
        }
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}
// Update ultrasound print handler
add_action('wp_ajax_pd_print_ultrasound', 'pd_print_ultrasound_handler');
function pd_print_ultrasound_handler() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $encounter_id = isset($_POST['encounter_id']) ? intval($_POST['encounter_id']) : 0;
        if (!$encounter_id) {
            throw new Exception('Invalid encounter ID');
        }

        $db = PD_Database::get_instance();
        $ultrasounds = $db->get_patient_ultrasounds($encounter_id);
        $encounter = $db->get_full_encounter_details($encounter_id);
        
        if (empty($ultrasounds)) {
            throw new Exception('No ultrasounds found for this encounter');
        }
        
        ob_start();
        include PD_PLUGIN_PATH . 'templates/print-ultrasound.php';
        wp_send_json_success(['html' => ob_get_clean()]);
        
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'encounter_id' => $encounter_id ?? 0
        ]);
    }
}



// Add Analyse & Radio AJAX handlers
add_action('wp_ajax_pd_add_analyse_radio', 'pd_add_analyse_radio_handler');
function pd_add_analyse_radio_handler() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $name = sanitize_text_field($_POST['name']);
    $description = sanitize_textarea_field($_POST['description'] ?? '');
    
    $db = PD_Database::get_instance();
    $result = $db->add_analyse_radio($name, $description);
    
    if ($result) {
        wp_send_json_success(['id' => $db->wpdb->insert_id]);
    } else {
        wp_send_json_error('Failed to add analyse/radio');
    }
}

add_action('wp_ajax_pd_add_patient_analyse_radio', 'pd_add_patient_analyse_radio_handler');
function pd_add_patient_analyse_radio_handler() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $patient_id = intval($_POST['patient_id']);
    $encounter_id = intval($_POST['encounter_id']);
    $analyse_radio_id = intval($_POST['analyse_radio_id']);
    
    $db = PD_Database::get_instance();
    $result = $db->add_patient_analyse_radio($patient_id, $encounter_id, $analyse_radio_id);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to add patient analyse/radio');
    }
}

add_action('wp_ajax_pd_delete_analyse_radio', 'pd_delete_analyse_radio_handler');
function pd_delete_analyse_radio_handler() {
    try {
        check_ajax_referer('pd-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Access denied']);
            return;
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'Invalid ID']);
            return;
        }
        
        $db = PD_Database::get_instance();
        $result = $db->delete_patient_analyse_radio($id);
        
        if ($result !== false) {
            wp_send_json_success(['message' => 'Analyse/Radio deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete analyse/radio']);
        }
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

add_action('wp_ajax_pd_print_analyse_radio', 'pd_print_analyse_radio_handler');
function pd_print_analyse_radio_handler() {
    check_ajax_referer('pd-nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Access denied');
    }
    
    $encounter_id = intval($_POST['encounter_id']);
    
    $db = PD_Database::get_instance();
    $analyse_radios = $db->get_patient_analyse_radio($encounter_id);
    $encounter = $db->get_full_encounter_details($encounter_id);
    
    if (empty($analyse_radios)) {
        wp_send_json_error(['message' => 'No analyses or radios found']);
        return;
    }
    
    ob_start();
    include PD_PLUGIN_PATH . 'templates/print-analyse-radio.php';
    wp_send_json_success(['html' => ob_get_clean()]);
}

// ...existing code...



// ...existing code...

// Add this after register_activation_hook
register_activation_hook(__FILE__, 'pd_setup_roles_capabilities');
function pd_setup_roles_capabilities() {
    // Get the clinic admin role
    $clinic_admin = get_role('kiviCare_clinic_admin');
    
    if (!$clinic_admin) {
        // Create the role if it doesn't exist
        $clinic_admin = add_role('kiviCare_clinic_admin', 'Clinic Admin');
    }
    
    // Add required capabilities
    $required_caps = array(
        'read',
        'edit_posts',
        'manage_options',
        'pd_view_buttons',      // Custom capability for viewing buttons
        'pd_manage_patients',   // Custom capability for patient management
        'pd_manage_encounters', // Custom capability for encounter management
        'pd_access_reports',    // Custom capability for accessing reports
        'pd_manage_accounting'  // Custom capability for accounting features
    );
    
    foreach ($required_caps as $cap) {
        $clinic_admin->add_cap($cap);
    }
    
    // Also add these capabilities to administrator role
    $admin = get_role('administrator');
    foreach ($required_caps as $cap) {
        $admin->add_cap($cap);
    }
}

// Add this function to check permissions
function pd_can_view_buttons($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    
    return user_can($user, 'pd_view_buttons') || 
           user_can($user, 'manage_options') || 
           user_can($user, 'administrator');
}

// Register receptionist dashboard assets
function pd_register_receptionist_assets() {
    wp_register_style(
        'pd-receptionist-dashboard',
        PD_PLUGIN_URL . 'assets/css/receptionist-dashboard.css',
        [],
        filemtime(PD_PLUGIN_PATH . 'assets/css/receptionist-dashboard.css')
    );
    
    wp_register_script(
        'pd-receptionist-dashboard',
        PD_PLUGIN_URL . 'assets/js/receptionist-dashboard.js',
        ['jquery'],
        filemtime(PD_PLUGIN_PATH . 'assets/js/receptionist-dashboard.js'),
        true
    );
    
    wp_localize_script('pd-receptionist-dashboard', 'pdData', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('pd-receptionist-nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'pd_register_receptionist_assets');

// Add AJAX handlers for appointments
add_action('wp_ajax_pd_get_appointments', 'pd_get_appointments_handler');
function pd_get_appointments_handler() {
    try {
        check_ajax_referer('pd-receptionist-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $month = isset($_POST['month']) ? intval($_POST['month']) : date('n');
        $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
        
        $db = PD_Database::get_instance();
        $appointments = $db->get_appointments_for_month($month, $year);
        
        wp_send_json_success($appointments);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// ...existing code...

add_action('wp_ajax_pd_delete_appointment', 'pd_delete_appointment_handler');
function pd_delete_appointment_handler() {
    try {
        check_ajax_referer('pd-receptionist-nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            throw new Exception('Access denied');
        }
        
        $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        if (!$patient_id) {
            throw new Exception('Invalid patient ID');
        }

        global $wpdb;
        $result = $wpdb->delete(
            $wpdb->prefix . 'kc_custom_fields_data',
            [
                'module_id' => $patient_id,
                'field_id' => 5
            ],
            ['%d', '%d']
        );

        if ($result !== false) {
            wp_send_json_success(['message' => 'Appointment deleted successfully']);
        } else {
            throw new Exception('Failed to delete appointment');
        }

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// ...existing code...
