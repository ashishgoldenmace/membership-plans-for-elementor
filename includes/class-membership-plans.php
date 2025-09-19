<?php
/**
 * Membership Plans Handler - Minimal OOP Version
 */
class MembershipPlans {
    
    public function __construct() {
        add_action('init', array($this, 'register_cpt'));
        add_action('init', array($this, 'sync_pmpro_plans'));
    }
    
    public function register_cpt() {
        register_post_type('membership_plan', array(
            'labels' => array(
                'name' => 'Membership Plans',
                'singular_name' => 'Membership Plan',
                'add_new' => 'Add New Plan',
                'add_new_item' => 'Add New Membership Plan',
                'edit_item' => 'Edit Membership Plan',
                'new_item' => 'New Membership Plan',
                'view_item' => 'View Membership Plan',
                'search_items' => 'Search Membership Plans',
                'not_found' => 'No plans found',
                'not_found_in_trash' => 'No plans found in Trash',
            ),
            'public' => true,
            'has_archive' => true,
            'show_in_rest' => true,
            'supports' => array('title', 'editor', 'custom-fields'),
        ));
    }
    
    public function sync_pmpro_plans() {
        if (!function_exists('pmpro_getAllLevels')) return;
        
        $levels = pmpro_getAllLevels(true, true);
        
        foreach ($levels as $level) {
            $existing_post = $this->get_plan_by_pmpro_id($level->id);
            
            if ($existing_post) {
                $this->update_plan($existing_post->ID, $level);
            } else {
                $this->create_plan($level);
            }
        }
    }
    
    private function get_plan_by_pmpro_id($pmpro_id) {
        $posts = get_posts(array(
            'post_type' => 'membership_plan',
            'meta_key' => 'pmpro_level_id',
            'meta_value' => $pmpro_id,
            'post_status' => 'any',
            'numberposts' => 1
        ));
        return !empty($posts) ? $posts[0] : null;
    }
    
    private function create_plan($level) {
        $post_id = wp_insert_post(array(
            'post_title'   => $level->name,
            'post_content' => $level->description,
            'post_excerpt' => $level->description,
            'post_type'    => 'membership_plan',
            'post_status'  => 'publish',
        ));
        
        if ($post_id) {
            update_post_meta($post_id, 'pmpro_level_id', $level->id);
            $price = pmpro_getLevelCost($level, true, true);
            update_field('plan_price', $price, $post_id);
            $checkout_link = $this->generate_checkout_link($level->id);
            update_field('checkout_link', $checkout_link, $post_id);
            
            // Add plan image from PMPro field
            $plan_image_url = get_option('pmpro_plan_image_' . $level->id, '');
            if ($plan_image_url) {
                $attachment_id = attachment_url_to_postid($plan_image_url);
                if ($attachment_id) {
                    update_field('plan_image', $attachment_id, $post_id);
                }
            }
        }
    }
    
    private function update_plan($post_id, $level) {
        wp_update_post(array(
            'ID'           => $post_id,
            'post_title'   => $level->name,
            'post_content' => $level->description,
            'post_excerpt' => $level->description,
        ));
        
        $price = pmpro_getLevelCost($level, true, true);
        update_field('plan_price', $price, $post_id);
        
        if (!get_field('checkout_link', $post_id)) {
            $checkout_link = $this->generate_checkout_link($level->id);
            update_field('checkout_link', $checkout_link, $post_id);
        }
        
        // Update plan image from PMPro field
        $plan_image_url = get_option('pmpro_plan_image_' . $level->id, '');
        if ($plan_image_url) {
            $attachment_id = attachment_url_to_postid($plan_image_url);
            if ($attachment_id) {
                update_field('plan_image', $attachment_id, $post_id);
            }
        }
    }
    
    private function generate_checkout_link($level_id) {
        if (function_exists('pmpro_url')) {
            return pmpro_url('checkout', '?level=' . $level_id);
        }
        
        $checkout_url = home_url('/checkout/');
        return add_query_arg('level', $level_id, $checkout_url);
    }
    
}
