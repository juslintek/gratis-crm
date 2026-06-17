<?php
/**
 * Plugin Name: Gratis CRM
 * Plugin URI: https://github.com/juslintek/gratis-crm
 * Description: Lightweight contact management — store contacts (name, email, phone, company, notes), tag and search them, auto-capture leads from gratis-forms, and export to CSV. Replaces FluentCRM ($103/yr) + Mailchimp ($13/mo).
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * Author URI: https://github.com/juslintek
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-crm
 */

defined('ABSPATH') || exit;

define('GRATIS_CRM_VERSION', '1.0.0');
define('GRATIS_CRM_FILE', __FILE__);
define('GRATIS_CRM_DIR', plugin_dir_path(__FILE__));
define('GRATIS_CRM_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/autoload.php';

// Post type, taxonomy and meta schema.
add_action('init', [\Gratis\CRM\ContactPostType::class, 'register']);

// Admin: contact details meta box, list-table columns/filters, CSV export.
add_action('add_meta_boxes', [\Gratis\CRM\MetaBox::class, 'register']);
add_action('save_post_gratis_contact', [\Gratis\CRM\MetaBox::class, 'save'], 10, 2);
add_action('admin_init', [\Gratis\CRM\AdminColumns::class, 'register']);
add_action('admin_init', [\Gratis\CRM\Export::class, 'register']);

// Sync WP users + gratis-forms submissions into contacts.
add_action('user_register', [\Gratis\CRM\ContactSync::class, 'onUserRegister']);
add_action('gratis_form_submitted', [\Gratis\CRM\ContactSync::class, 'onFormSubmit'], 10, 3);

// Broadcast email: admin compose screen, batched wp-cron sender, unsubscribe handler.
add_action('admin_menu', [\Gratis\CRM\Broadcast::class, 'menu']);
add_action('gratis_crm_process_broadcasts', [\Gratis\CRM\Broadcast::class, 'process_queue']);
add_action('init', [\Gratis\CRM\Unsubscribe::class, 'maybe_handle']);
register_deactivation_hook(GRATIS_CRM_FILE, [\Gratis\CRM\Broadcast::class, 'deactivate']);

// WP-CLI.
add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-crm', \Gratis\CRM\CLI\CRMCommand::class);
    }
});
