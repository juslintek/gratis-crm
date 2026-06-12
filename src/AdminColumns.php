<?php declare(strict_types=1);

namespace Gratis\CRM;

defined('ABSPATH') || exit;

/**
 * Enhances the contacts admin list table: custom columns (email/phone/company),
 * sortable columns, a tag filter dropdown and email/phone/company search.
 */
final class AdminColumns {

    /**
     * Wire all list-table hooks.
     */
    public static function register(): void {
        $pt = ContactPostType::POST_TYPE;

        add_filter('manage_' . $pt . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . $pt . '_posts_custom_column', [__CLASS__, 'render_column'], 10, 2);
        add_filter('manage_edit-' . $pt . '_sortable_columns', [__CLASS__, 'sortable']);
        add_action('restrict_manage_posts', [__CLASS__, 'tag_filter']);
        add_action('pre_get_posts', [__CLASS__, 'apply_filters']);
        add_filter('posts_clauses', [__CLASS__, 'search_meta'], 10, 2);
    }

    /**
     * Define the list-table columns.
     *
     * @param array<string,string> $columns Existing columns.
     * @return array<string,string>
     */
    public static function columns(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            if ('title' === $key) {
                $new[$key]       = __('Name', 'gratis-crm');
                $new['email']    = __('Email', 'gratis-crm');
                $new['phone']    = __('Phone', 'gratis-crm');
                $new['company']  = __('Company', 'gratis-crm');
                continue;
            }
            $new[$key] = $label;
        }
        return $new;
    }

    /**
     * Render a custom column's value.
     *
     * @param string $column  Column key.
     * @param int    $post_id Contact ID.
     */
    public static function render_column(string $column, int $post_id): void {
        switch ($column) {
            case 'email':
                $email = (string) get_post_meta($post_id, '_email', true);
                if ('' !== $email) {
                    echo '<a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a>';
                } else {
                    echo '&mdash;';
                }
                break;
            case 'phone':
                $phone = (string) get_post_meta($post_id, '_phone', true);
                echo '' !== $phone ? esc_html($phone) : '&mdash;';
                break;
            case 'company':
                $company = (string) get_post_meta($post_id, '_company', true);
                echo '' !== $company ? esc_html($company) : '&mdash;';
                break;
        }
    }

    /**
     * Mark the email and company columns as sortable.
     *
     * @param array<string,string> $columns Sortable columns.
     * @return array<string,string>
     */
    public static function sortable(array $columns): array {
        $columns['email']   = 'email';
        $columns['company'] = 'company';
        return $columns;
    }

    /**
     * Render a tag filter dropdown above the contacts list.
     *
     * @param string $post_type Current screen post type.
     */
    public static function tag_filter(string $post_type): void {
        if (ContactPostType::POST_TYPE !== $post_type) {
            return;
        }

        $terms = get_terms(
            [
                'taxonomy'   => ContactPostType::TAXONOMY,
                'hide_empty' => false,
            ]
        );
        if (is_wp_error($terms) || empty($terms)) {
            return;
        }

        $current = isset($_GET[ContactPostType::TAXONOMY]) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash($_GET[ContactPostType::TAXONOMY])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';

        echo '<select name="' . esc_attr(ContactPostType::TAXONOMY) . '">';
        echo '<option value="">' . esc_html__('All tags', 'gratis-crm') . '</option>';
        foreach ($terms as $term) {
            printf(
                '<option value="%s"%s>%s (%s)</option>',
                esc_attr($term->slug),
                selected($current, $term->slug, false),
                esc_html($term->name),
                esc_html((string) absint($term->count))
            );
        }
        echo '</select>';
    }

    /**
     * Apply tag filtering and column sorting on the contacts list query.
     *
     * @param \WP_Query $query Current query.
     */
    public static function apply_filters(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if (ContactPostType::POST_TYPE !== $query->get('post_type')) {
            return;
        }

        $orderby = $query->get('orderby');
        if ('email' === $orderby || 'company' === $orderby) {
            $query->set('meta_key', '_' . $orderby); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Extend the admin contact search to also match email/phone/company meta.
     *
     * @param array<string,string> $clauses Query clauses.
     * @param \WP_Query             $query   Current query.
     * @return array<string,string>
     */
    public static function search_meta(array $clauses, \WP_Query $query): array {
        global $wpdb;

        if (!is_admin() || !$query->is_main_query()) {
            return $clauses;
        }
        if (ContactPostType::POST_TYPE !== $query->get('post_type')) {
            return $clauses;
        }
        $search = (string) $query->get('s');
        if ('' === $search) {
            return $clauses;
        }

        $like = '%' . $wpdb->esc_like($search) . '%';

        $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS gcrm_m ON ({$wpdb->posts}.ID = gcrm_m.post_id) ";

        // Widen the existing title search to also match the contact meta values.
        // Values are bound via prepare(); the meta-key list is a fixed literal.
        $meta_where = $wpdb->prepare(
            " OR (gcrm_m.meta_key IN ('_email','_phone','_company','_name') AND gcrm_m.meta_value LIKE %s) ",
            $like
        );

        if (!empty($clauses['where']) && false !== strpos($clauses['where'], 'post_title LIKE')) {
            // Inject the meta clause inside the search's OR group.
            $clauses['where'] = preg_replace(
                '/\(\s*' . preg_quote($wpdb->posts, '/') . '\.post_title LIKE/',
                '( 1=0 ' . $meta_where . ' OR ' . $wpdb->posts . '.post_title LIKE',
                $clauses['where'],
                1
            );
        }

        $clauses['groupby'] = "{$wpdb->posts}.ID";

        return $clauses;
    }
}
