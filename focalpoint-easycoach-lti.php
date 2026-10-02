<?php
/**
 * Plugin Name: Focal Point EasyCoach LTI
 * Description: Shared LTI 1.3 platform foundation for the Focal Point multisite and EasyCoach.
 * Version: 0.1.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FP_EASYCOACH_LTI_VERSION', '0.1.0');
define('FP_EASYCOACH_LTI_DIR', WPMU_PLUGIN_DIR . '/focalpoint-easycoach-lti');

require_once FP_EASYCOACH_LTI_DIR . '/includes/class-configuration.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/class-rest-controller.php';
require_once FP_EASYCOACH_LTI_DIR . '/includes/class-plugin.php';

FocalPoint_EasyCoach_LTI_Plugin::boot();

