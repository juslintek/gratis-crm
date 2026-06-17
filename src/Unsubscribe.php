<?php declare(strict_types=1);

namespace Gratis\CRM;

defined('ABSPATH') || exit;

/**
 * Per-contact unsubscribe.
 *
 * Builds signed (HMAC) unsubscribe links and handles the public unsubscribe
 * request, marking the contact so broadcasts exclude it from future sends.
 */
final class Unsubscribe {

    /** Option storing the per-site HMAC secret used to sign tokens. */
    private const SECRET_OPTION = 'gratis_crm_unsub_secret';

    /** Contact meta flag, set to '1' once the contact has unsubscribed. */
    public const META_FLAG = '_unsubscribed';

    /** Contact meta storing the unsubscribe timestamp (ISO 8601). */
    public const META_TIME = '_unsubscribed_at';

    /**
     * Handle an incoming unsubscribe request, if one is present.
     *
     * This is a token-authenticated public endpoint: the signed HMAC token in
     * the URL is the authentication, so a WordPress nonce is neither available
     * nor applicable here.
     */
    public static function maybe_handle(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-signed public link, not a form submission.
        if (!isset($_GET['gratis_crm_unsub'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-signed public link.
        $cid = isset($_GET['gcrm_cid']) ? absint(wp_unslash($_GET['gcrm_cid'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-signed public link.
        $token = isset($_GET['gcrm_token']) ? sanitize_text_field(wp_unslash($_GET['gcrm_token'])) : '';

        if ($cid <= 0 || '' === $token) {
            self::deny();
        }

        $post = get_post($cid);
        if (!$post instanceof \WP_Post || ContactPostType::POST_TYPE !== $post->post_type) {
            self::deny();
        }

        $email = (string) get_post_meta($cid, '_email', true);
        if (!hash_equals(self::token($cid, $email), $token)) {
            self::deny();
        }

        update_post_meta($cid, self::META_FLAG, '1');
        update_post_meta($cid, self::META_TIME, gmdate('c'));

        wp_die(
            esc_html__('You have been unsubscribed and will no longer receive these emails.', 'gratis-crm'),
            esc_html__('Unsubscribed', 'gratis-crm'),
            ['response' => 200]
        );
    }

    /**
     * Build a signed unsubscribe URL for a contact.
     *
     * @param int    $cid   Contact post ID.
     * @param string $email Contact email (bound into the signature).
     */
    public static function url(int $cid, string $email): string {
        return add_query_arg(
            [
                'gratis_crm_unsub' => 1,
                'gcrm_cid'         => $cid,
                'gcrm_token'       => self::token($cid, $email),
            ],
            home_url('/')
        );
    }

    /**
     * Compute the HMAC token that authenticates an unsubscribe request.
     *
     * @param int    $cid   Contact post ID.
     * @param string $email Contact email address.
     */
    public static function token(int $cid, string $email): string {
        return hash_hmac('sha256', $cid . '|' . strtolower($email), self::secret());
    }

    /**
     * Whether a contact has opted out of broadcasts.
     *
     * @param int $cid Contact post ID.
     */
    public static function is_unsubscribed(int $cid): bool {
        return '1' === (string) get_post_meta($cid, self::META_FLAG, true);
    }

    /**
     * Return (creating once if needed) the per-site signing secret.
     */
    private static function secret(): string {
        $secret = get_option(self::SECRET_OPTION);
        if (!is_string($secret) || '' === $secret) {
            $secret = wp_generate_password(64, false, false);
            update_option(self::SECRET_OPTION, $secret, false);
        }
        return $secret;
    }

    /**
     * Reject an invalid or tampered unsubscribe request and stop.
     */
    private static function deny(): void {
        wp_die(
            esc_html__('This unsubscribe link is invalid or has expired.', 'gratis-crm'),
            esc_html__('Invalid link', 'gratis-crm'),
            ['response' => 400]
        );
    }
}
