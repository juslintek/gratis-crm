<?php declare(strict_types=1);

namespace Gratis\CRM;

defined('ABSPATH') || exit;

/**
 * Streams all contacts (optionally filtered by tag) as a CSV download via an
 * admin-post action, and renders the "Export CSV" button on the contacts list.
 */
final class Export {

    private const ACTION = 'gratis_crm_export';

    /**
     * Wire the export action and the list-table button.
     */
    public static function register(): void {
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('manage_posts_extra_tablenav', [__CLASS__, 'button']);
    }

    /**
     * Render an "Export CSV" button on the top of the contacts list table.
     *
     * @param string $which Which tablenav ("top" or "bottom").
     */
    public static function button(string $which): void {
        if ('top' !== $which) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || 'edit-' . ContactPostType::POST_TYPE !== $screen->id) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $tag = isset($_GET[ContactPostType::TAXONOMY]) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash($_GET[ContactPostType::TAXONOMY])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';

        $url = wp_nonce_url(
            add_query_arg(
                array_filter(['action' => self::ACTION, ContactPostType::TAXONOMY => $tag]),
                admin_url('admin-post.php')
            ),
            self::ACTION
        );

        echo '<div class="alignleft actions">';
        echo '<a href="' . esc_url($url) . '" class="button">' . esc_html__('Export CSV', 'gratis-crm') . '</a>';
        echo '</div>';
    }

    /**
     * Validate the request and stream the CSV.
     */
    public static function handle(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to export contacts.', 'gratis-crm'));
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::ACTION)) {
            wp_die(esc_html__('Invalid request.', 'gratis-crm'));
        }

        $tag = isset($_GET[ContactPostType::TAXONOMY])
            ? sanitize_key(wp_unslash($_GET[ContactPostType::TAXONOMY]))
            : '';

        self::stream($tag);
    }

    /**
     * Neutralize leading spreadsheet formula triggers in exported CSV cells.
     */
    private static function csvCell(string $value): string {
        if ('' !== $value && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Stream contacts as CSV to the browser.
     *
     * @param string $tag Optional tag slug to filter by.
     */
    private static function stream(string $tag): void {
        $args = [
            'post_type'      => ContactPostType::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 10000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if ('' !== $tag) {
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => ContactPostType::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $tag,
                ],
            ];
        }

        $contacts = get_posts($args);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contacts-' . gmdate('Ymd') . '.csv"');

        // Streaming CSV to php://output requires a direct stream handle;
        // WP_Filesystem cannot write to the output buffer.
        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Company', 'Tags', 'Source', 'Notes', 'Created'], ',', '"', ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv

        foreach ($contacts as $contact) {
            $tags = wp_get_object_terms($contact->ID, ContactPostType::TAXONOMY, ['fields' => 'names']);
            $tags = is_wp_error($tags) ? [] : $tags;

            fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
                $out,
                [
                    (int) $contact->ID,
                    self::csvCell((string) get_post_meta($contact->ID, '_name', true) ?: $contact->post_title),
                    self::csvCell((string) get_post_meta($contact->ID, '_email', true)),
                    self::csvCell((string) get_post_meta($contact->ID, '_phone', true)),
                    self::csvCell((string) get_post_meta($contact->ID, '_company', true)),
                    self::csvCell(implode(', ', $tags)),
                    self::csvCell((string) get_post_meta($contact->ID, '_source', true)),
                    self::csvCell((string) get_post_meta($contact->ID, '_notes', true)),
                    (string) $contact->post_date,
                ],
                ',',
                '"',
                ''
            );
        }

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }
}
