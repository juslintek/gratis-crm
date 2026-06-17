<?php declare(strict_types=1);

namespace Gratis\CRM;

defined('ABSPATH') || exit;

/**
 * Broadcast email.
 *
 * Provides an admin compose screen (subject + HTML body, optional tag filter,
 * recipient-count preview), a queue stored in an option, and a self-rescheduling
 * wp-cron worker that sends a capped batch per tick via wp_mail() while keeping
 * a sent/failed/skipped log. Each message gets a signed per-contact unsubscribe
 * link, and unsubscribed contacts are excluded from every send.
 */
final class Broadcast {

    /** Option holding all broadcast records, keyed by broadcast ID. */
    private const OPTION = 'gratis_crm_broadcasts';

    /** Cron hook that drains the queue. */
    private const CRON_HOOK = 'gratis_crm_process_broadcasts';

    /** Transient used as a best-effort processing lock. */
    private const LOCK = 'gratis_crm_broadcast_lock';

    /** Capability required to compose and send broadcasts. */
    private const CAP = 'manage_options';

    /** Admin submenu page slug. */
    public const PAGE_SLUG = 'gratis-crm-broadcast';

    /** Recipients processed per cron tick (keeps each tick well under timeouts). */
    private const BATCH_SIZE = 25;

    /** Maximum log rows retained per broadcast. */
    private const LOG_CAP = 500;

    /** Maximum broadcast records retained in history. */
    private const HISTORY_CAP = 20;

    /** Seconds to wait before the next queued cron tick. */
    private const CRON_DELAY = 10;

    /**
     * Register the admin submenu page.
     */
    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . ContactPostType::POST_TYPE,
            __('Broadcast Email', 'gratis-crm'),
            __('Broadcast', 'gratis-crm'),
            self::CAP,
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Render (and process submissions from) the broadcast compose screen.
     */
    public static function render_page(): void {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You do not have permission to send broadcasts.', 'gratis-crm'));
        }

        $subject       = '';
        $body          = '';
        $tag           = '';
        $notice        = '';
        $notice_type   = 'info';
        $preview_count = null;

        if (isset($_POST['gratis_crm_broadcast_nonce'])) {
            check_admin_referer('gratis_crm_broadcast', 'gratis_crm_broadcast_nonce');

            $subject = isset($_POST['gratis_crm_subject'])
                ? sanitize_text_field(wp_unslash($_POST['gratis_crm_subject']))
                : '';
            // wp_kses_post() both sanitises and expects unslashed input.
            $body = isset($_POST['gratis_crm_body'])
                ? wp_kses_post(wp_unslash($_POST['gratis_crm_body']))
                : '';
            $tag = isset($_POST['gratis_crm_tag'])
                ? sanitize_key(wp_unslash($_POST['gratis_crm_tag']))
                : '';

            $preview_count = self::count_recipients($tag);

            if (isset($_POST['gratis_crm_send'])) {
                if ('' === $subject || '' === trim(wp_strip_all_tags($body))) {
                    $notice      = __('Please provide both a subject and a body before sending.', 'gratis-crm');
                    $notice_type = 'error';
                } elseif (0 === $preview_count) {
                    $notice      = __('No eligible recipients match the selected filter.', 'gratis-crm');
                    $notice_type = 'error';
                } else {
                    $result = self::enqueue($subject, $body, $tag);
                    $notice = sprintf(
                        /* translators: %d: number of queued recipients. */
                        __('Broadcast queued for %d recipient(s). Delivery runs in the background.', 'gratis-crm'),
                        (int) $result['total']
                    );
                    $notice_type = 'success';

                    // Clear the form after a successful queue.
                    $subject       = '';
                    $body          = '';
                    $tag           = '';
                    $preview_count = null;
                }
            }
        }

        if (null === $preview_count) {
            $preview_count = self::count_recipients($tag);
        }

        $terms = get_terms(
            [
                'taxonomy'   => ContactPostType::TAXONOMY,
                'hide_empty' => false,
            ]
        );
        $terms = is_wp_error($terms) ? [] : $terms;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Broadcast Email', 'gratis-crm') . '</h1>';
        echo '<p>' . esc_html__('Send an email to your contacts. An unsubscribe link is added to every message automatically.', 'gratis-crm') . '</p>';

        if ('' !== $notice) {
            printf(
                '<div class="notice notice-%s"><p>%s</p></div>',
                esc_attr($notice_type),
                esc_html($notice)
            );
        }

        echo '<form method="post">';
        wp_nonce_field('gratis_crm_broadcast', 'gratis_crm_broadcast_nonce');

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr>';
        echo '<th scope="row"><label for="gratis_crm_subject">' . esc_html__('Subject', 'gratis-crm') . '</label></th>';
        echo '<td><input type="text" id="gratis_crm_subject" name="gratis_crm_subject" value="'
            . esc_attr($subject) . '" class="large-text" required /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="gratis_crm_tag">' . esc_html__('Filter by tag', 'gratis-crm') . '</label></th>';
        echo '<td><select id="gratis_crm_tag" name="gratis_crm_tag">';
        echo '<option value="">' . esc_html__('All contacts', 'gratis-crm') . '</option>';
        foreach ($terms as $term) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($term->slug),
                selected($tag, $term->slug, false),
                esc_html($term->name)
            );
        }
        echo '</select>';
        echo '<p class="description">';
        printf(
            /* translators: %s: number of eligible recipients. */
            esc_html__('Eligible recipients matching the current filter: %s', 'gratis-crm'),
            '<strong>' . esc_html(number_format_i18n($preview_count)) . '</strong>'
        );
        echo ' ' . esc_html__('(Use "Preview recipient count" after changing the tag to refresh this number.)', 'gratis-crm');
        echo '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="gratiscrmbody">' . esc_html__('Message', 'gratis-crm') . '</label></th>';
        echo '<td>';
        wp_editor(
            $body,
            'gratiscrmbody',
            [
                'textarea_name' => 'gratis_crm_body',
                'textarea_rows' => 12,
                'media_buttons' => false,
            ]
        );
        echo '<p class="description">' . esc_html__('Basic HTML is allowed. Disallowed tags are stripped on save.', 'gratis-crm') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody></table>';

        echo '<p class="submit">';
        submit_button(
            __('Preview recipient count', 'gratis-crm'),
            'secondary',
            'gratis_crm_preview',
            false
        );
        echo ' ';
        submit_button(
            __('Queue &amp; send broadcast', 'gratis-crm'),
            'primary',
            'gratis_crm_send',
            false
        );
        echo '</p>';

        echo '</form>';

        self::render_history();

        echo '</div>';
    }

    /**
     * Render the recent-broadcasts status/log table.
     */
    private static function render_history(): void {
        $history = self::all();
        if (empty($history)) {
            return;
        }

        uasort(
            $history,
            static function (array $first, array $second): int {
                return ($second['created'] ?? 0) <=> ($first['created'] ?? 0);
            }
        );
        $history = array_slice($history, 0, 10, true);

        echo '<h2>' . esc_html__('Recent broadcasts', 'gratis-crm') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Date', 'gratis-crm') . '</th>';
        echo '<th>' . esc_html__('Subject', 'gratis-crm') . '</th>';
        echo '<th>' . esc_html__('Filter', 'gratis-crm') . '</th>';
        echo '<th>' . esc_html__('Status', 'gratis-crm') . '</th>';
        echo '<th>' . esc_html__('Sent', 'gratis-crm') . '</th>';
        echo '<th>' . esc_html__('Failed', 'gratis-crm') . '</th>';
        echo '<th>' . esc_html__('Skipped', 'gratis-crm') . '</th>';
        echo '<th>' . esc_html__('Pending', 'gratis-crm') . '</th>';
        echo '<th>' . esc_html__('Total', 'gratis-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($history as $rec) {
            $pending = is_array($rec['queue'] ?? null) ? count($rec['queue']) : 0;
            echo '<tr>';
            echo '<td>' . esc_html(wp_date('Y-m-d H:i', (int) ($rec['created'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ($rec['subject'] ?? '')) . '</td>';
            echo '<td>' . esc_html('' !== (string) ($rec['tag'] ?? '') ? (string) $rec['tag'] : __('All', 'gratis-crm')) . '</td>';
            echo '<td>' . esc_html(self::status_label((string) ($rec['status'] ?? ''))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($rec['sent'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($rec['failed'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($rec['skipped'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($pending)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($rec['total'] ?? 0))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Human-readable label for a broadcast status.
     *
     * @param string $status Internal status key.
     */
    private static function status_label(string $status): string {
        switch ($status) {
            case 'queued':
                return __('Queued', 'gratis-crm');
            case 'sending':
                return __('Sending', 'gratis-crm');
            case 'done':
                return __('Done', 'gratis-crm');
            default:
                return $status;
        }
    }

    /**
     * Resolve the eligible recipient IDs for a tag filter.
     *
     * Eligible = published contact, with a non-empty email, not unsubscribed.
     *
     * @param string $tag Optional tag slug filter.
     * @return array<int,int>
     */
    public static function recipients(string $tag = ''): array {
        $args = [
            'post_type'      => ContactPostType::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'relation' => 'AND',
                [
                    'key'     => '_email',
                    'value'   => '',
                    'compare' => '!=',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => Unsubscribe::META_FLAG,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => Unsubscribe::META_FLAG,
                        'value'   => '1',
                        'compare' => '!=',
                    ],
                ],
            ],
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

        return array_map('intval', get_posts($args));
    }

    /**
     * Count eligible recipients for a tag filter.
     *
     * @param string $tag Optional tag slug filter.
     */
    public static function count_recipients(string $tag = ''): int {
        return count(self::recipients($tag));
    }

    /**
     * Create and queue a broadcast, scheduling background delivery.
     *
     * @param string $subject Email subject (plain text).
     * @param string $body    Email body (kses-sanitised HTML).
     * @param string $tag     Optional tag slug filter.
     * @return array{id:string,total:int}
     */
    public static function enqueue(string $subject, string $body, string $tag = ''): array {
        // Defence in depth: callers already sanitise at the input boundary, but
        // enqueue is the single choke point for what gets stored and emailed,
        // so harden here too. wp_kses_post() is idempotent on clean input.
        $subject = sanitize_text_field($subject);
        $body    = wp_kses_post($body);
        $tag     = sanitize_key($tag);

        $ids = self::recipients($tag);
        $id  = 'bc_' . gmdate('YmdHis') . '_' . wp_rand(1000, 9999);
        $now = time();

        $broadcasts       = self::all();
        $broadcasts[$id] = [
            'id'      => $id,
            'subject' => $subject,
            'body'    => $body,
            'tag'     => $tag,
            'status'  => empty($ids) ? 'done' : 'queued',
            'created' => $now,
            'updated' => $now,
            'queue'   => array_values($ids),
            'total'   => count($ids),
            'sent'    => 0,
            'failed'  => 0,
            'skipped' => 0,
            'log'     => [],
        ];
        self::save($broadcasts);

        if (!empty($ids)) {
            self::schedule();
        }

        return ['id' => $id, 'total' => count($ids)];
    }

    /**
     * Cron callback: process a single capped batch, then reschedule if work remains.
     */
    public static function process_queue(): void {
        if (!self::lock()) {
            return;
        }

        try {
            $stats = self::process_batch(self::BATCH_SIZE);
        } finally {
            self::unlock();
        }

        if ($stats['remaining'] > 0) {
            self::schedule();
        }
    }

    /**
     * Drain the queue synchronously (used by WP-CLI).
     *
     * @param int $max_batches Safety cap on the number of batches to run.
     * @return array{sent:int,failed:int,skipped:int,remaining:int}
     */
    public static function drain(int $max_batches = 1000): array {
        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0];

        if (!self::lock()) {
            return $totals;
        }

        $max_batches = max(1, $max_batches);

        try {
            for ($i = 0; $i < $max_batches; $i++) {
                $stats               = self::process_batch(self::BATCH_SIZE);
                $totals['sent']     += $stats['sent'];
                $totals['failed']   += $stats['failed'];
                $totals['skipped']  += $stats['skipped'];
                $totals['remaining'] = $stats['remaining'];

                if ($stats['remaining'] <= 0) {
                    break;
                }
                // Nothing processed this pass: avoid an infinite loop.
                if (0 === $stats['sent'] + $stats['failed'] + $stats['skipped']) {
                    break;
                }
            }
        } finally {
            self::unlock();
        }

        return $totals;
    }

    /**
     * Send up to $limit messages from the oldest pending broadcast(s).
     *
     * @param int $limit Maximum messages to process this batch.
     * @return array{sent:int,failed:int,skipped:int,remaining:int}
     */
    public static function process_batch(int $limit): array {
        $limit      = max(1, $limit);
        $broadcasts = self::all();

        // Oldest first, so broadcasts are delivered in the order they were queued.
        uasort(
            $broadcasts,
            static function (array $first, array $second): int {
                return ($first['created'] ?? 0) <=> ($second['created'] ?? 0);
            }
        );

        $sent    = 0;
        $failed  = 0;
        $skipped = 0;
        $budget  = $limit;
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        foreach ($broadcasts as $id => $rec) {
            if ($budget <= 0) {
                break;
            }

            $status = (string) ($rec['status'] ?? '');
            if ('queued' !== $status && 'sending' !== $status) {
                continue;
            }
            if (empty($broadcasts[$id]['queue'])) {
                $broadcasts[$id]['status'] = 'done';
                continue;
            }

            $broadcasts[$id]['status'] = 'sending';

            while ($budget > 0 && !empty($broadcasts[$id]['queue'])) {
                $cid = (int) array_shift($broadcasts[$id]['queue']);
                --$budget;

                $email = (string) get_post_meta($cid, '_email', true);

                // Re-check at send time: the contact may have unsubscribed after queueing.
                if (Unsubscribe::is_unsubscribed($cid) || !is_email($email)) {
                    ++$skipped;
                    $broadcasts[$id]['skipped'] = (int) ($broadcasts[$id]['skipped'] ?? 0) + 1;
                    self::log($broadcasts[$id], $cid, $email, 'skipped');
                    continue;
                }

                $message = self::append_unsubscribe((string) $rec['body'], $cid, $email);
                $ok      = wp_mail($email, (string) $rec['subject'], $message, $headers);

                if ($ok) {
                    ++$sent;
                    $broadcasts[$id]['sent'] = (int) ($broadcasts[$id]['sent'] ?? 0) + 1;
                    self::log($broadcasts[$id], $cid, $email, 'sent');
                } else {
                    ++$failed;
                    $broadcasts[$id]['failed'] = (int) ($broadcasts[$id]['failed'] ?? 0) + 1;
                    self::log($broadcasts[$id], $cid, $email, 'failed');
                }
            }

            $broadcasts[$id]['updated'] = time();
            if (empty($broadcasts[$id]['queue'])) {
                $broadcasts[$id]['status'] = 'done';
            }
        }

        self::save($broadcasts);

        $remaining = 0;
        foreach ($broadcasts as $rec) {
            $status = (string) ($rec['status'] ?? '');
            if (('queued' === $status || 'sending' === $status) && !empty($rec['queue'])) {
                $remaining += count($rec['queue']);
            }
        }

        return [
            'sent'      => $sent,
            'failed'    => $failed,
            'skipped'   => $skipped,
            'remaining' => $remaining,
        ];
    }

    /**
     * Clear scheduled cron on plugin deactivation.
     */
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Append a signed unsubscribe footer to an HTML email body.
     *
     * @param string $body  The broadcast HTML body.
     * @param int    $cid   Contact post ID.
     * @param string $email Contact email address.
     */
    private static function append_unsubscribe(string $body, int $cid, string $email): string {
        $url = Unsubscribe::url($cid, $email);

        $footer  = '<hr style="margin-top:24px;border:none;border-top:1px solid #dddddd;" />';
        $footer .= '<p style="font-size:12px;color:#888888;">';
        $footer .= esc_html(
            sprintf(
                /* translators: %s: site name. */
                __('You are receiving this email as a contact of %s.', 'gratis-crm'),
                get_bloginfo('name')
            )
        );
        $footer .= ' <a href="' . esc_url($url) . '">' . esc_html__('Unsubscribe', 'gratis-crm') . '</a>.';
        $footer .= '</p>';

        return $body . $footer;
    }

    /**
     * Append a capped log row to a broadcast record (passed by reference).
     *
     * @param array<string,mixed> $rec    Broadcast record.
     * @param int                 $cid    Contact post ID.
     * @param string              $email  Contact email address.
     * @param string              $result One of sent|failed|skipped.
     */
    private static function log(array &$rec, int $cid, string $email, string $result): void {
        $rec['log']   = is_array($rec['log'] ?? null) ? $rec['log'] : [];
        $rec['log'][] = [
            'cid'    => $cid,
            'email'  => $email,
            'status' => $result,
            'time'   => time(),
        ];
        if (count($rec['log']) > self::LOG_CAP) {
            $rec['log'] = array_slice($rec['log'], -self::LOG_CAP);
        }
    }

    /**
     * Schedule a single processing tick if one is not already pending.
     */
    private static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + self::CRON_DELAY, self::CRON_HOOK);
        }
    }

    /**
     * Acquire the best-effort processing lock.
     *
     * ponytail: a transient lock is not atomic; under heavy concurrent cron a
     * small batch could in theory be processed twice. Acceptable at this scale;
     * upgrade to an atomic DB/option CAS if double-sends become a concern.
     */
    private static function lock(): bool {
        if (get_transient(self::LOCK)) {
            return false;
        }
        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Release the processing lock.
     */
    private static function unlock(): void {
        delete_transient(self::LOCK);
    }

    /**
     * Read all broadcast records.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function all(): array {
        $broadcasts = get_option(self::OPTION, []);
        return is_array($broadcasts) ? $broadcasts : [];
    }

    /**
     * Persist broadcast records (pruned, non-autoloaded).
     *
     * @param array<string,array<string,mixed>> $broadcasts Records to store.
     */
    private static function save(array $broadcasts): void {
        update_option(self::OPTION, self::prune($broadcasts), false);
    }

    /**
     * Cap stored history, keeping all active broadcasts plus the most recent
     * completed ones.
     *
     * @param array<string,array<string,mixed>> $broadcasts Records to prune.
     * @return array<string,array<string,mixed>>
     */
    private static function prune(array $broadcasts): array {
        if (count($broadcasts) <= self::HISTORY_CAP) {
            return $broadcasts;
        }

        uasort(
            $broadcasts,
            static function (array $first, array $second): int {
                return ($second['created'] ?? 0) <=> ($first['created'] ?? 0);
            }
        );

        $kept = [];
        $done = 0;
        foreach ($broadcasts as $id => $rec) {
            if ('done' !== (string) ($rec['status'] ?? '')) {
                $kept[$id] = $rec;
                continue;
            }
            if ($done < self::HISTORY_CAP) {
                $kept[$id] = $rec;
                ++$done;
            }
        }

        return $kept;
    }
}
