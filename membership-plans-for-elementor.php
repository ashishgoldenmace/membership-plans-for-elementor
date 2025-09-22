<?php
/**
 * Plugin Name: Membership Plans For Elementor
 * Description: Sync Paid Memberships Pro plans with Elementor.
 * Version: 1.0
 * Author: Michael
 * Text Domain: membership-plans-for-elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define plugin path
define( 'MPS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// Include class files
require_once MPS_PLUGIN_PATH . 'includes/class-membership-plans.php';
require_once MPS_PLUGIN_PATH . 'includes/class-pmpro-fields.php';

// Initialize plugin
function mps_init_membership_plans() {
    new MembershipPlans();
}
add_action( 'plugins_loaded', 'mps_init_membership_plans' );

// add_Action('init', function() {
//     $levels = pmpro_getAllLevels(true, true);
//     echo '<pre>';
//     print_r($levels);
//     die();
// });