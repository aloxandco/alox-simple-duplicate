<?php
/**
 * Plugin Name: Alox Simply Duplicate
 * Plugin URI:  https://codex.alox.co/
 * Description: Duplicate posts, pages, and public custom post types into a new draft with taxonomies, meta, and featured image.
 * Version:     1.1.0
 * Author:      Alox & Co
 * Author URI:  https://alox.co
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: alox-simply-duplicate
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ALOX_SD_VER', '1.1.0' );

/**
 * Load textdomain.
 */
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'alox-simply-duplicate', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Post types to exclude.
 *
 * Defaults: attachment, revision, nav_menu_item, and WooCommerce product.
 * Developers can filter via 'alox_sd_excluded_post_types'.
 */
function alox_sd_get_excluded_post_types() : array {
    $excluded = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'product' );
    /**
     * Filter excluded post types.
     *
     * @param array $excluded
     */
    return apply_filters( 'alox_sd_excluded_post_types', $excluded );
}

/**
 * Add Duplicate action link to row actions for supported post types.
 */
function alox_sd_row_action_link( $actions, $post ) {
    if ( ! $post instanceof WP_Post ) {
        return $actions;
    }

    $excluded = alox_sd_get_excluded_post_types();
    if ( in_array( $post->post_type, $excluded, true ) ) {
        return $actions;
    }

    if ( current_user_can( 'edit_post', $post->ID ) && current_user_can( get_post_type_object( $post->post_type )->cap->create_posts ) ) {
        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'   => 'alox_sd_duplicate',
                    'post'     => $post->ID,
                    'posttype' => $post->post_type,
                ),
                admin_url( 'admin.php' )
            ),
            'alox_sd_duplicate_' . $post->ID,
            'alox_sd_nonce'
        );

        $actions['alox_sd_duplicate'] = sprintf(
            '<a href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
            esc_url( $url ),
            esc_attr__( 'Duplicate this item', 'alox-simply-duplicate' ),
            esc_html__( 'Duplicate', 'alox-simply-duplicate' )
        );
    }

    return $actions;
}
add_filter( 'post_row_actions', 'alox_sd_row_action_link', 10, 2 );
add_filter( 'page_row_actions', 'alox_sd_row_action_link', 10, 2 );

/**
 * Safe list of meta keys to skip copying by default.
 */
function alox_sd_default_skipped_meta_keys() : array {
    return array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_thumbnail_id', // handled separately
        // Skip common builder or lock flags if needed (devs can filter)
    );
}

/**
 * Duplicate handler.
 */
function alox_sd_handle_duplicate() {
    if ( ! is_admin() ) {
        return;
    }

    if ( ! isset( $_GET['action'] ) || 'alox_sd_duplicate' !== $_GET['action'] ) {
        return;
    }

    $post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
    $posttype = isset( $_GET['posttype'] ) ? sanitize_key( $_GET['posttype'] ) : '';

    if ( ! $post_id || ! $posttype ) {
        wp_die( esc_html__( 'Invalid request.', 'alox-simply-duplicate' ) );
    }

    if ( ! isset( $_GET['alox_sd_nonce'] ) || ! wp_verify_nonce( $_GET['alox_sd_nonce'], 'alox_sd_duplicate_' . $post_id ) ) {
        wp_die( esc_html__( 'Security check failed.', 'alox-simply-duplicate' ) );
    }

    $excluded = alox_sd_get_excluded_post_types();
    if ( in_array( $posttype, $excluded, true ) ) {
        wp_die( esc_html__( 'Duplication is disabled for this post type.', 'alox-simply-duplicate' ) );
    }

    $original = get_post( $post_id );
    if ( ! $original ) {
        wp_die( esc_html__( 'Original item not found.', 'alox-simply-duplicate' ) );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'You do not have permission to duplicate this item.', 'alox-simply-duplicate' ) );
    }

    $pto = get_post_type_object( $original->post_type );
    if ( ! $pto || ! current_user_can( $pto->cap->create_posts ) ) {
        wp_die( esc_html__( 'You cannot create items for this post type.', 'alox-simply-duplicate' ) );
    }

    // Prepare new post array.
    $new_post_args = array(
        'post_author'           => get_current_user_id(),
        'post_content'          => $original->post_content,
        'post_excerpt'          => $original->post_excerpt,
        'post_status'           => 'draft',
        'post_title'            => sprintf( /* translators: %s: original title */ __( '%s (Copy)', 'alox-simply-duplicate' ), $original->post_title ),
        'post_type'             => $original->post_type,
        'comment_status'        => $original->comment_status,
        'ping_status'           => $original->ping_status,
        'menu_order'            => $original->menu_order,
        'post_parent'           => $original->post_parent,
        'post_password'         => '', // do not carry over passwords by default
        'post_name'             => '', // let WP generate a fresh slug
        'post_date'             => current_time( 'mysql' ),
        'post_date_gmt'         => current_time( 'mysql', 1 ),
        'post_modified'         => current_time( 'mysql' ),
        'post_modified_gmt'     => current_time( 'mysql', 1 ),
    );

    /**
     * Allow devs to adjust the new post args before insert.
     *
     * @param array $new_post_args
     * @param WP_Post $original
     */
    $new_post_args = apply_filters( 'alox_sd_new_post_args', $new_post_args, $original );

    $new_post_id = wp_insert_post( wp_slash( $new_post_args ), true );

    if ( is_wp_error( $new_post_id ) ) {
        wp_die( esc_html( $new_post_id->get_error_message() ) );
    }

    // Copy taxonomies.
    $taxonomies = get_object_taxonomies( $original->post_type );
    foreach ( $taxonomies as $taxonomy ) {
        $terms = wp_get_object_terms( $original->ID, $taxonomy, array( 'fields' => 'ids' ) );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            wp_set_object_terms( $new_post_id, $terms, $taxonomy, false );
        }
    }

    // Copy featured image.
    $thumb_id = get_post_thumbnail_id( $original->ID );
    if ( $thumb_id ) {
        set_post_thumbnail( $new_post_id, $thumb_id );
    }

    // Copy meta, skipping unsafe keys.
    $all_meta   = get_post_meta( $original->ID );
    $skip_keys  = alox_sd_default_skipped_meta_keys();

    /**
     * Filter list of meta keys to skip.
     *
     * @param array   $skip_keys
     * @param WP_Post $original
     */
    $skip_keys = apply_filters( 'alox_sd_skip_meta_keys', $skip_keys, $original );

    if ( is_array( $all_meta ) ) {
        foreach ( $all_meta as $meta_key => $values ) {
            if ( in_array( $meta_key, $skip_keys, true ) ) {
                continue;
            }

            // Optionally skip private keys starting with underscore. Filterable.
            $skip_private = apply_filters( 'alox_sd_skip_private_meta', false, $meta_key, $original );
            if ( $skip_private && 0 === strpos( $meta_key, '_' ) ) {
                continue;
            }

            foreach ( (array) $values as $meta_value ) {
                add_post_meta( $new_post_id, $meta_key, maybe_unserialize( $meta_value ) );
            }
        }
    }

    /**
     * Action after duplication completes.
     *
     * @param int     $new_post_id
     * @param WP_Post $original
     */
    do_action( 'alox_sd_after_duplicate', $new_post_id, $original );

    // Redirect to the editor for the new draft.
    $edit_link = get_edit_post_link( $new_post_id, 'raw' );
    if ( $edit_link ) {
        wp_safe_redirect( $edit_link );
    } else {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'post'   => $new_post_id,
                    'action' => 'edit',
                ),
                admin_url( 'post.php' )
            )
        );
    }
    exit;
}
add_action( 'admin_init', 'alox_sd_handle_duplicate' );

/**
 * Optional: add Bulk Action "Duplicate" for supported post types.
 */
function alox_sd_register_bulk_action( $bulk_actions ) {
    $bulk_actions['alox_sd_bulk_duplicate'] = __( 'Duplicate', 'alox-simply-duplicate' );
    return $bulk_actions;
}
function alox_sd_handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
    if ( 'alox_sd_bulk_duplicate' !== $doaction ) {
        return $redirect_to;
    }
    $count = 0;
    foreach ( (array) $post_ids as $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            continue;
        }
        if ( in_array( $post->post_type, alox_sd_get_excluded_post_types(), true ) ) {
            continue;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( get_post_type_object( $post->post_type )->cap->create_posts ) ) {
            continue;
        }

        // Simulate a light duplicate by reusing the core routine via action hook.
        // Build new post args quickly to avoid repeating logic.
        $new_id = wp_insert_post( array(
            'post_author'    => get_current_user_id(),
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_status'    => 'draft',
            'post_title'     => sprintf( __( '%s (Copy)', 'alox-simply-duplicate' ), $post->post_title ),
            'post_type'      => $post->post_type,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'menu_order'     => $post->menu_order,
            'post_parent'    => $post->post_parent,
        ), true );

        if ( is_wp_error( $new_id ) ) {
            continue;
        }

        // Taxonomies
        $taxonomies = get_object_taxonomies( $post->post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $new_id, $terms, $taxonomy, false );
            }
        }

        // Meta
        $all_meta  = get_post_meta( $post_id );
        $skip_keys = apply_filters( 'alox_sd_skip_meta_keys', alox_sd_default_skipped_meta_keys(), $post );
        foreach ( $all_meta as $meta_key => $values ) {
            if ( in_array( $meta_key, $skip_keys, true ) ) {
                continue;
            }
            foreach ( (array) $values as $meta_value ) {
                add_post_meta( $new_id, $meta_key, maybe_unserialize( $meta_value ) );
            }
        }

        // Thumbnail
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            set_post_thumbnail( $new_id, $thumb_id );
        }

        $count++;
    }

    return add_query_arg(
        array( 'alox_sd_duplicated' => $count ),
        $redirect_to
    );
}

function alox_sd_bulk_notices() {
    if ( isset( $_REQUEST['alox_sd_duplicated'] ) ) {
        $count = (int) $_REQUEST['alox_sd_duplicated'];
        if ( $count > 0 ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( _n( 'Duplicated %d item.', 'Duplicated %d items.', $count, 'alox-simply-duplicate' ), $count ) )
            );
        }
    }
}

// Hook bulk actions only for supported screens.
add_action( 'load-edit.php', function () {
    $screen = get_current_screen();
    if ( ! $screen || 'edit' !== $screen->base ) {
        return;
    }
    $post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';
    if ( in_array( $post_type, alox_sd_get_excluded_post_types(), true ) ) {
        return;
    }
    add_filter( "bulk_actions-edit-{$post_type}", 'alox_sd_register_bulk_action' );
    add_filter( 'handle_bulk_actions-edit-' . $post_type, 'alox_sd_handle_bulk_action', 10, 3 );
    add_action( 'admin_notices', 'alox_sd_bulk_notices' );
} );
