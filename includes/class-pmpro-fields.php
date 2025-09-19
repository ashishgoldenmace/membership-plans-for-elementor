<?php
/**
 * Paid Memberships Pro Custom Fields
 * Adds plan image field to membership levels
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMProCustomFields {
    
    public function __construct() {
        add_action('pmpro_membership_level_after_other_settings', array($this, 'add_plan_image_field'));
        add_action('pmpro_save_membership_level', array($this, 'save_plan_image_field'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_media_script'));
    }
    
    public function add_plan_image_field() {
        $level_id = isset($_REQUEST['edit']) ? intval($_REQUEST['edit']) : 0;
        $image_url = get_option('pmpro_plan_image_' . $level_id, '');
        ?>
        <h3 class="topborder">Plan Image</h3>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row" valign="top">
                        <label for="plan_image">Plan Image</label>
                    </th>
                    <td>
                        <input type="text" name="plan_image" id="plan_image" value="<?php echo esc_attr($image_url); ?>" style="width:60%;" />
                        <button type="button" class="button upload_image_button">Select Image</button>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }
    
    public function save_plan_image_field($level_id) {
        if (isset($_POST['plan_image'])) {
            update_option('pmpro_plan_image_' . $level_id, sanitize_text_field($_POST['plan_image']));
        }
    }
    
    public function enqueue_media_script($hook) {
        if (strpos($hook, 'pmpro-membershiplevels') !== false) {
            wp_enqueue_media();
            wp_enqueue_script('jquery');
            
            // Add inline script
            wp_add_inline_script('jquery', '
                jQuery(document).ready(function($){
                    var mediaUploader;
                    $(".upload_image_button").on("click", function(e) {
                        e.preventDefault();
                        if (mediaUploader) {
                            mediaUploader.open();
                            return;
                        }
                        mediaUploader = wp.media.frames.file_frame = wp.media({
                            title: "Select or Upload Plan Image",
                            button: { text: "Use this image" },
                            multiple: false
                        });
                        mediaUploader.on("select", function() {
                            var attachment = mediaUploader.state().get("selection").first().toJSON();
                            $("#plan_image").val(attachment.url);
                        });
                        mediaUploader.open();
                    });
                });
            ');
        }
    }
}

// Initialize the class
new PMProCustomFields();
