<?php declare(strict_types=1);
namespace Gratis\CRM;

final class ContactPostType
{
    public static function register(): void
    {
        register_post_type('gratis_contact', [
            'labels'      => ['name' => 'Contacts', 'singular_name' => 'Contact'],
            'public'      => false,
            'show_ui'     => true,
            'show_in_rest'=> true,
            'menu_icon'   => 'dashicons-groups',
            'supports'    => ['title', 'custom-fields'],
        ]);

        register_taxonomy('contact_tag', 'gratis_contact', [
            'labels'       => ['name' => 'Tags'],
            'hierarchical' => false,
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
        ]);
    }

    public static function create(string $email, string $name = '', array $meta = []): int
    {
        $existing = get_posts(['post_type' => 'gratis_contact', 'meta_key' => '_email', 'meta_value' => $email, 'numberposts' => 1]);
        if ($existing) return $existing[0]->ID;

        return wp_insert_post([
            'post_type'   => 'gratis_contact',
            'post_title'  => $name ?: $email,
            'post_status' => 'publish',
            'meta_input'  => array_merge(['_email' => $email, '_name' => $name], $meta),
        ]);
    }
}
