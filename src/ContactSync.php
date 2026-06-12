<?php declare(strict_types=1);

namespace Gratis\CRM;

defined('ABSPATH') || exit;

/**
 * Syncs WordPress users and gratis-forms submissions into CRM contacts.
 */
final class ContactSync {

    /**
     * Create a contact when a new WP user registers.
     *
     * @param int $userId New user ID.
     */
    public static function onUserRegister(int $userId): void {
        $user = get_userdata($userId);
        if (!$user) {
            return;
        }
        $id = ContactPostType::create(
            $user->user_email,
            $user->display_name,
            ['_source' => 'registration']
        );
        if ($id) {
            wp_set_object_terms($id, ['subscriber'], ContactPostType::TAXONOMY, true);
        }
    }

    /**
     * Create a contact from a gratis-forms submission.
     *
     * @param int                 $formId  Form post ID.
     * @param array<string,mixed> $data    Submitted field values keyed by label.
     * @param int                 $entryId Stored submission ID.
     */
    public static function onFormSubmit(int $formId, array $data, int $entryId): void {
        unset($entryId);

        $email = '';
        foreach ($data as $label => $value) {
            if (is_string($value) && str_contains(strtolower((string) $label), 'email') && is_email($value)) {
                $email = $value;
                break;
            }
        }
        if (!$email) {
            return;
        }

        $name    = self::find($data, ['name', 'full name', 'your name']);
        $phone   = self::find($data, ['phone', 'telephone', 'mobile']);
        $company = self::find($data, ['company', 'organisation', 'organization', 'business']);

        $meta = ['_source' => 'form', '_form_id' => $formId];
        if ('' !== $phone) {
            $meta['_phone'] = $phone;
        }
        if ('' !== $company) {
            $meta['_company'] = $company;
        }

        $id = ContactPostType::create($email, $name, $meta);
        if ($id) {
            wp_set_object_terms($id, ['lead'], ContactPostType::TAXONOMY, true);
        }
    }

    /**
     * Find the first submitted value whose label loosely matches a candidate.
     *
     * @param array<string,mixed> $data       Submitted values keyed by label.
     * @param array<int,string>   $candidates Candidate label fragments.
     */
    private static function find(array $data, array $candidates): string {
        foreach ($data as $label => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $needle = strtolower((string) $label);
            foreach ($candidates as $candidate) {
                if (str_contains($needle, $candidate)) {
                    return sanitize_text_field((string) $value);
                }
            }
        }
        return '';
    }
}
