<?php
/**
 * Membership Plans Handler - Optimized
 */
class MembershipPlans {
    
    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('pmpro_save_membership_level', [$this, 'sync_single_plan']);
        add_action('pmpro_delete_membership_level', [$this, 'delete_plan']);
        // add_action('admin_init', [$this, 'sync_all_plans']); // optional manual sync
    }

    /** Register CPT for synced plans */
    public function register_cpt() {
        register_post_type('membership_plan', [
            'labels' => [
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
            ],
            'public'       => true,
            'has_archive'  => true,
            'show_in_rest' => true,
            'show_ui'      => false, // Hide UI completely
            'show_in_menu' => false,
            'supports'     => ['title', 'editor', 'custom-fields'],
            'taxonomies'   => ['category'],
        ]);
    }

    /** Sync only one plan */
    public function sync_single_plan($level_id) {
        if (!function_exists('pmpro_getLevel')) return;
        $level = pmpro_getLevel($level_id);
        if (!$level) return;
    
        $post = $this->get_plan_by_pmpro_id($level_id);
    
        // Get all ordered data to know max index
        $ordered_data = $this->get_ordered_levels_data();
        $order_index = count($ordered_data); // default → last
    
        // Check if level_id already has a position in pmpro_level_order
        $level_order = array_filter(array_map('intval', explode(',', get_option('pmpro_level_order', ''))));
        $pos = array_search($level_id, $level_order);
        if ($pos !== false) {
            $order_index = $pos; // respect order
        }
    
        $this->save_plan($level, $post ? $post->ID : 0, $order_index);
    
        // Reorder all plans finally
        $this->reorder_all_plans();
    }
    

    /** Delete plan if PMPro level deleted */
    public function delete_plan($level_id) {
        $post = $this->get_plan_by_pmpro_id($level_id);
        if ($post) wp_delete_post($post->ID, true);
        $this->reorder_all_plans();
    }

    /** Full sync (manual or initial run) */
    public function sync_all_plans() {
        foreach ($this->get_ordered_levels_data() as $i => $data) {
            $this->save_plan($data['level'], $data['existing_post'] ? $data['existing_post']->ID : 0, $i);
        }
    }

    /** Save or update a membership plan post */
    private function save_plan($level, $post_id = 0, $order_index = 0) {
        $base_date = current_time('Y-m-d H:i:s');
        $post_date = date('Y-m-d H:i:s', strtotime($base_date . " +{$order_index} seconds"));

        $post_args = [
            'ID'           => $post_id,
            'post_title'   => $level->name,
            'post_content' => $level->description,
            'post_excerpt' => $level->description,
            'post_type'    => 'membership_plan',
            'post_status'  => 'publish',
            'post_date'    => $post_date,
            'post_date_gmt'=> get_gmt_from_date($post_date),
        ];

        $post_id = $post_id ? wp_update_post($post_args) : wp_insert_post($post_args);

        if ($post_id) {
            update_post_meta($post_id, 'pmpro_level_id', $level->id);

            update_field('plan_price', pmpro_getLevelCost($level, true, true), $post_id);

            if (!get_field('checkout_link', $post_id)) {
                update_field('checkout_link', $this->generate_checkout_link($level->id), $post_id);
            }

            // Featured image
            $img_url = get_option("pmpro_plan_image_{$level->id}", '');
            if ($img_url && ($attachment_id = attachment_url_to_postid($img_url))) {
                set_post_thumbnail($post_id, $attachment_id);
            }

            // Categories
            $this->assign_categories($post_id, $level->id);
        }
    }

    /** Reorder posts according to PMPro order */
    private function reorder_all_plans() {
        foreach ($this->get_ordered_levels_data() as $i => $data) {
            if ($data['existing_post']) {
                $this->save_plan($data['level'], $data['existing_post']->ID, $i);
            }
        }
    }

    /** Find plan post by PMPro level id */
    private function get_plan_by_pmpro_id($pmpro_id) {
        $posts = get_posts([
            'post_type'   => 'membership_plan',
            'meta_key'    => 'pmpro_level_id',
            'meta_value'  => $pmpro_id,
            'post_status' => 'any',
            'numberposts' => 1
        ]);
        return $posts ? $posts[0] : null;
    }

    private function get_ordered_levels_data() {
        if (!function_exists('pmpro_getAllLevels')) return [];
    
        // Ordered IDs from PMPro option
        $ordered_ids = array_filter(array_map('intval', explode(',', get_option('pmpro_level_order', ''))));
    
        // All levels
        $levels_by_id = [];
        foreach (pmpro_getAllLevels(true, true) as $l) {
            $levels_by_id[$l->id] = $l;
        }
    
        // Existing posts (with date info)
        $posts_by_id = [];
        foreach (get_posts([
            'post_type'   => 'membership_plan',
            'meta_key'    => 'pmpro_level_id',
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby'     => 'date',
            'order'       => 'ASC'
        ]) as $p) {
            $id = get_post_meta($p->ID, 'pmpro_level_id', true);
            if ($id) $posts_by_id[$id] = $p;
        }
    
        $data = [];
    
        // Ordered levels first
        foreach ($ordered_ids as $id) {
            if (isset($levels_by_id[$id])) {
                $data[] = [
                    'level' => $levels_by_id[$id],
                    'existing_post' => $posts_by_id[$id] ?? null
                ];
                unset($levels_by_id[$id]);
            }
        }
    
        // Remaining levels → follow their post_date order
        if (!empty($levels_by_id)) {
            // Build array with date reference
            $remaining = [];
            foreach ($levels_by_id as $lvl) {
                $date = isset($posts_by_id[$lvl->id]) ? $posts_by_id[$lvl->id]->post_date : current_time('mysql');
                $remaining[] = [
                    'level' => $lvl,
                    'existing_post' => $posts_by_id[$lvl->id] ?? null,
                    'date'  => $date
                ];
            }
            // Sort by date ASC
            usort($remaining, fn($a, $b) => strcmp($a['date'], $b['date']));
            foreach ($remaining as $r) {
                $data[] = ['level' => $r['level'], 'existing_post' => $r['existing_post']];
            }
        }
    
        return $data;
    }
    
    
    /** Build checkout link */
    private function generate_checkout_link($level_id) {
        return function_exists('pmpro_url') 
            ? pmpro_url('checkout', '?level=' . $level_id) 
            : add_query_arg('level', $level_id, home_url('/checkout/'));
    }

    /** Get groups and assign them as categories */
    private function assign_categories($post_id, $level_id) {
        global $wpdb;
        $groups = $wpdb->get_results($wpdb->prepare("
            SELECT g.name FROM {$wpdb->prefix}pmpro_membership_levels_groups lg
            INNER JOIN {$wpdb->prefix}pmpro_groups g ON lg.group = g.id
            WHERE lg.level = %d
        ", $level_id));

        $term_ids = [];
        foreach ($groups as $g) {
            $slug = sanitize_title($g->name);
            $term = get_term_by('slug', $slug, 'category');
            if (!$term) {
                $res = wp_insert_term($g->name, 'category', [
                    'slug' => $slug,
                    'description' => "Category for {$g->name} membership group"
                ]);
                if (!is_wp_error($res)) $term_ids[] = $res['term_id'];
            } else {
                $term_ids[] = $term->term_id;
            }
        }
        wp_set_post_terms($post_id, $term_ids, 'category');
    }
}
