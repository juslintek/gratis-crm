<?php declare(strict_types=1);

namespace Gratis\CRM;

defined('ABSPATH') || exit;

/**
 * Renders and saves the "Contact Details" meta box (name, email, phone,
 * company, notes) on the single contact edit screen.
 */
final class MetaBox {

    private const NONCE_ACTION = 'gratis_crm_save_contact';
    private const NONCE_FIELD  = 'gratis_crm_contact_nonce';

    /**
     * Register the meta box.
     */
    public static function register(): void {
        add_meta_box(
            'gratis_crm_contact_details',
            __('Contact Details', 'gratis-crm'),
            [__CLASS__, 'render'],
            ContactPostType::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Render the meta box fields.
     *
     * @param \WP_Post $post Current contact post.
     */
    public static function render(\WP_Post $post): void {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $email   = (string) get_post_meta($post->ID, '_email', true);
        $name    = (string) get_post_meta($post->ID, '_name', true);
        $phone   = (string) get_post_meta($post->ID, '_phone', true);
        $company = (string) get_post_meta($post->ID, '_company', true);
        $notes   = (string) get_post_meta($post->ID, '_notes', true);
        $source  = (string) get_post_meta($post->ID, '_source', true);

        echo '<table class="form-table" role="presentation"><tbody>';

        self::text_row('gratis_crm_name', __('Name', 'gratis-crm'), $name);
        self::text_row('gratis_crm_email', __('Email', 'gratis-crm'), $email, 'email');
        self::text_row('gratis_crm_phone', __('Phone', 'gratis-crm'), $phone, 'tel');
        self::text_row('gratis_crm_company', __('Company', 'gratis-crm'), $company);

        echo '<tr>';
        echo '<th scope="row"><label for="gratis_crm_notes">' . esc_html__('Notes', 'gratis-crm') . '</label></th>';
        echo '<td><textarea id="gratis_crm_notes" name="gratis_crm_notes" rows="5" class="large-text">'
            . esc_textarea($notes) . '</textarea></td>';
        echo '</tr>';

        if ('' !== $source) {
            echo '<tr><th scope="row">' . esc_html__('Source', 'gratis-crm') . '</th><td><code>'
                . esc_html($source) . '</code></td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Output a single labelled text input row.
     */
    private static function text_row(string $id, string $label, string $value, string $type = 'text'): void {
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input type="' . esc_attr($type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($id)
            . '" value="' . esc_attr($value) . '" class="regular-text" /></td>';
        echo '</tr>';
    }

    /**
     * Persist the contact field values on save.
     *
     * @param int      $post_id Contact post ID.
     * @param \WP_Post $post    Contact post object.
     */
    public static function save(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if ($post->post_type !== ContactPostType::POST_TYPE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        // Nonce verified above; helper reads are within this same guarded scope.
        $email   = isset($_POST['gratis_crm_email']) ? sanitize_email(wp_unslash($_POST['gratis_crm_email'])) : '';
        $name    = isset($_POST['gratis_crm_name']) ? sanitize_text_field(wp_unslash($_POST['gratis_crm_name'])) : '';
        $phone   = isset($_POST['gratis_crm_phone']) ? sanitize_text_field(wp_unslash($_POST['gratis_crm_phone'])) : '';
        $company = isset($_POST['gratis_crm_company']) ? sanitize_text_field(wp_unslash($_POST['gratis_crm_company'])) : '';
        $notes   = isset($_POST['gratis_crm_notes']) ? sanitize_textarea_field(wp_unslash($_POST['gratis_crm_notes'])) : '';

        update_post_meta($post_id, '_email', $email);
        update_post_meta($post_id, '_name', $name);
        update_post_meta($post_id, '_phone', $phone);
        update_post_meta($post_id, '_company', $company);
        update_post_meta($post_id, '_notes', $notes);

        // Keep the post title in sync with the contact's name/email so the
        // native admin search and lists show a meaningful label.
        $title = $name ?: $email;
        if ('' !== $title && $title !== $post->post_title) {
            remove_action('save_post_' . ContactPostType::POST_TYPE, [__CLASS__, 'save'], 10);
            wp_update_post(['ID' => $post_id, 'post_title' => $title]);
            add_action('save_post_' . ContactPostType::POST_TYPE, [__CLASS__, 'save'], 10, 2);
        }
    }
}
