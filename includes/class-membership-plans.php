<?php
/**
 * Membership Plans Handler - Minimal OOP Version
 */
class MembershipPlans {
    
    public function __construct() {
        add_action('init', array($this, 'register_cpt'));
        
        // Only sync when PMPro levels are created, updated, or deleted
        add_action('pmpro_save_membership_level', array($this, 'sync_single_plan'));
        add_action('pmpro_delete_membership_level', array($this, 'delete_plan'));
        
        // // Manual sync on admin init (optional)
        // add_action('admin_init', array($this, 'sync_all_plans'));    
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
            'show_in_menu' => false, // Hide from admin menu
            'show_ui' => false, // Hide UI completely
            'supports' => array('title', 'editor', 'custom-fields'),
            'taxonomies' => array('category'),
        ));
    }
    
    
    public function sync_single_plan($level_id) {
        if (!function_exists('pmpro_getLevel')) return;
        
        $level = pmpro_getLevel($level_id);
        if (!$level) return;
        
        $existing_post = $this->get_plan_by_pmpro_id($level_id);
        
        if ($existing_post) {
            $this->update_plan($existing_post->ID, $level);
        } else {
            $this->create_plan($level);
        }
        
        // Reorder all plans after updating/creating a single plan
        $this->reorder_all_plans();
    }
    
    public function delete_plan($level_id) {
        $existing_post = $this->get_plan_by_pmpro_id($level_id);
        if ($existing_post) {
            wp_delete_post($existing_post->ID, true);
        }
        
        // Reorder all remaining plans after deletion
        $this->reorder_all_plans();
    }
    
    public function sync_all_plans() {
        $ordered_data = $this->get_ordered_levels_data();
        
        foreach ($ordered_data as $index => $data) {
            if ($data['existing_post']) {
                $this->update_plan($data['existing_post']->ID, $data['level'], $index);
            } else {
                $this->create_plan($data['level'], $index);
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
    
    /**
     * Get ordered levels data with existing posts in one optimized method
     */
    private function get_ordered_levels_data() {
        if (!function_exists('pmpro_getAllLevels')) return array();
        
        // Get the level order from PMPro settings
        $level_order = get_option('pmpro_level_order', '');
        $ordered_level_ids = array();
        
        if (!empty($level_order)) {
            $ordered_level_ids = array_map('intval', explode(',', $level_order));
        }
        
        // Get all levels and create lookup array
        $all_levels = pmpro_getAllLevels(true, true);
        $levels_by_id = array();
        foreach ($all_levels as $level) {
            $levels_by_id[$level->id] = $level;
        }
        
        // Get all existing posts in one query
        $existing_posts = get_posts(array(
            'post_type' => 'membership_plan',
            'meta_key' => 'pmpro_level_id',
            'post_status' => 'any',
            'numberposts' => -1
        ));
        
        $posts_by_pmpro_id = array();
        foreach ($existing_posts as $post) {
            $pmpro_id = get_post_meta($post->ID, 'pmpro_level_id', true);
            if ($pmpro_id) {
                $posts_by_pmpro_id[$pmpro_id] = $post;
            }
        }
        
        $ordered_data = array();
        
        // Process levels in the specified order
        foreach ($ordered_level_ids as $level_id) {
            if (isset($levels_by_id[$level_id])) {
                $ordered_data[] = array(
                    'level' => $levels_by_id[$level_id],
                    'existing_post' => isset($posts_by_pmpro_id[$level_id]) ? $posts_by_pmpro_id[$level_id] : null
                );
                unset($levels_by_id[$level_id]);
            }
        }
        
        // Process remaining levels
        foreach ($levels_by_id as $level) {
            $ordered_data[] = array(
                'level' => $level,
                'existing_post' => isset($posts_by_pmpro_id[$level->id]) ? $posts_by_pmpro_id[$level->id] : null
            );
        }
        
        return $ordered_data;
    }
    
    /**
     * Reorder all existing plans according to PMPro level order
     */
    private function reorder_all_plans() {
        $ordered_data = $this->get_ordered_levels_data();
        
        foreach ($ordered_data as $index => $data) {
            if ($data['existing_post']) {
                $this->update_plan($data['existing_post']->ID, $data['level'], $index);
            }
        }
    }
    
    private function create_plan($level, $order_index = 0) {
        // Calculate post date based on order index to maintain proper ordering
        $base_date = current_time('Y-m-d H:i:s');
        $post_date = date('Y-m-d H:i:s', strtotime($base_date . ' +' . $order_index . ' seconds'));
        
        $post_id = wp_insert_post(array(
            'post_title'   => $level->name,
            'post_content' => $level->description,
            'post_excerpt' => $level->description,
            'post_type'    => 'membership_plan',
            'post_status'  => 'publish',
            'post_date'    => $post_date,
            'post_date_gmt' => get_gmt_from_date($post_date),
        ));
        
        if ($post_id) {
            update_post_meta($post_id, 'pmpro_level_id', $level->id);
            $price = pmpro_getLevelCost($level, true, true);
            update_field('plan_price', $price, $post_id);
            $checkout_link = $this->generate_checkout_link($level->id);
            update_field('checkout_link', $checkout_link, $post_id);
            
            // Add plan image as featured image from PMPro field
            $plan_image_url = get_option('pmpro_plan_image_' . $level->id, '');
            if ($plan_image_url) {
                $attachment_id = attachment_url_to_postid($plan_image_url);
                if ($attachment_id) {
                    set_post_thumbnail($post_id, $attachment_id);
                }
            }
            
            // Create categories and assign them to the plan post
            $this->create_categories_for_level($level->id);
        }
    }
    
    private function update_plan($post_id, $level, $order_index = 0) {
        // Calculate post date based on order index to maintain proper ordering
        $base_date = current_time('Y-m-d H:i:s');
        $post_date = date('Y-m-d H:i:s', strtotime($base_date . ' +' . $order_index . ' seconds'));
        
        wp_update_post(array(
            'ID'           => $post_id,
            'post_title'   => $level->name,
            'post_content' => $level->description,
            'post_excerpt' => $level->description,
            'post_date'    => $post_date,
            'post_date_gmt' => get_gmt_from_date($post_date),
        ));
        
        $price = pmpro_getLevelCost($level, true, true);
        update_field('plan_price', $price, $post_id);
        
        if (!get_field('checkout_link', $post_id)) {
            $checkout_link = $this->generate_checkout_link($level->id);
            update_field('checkout_link', $checkout_link, $post_id);
        }
        
        // Update plan image as featured image from PMPro field
        $plan_image_url = get_option('pmpro_plan_image_' . $level->id, '');
        if ($plan_image_url) {
            $attachment_id = attachment_url_to_postid($plan_image_url);
            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
        
        // Create/update categories and assign them to the plan post
        $this->create_categories_for_level($level->id);
    }
    
    private function generate_checkout_link($level_id) {
        if (function_exists('pmpro_url')) {
            return pmpro_url('checkout', '?level=' . $level_id);
        }
        
        $checkout_url = home_url('/checkout/');
        return add_query_arg('level', $level_id, $checkout_url);
    }
 
    private function get_groups_by_level_id($level_id) {
        global $wpdb;
    
        $table_groups = $wpdb->prefix . 'pmpro_groups';
        $table_levels_groups = $wpdb->prefix . 'pmpro_membership_levels_groups';

        $results = $wpdb->get_results(
            $wpdb->prepare("
                SELECT g.id as group_id, g.name as group_name
                FROM $table_levels_groups lg
                INNER JOIN $table_groups g ON lg.group = g.id
                WHERE lg.level = %d
            ", $level_id)
        );
    
        return $results;
    }
    
    private function create_categories_for_level($level_id) {
        $groups = $this->get_groups_by_level_id($level_id);
        
        if (empty($groups)) {
            $plan_post = $this->get_plan_by_pmpro_id($level_id);
            if ($plan_post) {
                wp_set_post_terms($plan_post->ID, array(), 'category');
            }
            return;
        }

        $plan_post = $this->get_plan_by_pmpro_id($level_id);
        if (!$plan_post) return;
    
        $term_ids = array();
    
        foreach ($groups as $group) {
            $category_name = sanitize_text_field($group->group_name);
            $category_slug = sanitize_title($group->group_name);
    
            // Check if category already exists
            $existing_category = get_term_by('slug', $category_slug, 'category');
    
            if (!$existing_category) {
                $category_data = wp_insert_term(
                    $category_name,
                    'category',
                    array(
                        'slug' => $category_slug,
                        'description' => 'Category for ' . $category_name . ' membership group'
                    )
                );
    
                if (!is_wp_error($category_data)) {
                    $term_ids[] = $category_data['term_id'];
                }
            } else {
                $term_ids[] = $existing_category->term_id;
            }
        }
    
        wp_set_post_terms($plan_post->ID, $term_ids, 'category');
    }  
    
}
