<?php
/**
 * Plugin Name: Focal Point PDF Registry
 * Description: Shared multisite audit registry for user Coaching, Feedback, and Aspirations PDFs.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once WPMU_PLUGIN_DIR . '/focalpoint-pdf-registry/pdf-registry.php';

$fp_pdf_email_notification_file = WP_CONTENT_DIR
    . '/themes/rayner_focalpoint_mgmt/includes/include_pdf_email_notification_functions.php';

if (is_readable($fp_pdf_email_notification_file)) {
    require_once $fp_pdf_email_notification_file;
}