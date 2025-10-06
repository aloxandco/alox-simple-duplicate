# Alox Simple Duplicate

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)

**A simple, safe WordPress plugin to duplicate posts, pages, and public custom post types.**

---

## 📚 Documentation

For full details, usage guides, and developer examples, visit the  
👉 [**Alox & Co Codex**](https://codex.alox.co)  

---

## ✨ Features

✅ One-Click Duplication
Add a “Duplicate” link under each post or page in your WordPress dashboard.

✅ Bulk Duplicate
Select multiple posts or pages and duplicate them all at once using the bulk actions dropdown.

✅ Preserves Key Content
Copies taxonomies, featured images, and custom meta fields safely.

✅ Respects Permissions
Only users with proper capabilities (like Editors or Admins) can duplicate posts.

✅ Lightweight & Secure
No extra tables or settings. No external API calls. Follows WordPress security and coding standards.

✅ Translation Ready
Includes a .pot file and full internationalization support (alox-simply-duplicate text domain).

---

## 🚀 Installation

Download the ZIP file from our Codex.
In your WordPress dashboard, go to Plugins → Add New → Upload Plugin.
Upload alox-simple-duplicate.zip.
Activate it.
You’re ready to go!
Alternatively, place the folder manually into /wp-content/plugins/ and activate via the dashboard.

🔧 Filters & Hooks

Alox Simply Duplicate is filterable and extensible. You can modify or extend behavior using built-in hooks:
```php
/**
 * Filter excluded post types.
 */
add_filter( 'alox_sd_excluded_post_types', function( $excluded ) {
    $excluded[] = 'my_custom_type';
    return $excluded;
});

/**
 * Filter duplicated post arguments.
 */
add_filter( 'alox_sd_new_post_args', function( $args, $original ) {
    $args['post_status'] = 'pending';
    return $args;
}, 10, 2 );
```
Other available hooks:

alox_sd_skip_meta_keys
alox_sd_skip_private_meta
alox_sd_after_duplicate
Perfect for developers who want to control which metadata or post types are duplicated.

📦 Requirements

WordPress 5.0+

PHP 7.4+

📜 License

Released under the GPL-2.0-or-later

💡 About

Built with ❤️ by [**Alox & Co**](https://alox.co)  
 to keep WordPress workflows simple.
