<?php
/**
 * Plugin Name: Gratis CRM
 * Description: Lightweight CRM + email marketing. Replaces FluentCRM ($103/yr) + Mailchimp ($13/mo).
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * License: GPL-2.0-or-later
 * Text Domain: gratis-crm
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/autoload.php';

add_action('init', [\Gratis\CRM\ContactPostType::class, 'register']);
add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-crm', \Gratis\CRM\CLI\CRMCommand::class);
    }
});
// Sync WP users → contacts
add_action('user_register', [\Gratis\CRM\ContactSync::class, 'onUserRegister']);
add_action('gratis_form_submitted', [\Gratis\CRM\ContactSync::class, 'onFormSubmit'], 10, 3);
