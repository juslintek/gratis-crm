<?php declare(strict_types=1);

namespace Gratis\CRM\CLI;

use Gratis\CRM\ContactPostType;
use Gratis\CRM\Broadcast;

defined('ABSPATH') || exit;

/**
 * WP-CLI commands for the Gratis CRM.
 */
final class CRMCommand {

    /**
     * List contacts.
     *
     * [--tag=<tag>]
     * : Filter by tag slug.
     *
     * [--search=<term>]
     * : Filter by a name/email search term.
     *
     * @param array<int,string>    $args       Positional args (unused).
     * @param array<string,string> $assoc_args Associative args.
     * @when after_wp_load
     */
    public function contacts($args, $assoc_args): void {
        unset($args);

        $query = [
            'post_type'      => ContactPostType::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 50, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
        ];
        if (!empty($assoc_args['tag'])) {
            $query['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => ContactPostType::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => sanitize_key($assoc_args['tag']),
                ],
            ];
        }
        if (!empty($assoc_args['search'])) {
            $query['s'] = sanitize_text_field($assoc_args['search']);
        }

        $contacts = get_posts($query);
        if (empty($contacts)) {
            \WP_CLI::log('No contacts found.');
            return;
        }

        $rows = array_map(
            static function ($c) {
                $tags = wp_get_object_terms($c->ID, ContactPostType::TAXONOMY, ['fields' => 'names']);
                return [
                    'ID'      => $c->ID,
                    'Name'    => $c->post_title,
                    'Email'   => get_post_meta($c->ID, '_email', true),
                    'Phone'   => get_post_meta($c->ID, '_phone', true),
                    'Company' => get_post_meta($c->ID, '_company', true),
                    'Tags'    => is_wp_error($tags) ? '' : implode(', ', $tags),
                ];
            },
            $contacts
        );
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Name', 'Email', 'Phone', 'Company', 'Tags']);
    }

    /**
     * Create or update a contact.
     *
     * <email>
     * : Contact email address.
     *
     * [--name=<name>]
     * : Contact display name.
     *
     * [--phone=<phone>]
     * : Contact phone number.
     *
     * [--company=<company>]
     * : Contact company.
     *
     * [--tag=<tag>]
     * : Tag slug to apply.
     *
     * @param array<int,string>    $args       Positional args.
     * @param array<string,string> $assoc_args Associative args.
     * @subcommand add-contact
     * @when after_wp_load
     */
    public function add_contact($args, $assoc_args): void {
        $email = sanitize_email($args[0] ?? '');
        if (!$email) {
            \WP_CLI::error('A valid email address is required.');
        }

        $meta = [];
        if (!empty($assoc_args['phone'])) {
            $meta['_phone'] = sanitize_text_field($assoc_args['phone']);
        }
        if (!empty($assoc_args['company'])) {
            $meta['_company'] = sanitize_text_field($assoc_args['company']);
        }

        $id = ContactPostType::create($email, sanitize_text_field($assoc_args['name'] ?? ''), $meta);
        if (!$id) {
            \WP_CLI::error('Failed to create the contact.');
        }
        if (!empty($assoc_args['tag'])) {
            wp_set_object_terms($id, [sanitize_key($assoc_args['tag'])], ContactPostType::TAXONOMY, true);
        }

        \WP_CLI::success("Contact #{$id} saved.");
    }

    /**
     * Export contacts to a CSV file.
     *
     * <file>
     * : Destination CSV file path.
     *
     * [--tag=<tag>]
     * : Only export contacts with this tag slug.
     *
     * @param array<int,string>    $args       Positional args.
     * @param array<string,string> $assoc_args Associative args.
     * @when after_wp_load
     */
    public function export($args, $assoc_args): void {
        $file = $args[0] ?? '';
        if ('' === $file) {
            \WP_CLI::error('A destination file path is required.');
        }

        $query = [
            'post_type'      => ContactPostType::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
        ];
        if (!empty($assoc_args['tag'])) {
            $query['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => ContactPostType::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => sanitize_key($assoc_args['tag']),
                ],
            ];
        }

        $contacts = get_posts($query);

        // Local file write for a CLI export; WP_Filesystem is unnecessary here.
        $out = fopen($file, 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (false === $out) {
            \WP_CLI::error("Could not open {$file} for writing.");
        }

        fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Company', 'Tags']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
        foreach ($contacts as $c) {
            $tags = wp_get_object_terms($c->ID, ContactPostType::TAXONOMY, ['fields' => 'names']);
            fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
                $out,
                [
                    $c->ID,
                    $c->post_title,
                    get_post_meta($c->ID, '_email', true),
                    get_post_meta($c->ID, '_phone', true),
                    get_post_meta($c->ID, '_company', true),
                    is_wp_error($tags) ? '' : implode(', ', $tags),
                ]
            );
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        \WP_CLI::success(count($contacts) . " contacts exported to {$file}.");
    }

    /**
     * Send an email broadcast to contacts with a tag (synchronously).
     *
     * Routed through the broadcast queue, so unsubscribed contacts are skipped
     * and each message includes a per-contact unsubscribe link. The body may
     * contain basic HTML.
     *
     * <tag>
     * : Tag slug to send to.
     *
     * <subject>
     * : Email subject.
     *
     * <body>
     * : Email body (basic HTML allowed).
     *
     * @param array<int,string>    $args       Positional args.
     * @param array<string,string> $assoc_args Associative args (unused).
     * @subcommand send-campaign
     * @when after_wp_load
     */
    public function send_campaign($args, $assoc_args): void {
        unset($assoc_args);

        $tag     = sanitize_key($args[0] ?? '');
        $subject = sanitize_text_field($args[1] ?? '');
        $body    = wp_kses_post($args[2] ?? '');

        if ('' === $tag || '' === $subject || '' === $body) {
            \WP_CLI::error('Usage: wp gratis-crm send-campaign <tag> <subject> <body>');
        }

        $result = Broadcast::enqueue($subject, $body, $tag);
        if (0 === $result['total']) {
            \WP_CLI::error("No eligible contacts with tag: {$tag}");
        }

        $stats = Broadcast::drain();
        \WP_CLI::success(
            "Sent to {$stats['sent']}/{$result['total']} contacts "
            . "({$stats['failed']} failed, {$stats['skipped']} skipped)."
        );
    }

    /**
     * Queue a broadcast email to contacts.
     *
     * [--subject=<subject>]
     * : Email subject.
     *
     * [--body=<body>]
     * : Email body (basic HTML allowed).
     *
     * [--tag=<tag>]
     * : Only send to contacts with this tag slug. Omit to send to all contacts.
     *
     * [--now]
     * : Process the broadcast immediately instead of leaving it for wp-cron.
     *
     * @param array<int,string>    $args       Positional args (unused).
     * @param array<string,string> $assoc_args Associative args.
     * @when after_wp_load
     */
    public function broadcast($args, $assoc_args): void {
        unset($args);

        $subject = sanitize_text_field($assoc_args['subject'] ?? '');
        $body    = isset($assoc_args['body']) ? wp_kses_post($assoc_args['body']) : '';
        $tag     = isset($assoc_args['tag']) ? sanitize_key($assoc_args['tag']) : '';

        if ('' === $subject || '' === $body) {
            \WP_CLI::error('Both --subject and --body are required.');
        }

        $result = Broadcast::enqueue($subject, $body, $tag);
        if (0 === $result['total']) {
            \WP_CLI::error('No eligible recipients match the filter.');
        }

        \WP_CLI::success("Queued broadcast {$result['id']} for {$result['total']} recipient(s).");

        if (isset($assoc_args['now'])) {
            $stats = Broadcast::drain();
            \WP_CLI::success(
                "Processed: {$stats['sent']} sent, {$stats['failed']} failed, {$stats['skipped']} skipped."
            );
        }
    }

    /**
     * Process the pending broadcast queue now.
     *
     * Drains any queued broadcasts in capped batches without waiting for the
     * wp-cron schedule. Useful when driven from a real system cron via WP-CLI.
     *
     * [--max=<batches>]
     * : Maximum number of batches to run (default 1000).
     *
     * @param array<int,string>    $args       Positional args (unused).
     * @param array<string,string> $assoc_args Associative args.
     * @subcommand process-broadcast
     * @when after_wp_load
     */
    public function process_broadcast($args, $assoc_args): void {
        unset($args);

        $max   = isset($assoc_args['max']) ? max(1, (int) $assoc_args['max']) : 1000;
        $stats = Broadcast::drain($max);

        $processed = $stats['sent'] + $stats['failed'] + $stats['skipped'];
        if (0 === $processed) {
            \WP_CLI::log('No pending broadcasts to process.');
            return;
        }

        \WP_CLI::success(
            "Processed: {$stats['sent']} sent, {$stats['failed']} failed, "
            . "{$stats['skipped']} skipped. Remaining: {$stats['remaining']}."
        );
    }
}
