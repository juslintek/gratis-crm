<?php declare(strict_types=1);
namespace Gratis\CRM\CLI;

final class CRMCommand
{
    /**
     * List contacts.
     * [--tag=<tag>] : Filter by tag.
     * @when after_wp_load
     */
    public function contacts($args, $assoc_args): void
    {
        $query = ['post_type' => 'gratis_contact', 'numberposts' => 50];
        if (!empty($assoc_args['tag'])) {
            $query['tax_query'] = [['taxonomy' => 'contact_tag', 'field' => 'slug', 'terms' => $assoc_args['tag']]];
        }
        $contacts = get_posts($query);
        if (empty($contacts)) { \WP_CLI::log("No contacts."); return; }
        $rows = array_map(fn($c) => [
            'ID' => $c->ID,
            'Name' => $c->post_title,
            'Email' => get_post_meta($c->ID, '_email', true),
            'Tags' => implode(', ', wp_get_object_terms($c->ID, 'contact_tag', ['fields' => 'names'])),
        ], $contacts);
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Name', 'Email', 'Tags']);
    }

    /**
     * Send email campaign to tagged contacts.
     * <tag> : Tag to send to.
     * <subject> : Email subject.
     * <body> : Email body.
     * @subcommand send-campaign
     * @when after_wp_load
     */
    public function send_campaign($args, $assoc_args): void
    {
        $contacts = get_posts(['post_type' => 'gratis_contact', 'numberposts' => -1,
            'tax_query' => [['taxonomy' => 'contact_tag', 'field' => 'slug', 'terms' => $args[0]]]]);
        if (empty($contacts)) { \WP_CLI::error("No contacts with tag: {$args[0]}"); }
        $sent = 0;
        foreach ($contacts as $c) {
            $email = get_post_meta($c->ID, '_email', true);
            if ($email && wp_mail($email, $args[1], $args[2])) $sent++;
        }
        \WP_CLI::success("Sent to {$sent}/" . count($contacts) . " contacts.");
    }
}
