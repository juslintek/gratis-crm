<?php declare(strict_types=1);

namespace Gratis\CRM;

defined('ABSPATH') || exit;

/**
 * Registers the gratis_contact custom post type, its tag taxonomy and the
 * contact field meta schema, and provides a de-duplicating create() helper.
 */
final class ContactPostType {

    public const POST_TYPE = 'gratis_contact';
    public const TAXONOMY  = 'gratis_contact_tag';

    /**
     * Contact detail meta keys and their scalar type for register_post_meta.
     *
     * @var array<string,string>
     */
    private const META = [
        '_email'   => 'string',
        '_name'    => 'string',
        '_phone'   => 'string',
        '_company' => 'string',
        '_notes'   => 'string',
        '_source'  => 'string',
        '_form_id' => 'integer',
        '_unsubscribed'    => 'string',
        '_unsubscribed_at' => 'string',
    ];

    /**
     * Register the post type, taxonomy and post meta.
     */
    public static function register(): void {
        register_post_type(
            self::POST_TYPE,
            [
                'labels'       => [
                    'name'               => __('Contacts', 'gratis-crm'),
                    'singular_name'      => __('Contact', 'gratis-crm'),
                    'add_new'            => __('Add Contact', 'gratis-crm'),
                    'add_new_item'       => __('Add New Contact', 'gratis-crm'),
                    'edit_item'          => __('Edit Contact', 'gratis-crm'),
                    'new_item'           => __('New Contact', 'gratis-crm'),
                    'view_item'          => __('View Contact', 'gratis-crm'),
                    'search_items'       => __('Search Contacts', 'gratis-crm'),
                    'not_found'          => __('No contacts found.', 'gratis-crm'),
                    'not_found_in_trash' => __('No contacts found in Trash.', 'gratis-crm'),
                    'all_items'          => __('All Contacts', 'gratis-crm'),
                    'menu_name'          => __('CRM', 'gratis-crm'),
                ],
                'public'       => false,
                'show_ui'      => true,
                'show_in_menu' => true,
                'show_in_rest' => false,
                'menu_icon'    => 'dashicons-groups',
                'menu_position' => 26,
                'supports'     => ['title'],
                'capability_type' => 'post',
                'map_meta_cap' => true,
            ]
        );

        register_taxonomy(
            self::TAXONOMY,
            self::POST_TYPE,
            [
                'labels'       => [
                    'name'          => __('Tags', 'gratis-crm'),
                    'singular_name' => __('Tag', 'gratis-crm'),
                ],
                'hierarchical' => false,
                'public'       => false,
                'show_ui'      => true,
                'show_admin_column' => true,
                'show_in_rest' => false,
            ]
        );

        self::register_meta();
    }

    /**
     * Register typed contact meta with sanitisation + auth.
     */
    private static function register_meta(): void {
        foreach (self::META as $key => $type) {
            $sanitize = '_notes' === $key
                ? 'sanitize_textarea_field'
                : ('_form_id' === $key ? 'absint' : 'sanitize_text_field');

            register_post_meta(
                self::POST_TYPE,
                $key,
                [
                    'type'              => $type,
                    'single'            => true,
                    'show_in_rest'      => false,
                    'sanitize_callback' => $sanitize,
                    'auth_callback'     => static function (): bool {
                        return current_user_can('edit_posts');
                    },
                ]
            );
        }
    }

    /**
     * The list of editable contact field meta keys.
     *
     * @return array<int,string>
     */
    public static function fields(): array {
        return ['_email', '_name', '_phone', '_company', '_notes'];
    }

    /**
     * Create a contact, or return the ID of an existing one with the same email.
     *
     * @param string               $email Contact email (used for de-duplication).
     * @param string               $name  Display name.
     * @param array<string,mixed>  $meta  Extra meta to store.
     */
    public static function create(string $email, string $name = '', array $meta = []): int {
        $email = sanitize_email($email);

        if ($email) {
            $existing = get_posts(
                [
                    'post_type'      => self::POST_TYPE,
                    'post_status'    => 'any',
                    'meta_key'       => '_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'numberposts'    => 1,
                    'fields'         => 'ids',
                ]
            );
            if (!empty($existing)) {
                return (int) $existing[0];
            }
        }

        $id = wp_insert_post(
            [
                'post_type'   => self::POST_TYPE,
                'post_title'  => $name ?: $email,
                'post_status' => 'publish',
                'meta_input'  => array_merge(['_email' => $email, '_name' => $name], $meta),
            ]
        );

        return is_wp_error($id) ? 0 : (int) $id;
    }
}
