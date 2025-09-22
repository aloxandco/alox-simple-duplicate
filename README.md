# Alox Simply Duplicate

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

- One-click **Duplicate** link in post/page actions  
- Works with any **public custom post type**  
- Copies:
  - Post content & excerpt
  - Taxonomies (categories, tags, custom taxonomies)
  - Featured image
  - Post meta (safe-copied, excludes `_edit_lock` etc.)
- Bulk **Duplicate** action available
- Excludes WooCommerce products by default (to avoid conflicts)
- Lightweight and extendable via filters

---

## 🚀 Installation

1. Download or clone this repository into your `/wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/yourusername/alox-simply-duplicate.git


Activate Alox Simply Duplicate in the WordPress admin under Plugins.

Hover over a post, page, or supported custom post type → click Duplicate.

🔧 Filters & Hooks

Developers can extend functionality:

Exclude/Include Post Types
```php
add_filter( 'alox_sd_excluded_post_types', function( $types ) {
    // Re-enable WooCommerce products
    return array_diff( $types, array( 'product' ) );
} );
```

Skip Meta Keys
```php
add_filter( 'alox_sd_skip_meta_keys', function( $keys ) {
    $keys[] = '_my_unique_meta';
    return $keys;
} );
```

After Duplicate Action
```php
add_action( 'alox_sd_after_duplicate', function( $new_id, $original ) {
    // Custom logic after a post is duplicated
} , 10, 2 );
```

📸 Screenshots

Duplicate link in row actions

Bulk duplicate option in list view

📦 Requirements

WordPress 5.0+

PHP 7.4+

📜 License

Released under the GPL-2.0-or-later
.
© Alox & Co

💡 About

Built with ❤️ by [**Alox & Co**](https://alox.co)  
 to keep WordPress workflows simple.
