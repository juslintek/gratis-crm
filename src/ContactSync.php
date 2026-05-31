<?php declare(strict_types=1);
namespace Gratis\CRM;

final class ContactSync
{
    public static function onUserRegister(int $userId): void
    {
        $user = get_userdata($userId);
        if (!$user) return;
        $id = ContactPostType::create($user->user_email, $user->display_name, ['_source' => 'registration']);
        wp_set_object_terms($id, ['subscriber'], 'contact_tag');
    }

    public static function onFormSubmit(int $formId, array $data, int $entryId): void
    {
        $email = '';
        foreach ($data as $label => $value) {
            if (str_contains(strtolower($label), 'email') && is_email($value)) { $email = $value; break; }
        }
        if (!$email) return;
        $name = $data['Name'] ?? $data['name'] ?? '';
        $id = ContactPostType::create($email, $name, ['_source' => 'form', '_form_id' => $formId]);
        wp_set_object_terms($id, ['lead'], 'contact_tag');
    }
}
