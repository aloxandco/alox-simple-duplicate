<?php
/**
 * Plugin Name: Alox Simply Duplicate
 * Plugin URI:  https://codex.alox.co/
 * Description: Duplicate posts, pages, and public custom post types into a new draft with taxonomies, meta, and featured image.
 * Version:     1.2.0
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

define( 'ALOX_SD_VER', '1.2.0' );

/**
 * Load textdomain.
 */
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'alox-simply-duplicate', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Post types to exclude.
 *
 * Defaults: attachment, revision, nav_menu_item, custom CSS/template post types, and WooCommerce product.
 * Developers can filter via 'alox_sd_excluded_post_types'.
 *
 * @return array<string>
 */
function alox_sd_get_excluded_post_types() : array {
    $excluded = array(
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'wp_block',
        'wp_navigation',
        'wp_template',
        'wp_template_part',
        'product',
    );

    /**
     * Filter excluded post types.
     *
     * @param array $excluded
     */
    return apply_filters( 'alox_sd_excluded_post_types', $excluded );
}

/**
 * Safe list of meta keys to skip copying by default.
 *
 * @return array<string>
 */
function alox_sd_default_skipped_meta_keys() : array {
    return array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_thumbnail_id', // handled separately below.
    );
}

/**
 * Core duplication routine. Single source of truth used by row action and bulk action.
 *
 * @param int $original_id Original post ID.
 * @return int|WP_Error New post ID or error.
 */
function alox_sd_duplicate_post( int $original_id ) {
    $original = get_post( $original_id );

    if ( ! $original instanceof WP_Post ) {
        return new WP_Error( 'alox_sd_not_found', __( 'Original item not found.', 'alox-simply-duplicate' ) );
    }

    $excluded = alox_sd_get_excluded_post_types();
    if ( in_array( $original->post_type, $excluded, true ) ) {
        return new WP_Error( 'alox_sd_excluded', __( 'Duplication is disabled for this post type.', 'alox-simply-duplicate' ) );
    }

    $pto = get_post_type_object( $original->post_type );
    if ( ! $pto ) {
        return new WP_Error( 'alox_sd_invalid_pto', __( 'Invalid post type.', 'alox-simply-duplicate' ) );
    }

    // Permissions: must be able to edit original and create new items for that post type.
    if ( ! current_user_can( 'edit_post', $original->ID ) || ! current_user_can( $pto->cap->create_posts ) ) {
        return new WP_Error( 'alox_sd_forbidden', __( 'You do not have permission to duplicate this item.', 'alox-simply-duplicate' ) );
    }

    // Prepare new post arguments. Let core set dates/slugs.
    $new_post_args = array(
        'post_author'    => get_current_user_id(),
        'post_content'   => $original->post_content,
        'post_excerpt'   => $original->post_excerpt,
        'post_status'    => 'draft',
        /* translators: %s: original title */
        'post_title'     => sprintf( __( '%s (Copy)', 'alox-simply-duplicate' ), $original->post_title ),
        'post_type'      => $original->post_type,
        'comment_status' => $original->comment_status,
        'ping_status'    => $original->ping_status,
        'menu_order'     => (int) $original->menu_order,
        'post_parent'    => (int) $original->post_parent,
        'post_password'  => '', // Do not carry over passwords by default.
        'post_name'      => '', // Fresh slug.
    );

    /**
     * Allow devs to adjust the new post args before insert.
     *
     * @param array   $new_post_args
     * @param WP_Post $original
     */
    $new_post_args = apply_filters( 'alox_sd_new_post_args', $new_post_args, $original );

    $new_post_id = wp_insert_post( wp_slash( $new_post_args ), true );
    if ( is_wp_error( $new_post_id ) ) {
        return $new_post_id;
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
    $all_meta  = get_post_meta( $original->ID );
    $skip_keys = alox_sd_default_skipped_meta_keys();

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

            /**
             * Optionally skip private keys starting with underscore (default: false).
             *
             * @param bool   $skip_private
             * @param string $meta_key
             * @param WP_Post $original
             */
            $skip_private = apply_filters( 'alox_sd_skip_private_meta', false, $meta_key, $original );
            if ( $skip_private && 0 === strpos( $meta_key, '_' ) ) {
                continue;
            }

            foreach ( (array) $values as $meta_value ) {
                // get_post_meta() returns serialized strings. maybe_unserialize() restores arrays/objects.
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

    return (int) $new_post_id;
}

/**
 * Add "Duplicate" row action link for supported post types.
 *
 * @param array   $actions
 * @param WP_Post $post
 * @return array
 */
function alox_sd_row_action_link( $actions, $post ) {
    if ( ! $post instanceof WP_Post ) {
        return $actions;
    }

    if ( in_array( $post->post_type, alox_sd_get_excluded_post_types(), true ) ) {
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
 * Handle single-item duplicate request (row action).
 */
function alox_sd_handle_duplicate() {
    if ( ! is_admin() ) {
        return;
    }

    if ( empty( $_GET['action'] ) || 'alox_sd_duplicate' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    $post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $posttype = isset( $_GET['posttype'] ) ? sanitize_key( (string) $_GET['posttype'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if ( ! $post_id || ! $posttype ) {
        wp_die( esc_html__( 'Invalid request.', 'alox-simply-duplicate' ) );
    }

    if ( ! isset( $_GET['alox_sd_nonce'] ) || ! wp_verify_nonce( $_GET['alox_sd_nonce'], 'alox_sd_duplicate_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        wp_die( esc_html__( 'Security check failed.', 'alox-simply-duplicate' ) );
    }

    $result = alox_sd_duplicate_post( $post_id );

    if ( is_wp_error( $result ) ) {
        wp_die( esc_html( $result->get_error_message() ) );
    }

    $edit_link = get_edit_post_link( (int) $result, 'raw' );
    if ( $edit_link ) {
        wp_safe_redirect( $edit_link );
    } else {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'post'   => (int) $result,
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
 * Bulk Action: register "Duplicate" for supported post types.
 *
 * @param array $bulk_actions
 * @return array
 */
function alox_sd_register_bulk_action( $bulk_actions ) {
    $bulk_actions['alox_sd_bulk_duplicate'] = __( 'Duplicate', 'alox-simply-duplicate' );
    return $bulk_actions;
}

/**
 * Bulk Action handler.
 *
 * @param string $redirect_to
 * @param string $doaction
 * @param array  $post_ids
 * @return string
 */
function alox_sd_handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
    if ( 'alox_sd_bulk_duplicate' !== $doaction ) {
        return $redirect_to;
    }

    // Core verifies the "bulk-posts" nonce before this filter runs.
    $count = 0;

    foreach ( (array) $post_ids as $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            continue;
        }

        $result = alox_sd_duplicate_post( $post_id );
        if ( ! is_wp_error( $result ) ) {
            $count++;
        }
    }

    return add_query_arg(
        array( 'alox_sd_duplicated' => $count ),
        $redirect_to
    );
}

/**
 * Bulk success notice.
 */
function alox_sd_bulk_notices() {
    if ( isset( $_REQUEST['alox_sd_duplicated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $count = (int) $_REQUEST['alox_sd_duplicated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $count > 0 ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( _n( 'Duplicated %d item.', 'Duplicated %d items.', $count, 'alox-simply-duplicate' ), $count ) )
            );
        }
    }
}

/**
 * Hook bulk actions only for supported edit screens.
 */
add_action( 'load-edit.php', function () {
    $screen = get_current_screen();
    if ( ! $screen || 'edit' !== $screen->base ) {
        return;
    }

    $post_type = isset( $_GET['post_type'] ) ? sanitize_key( (string) $_GET['post_type'] ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if ( in_array( $post_type, alox_sd_get_excluded_post_types(), true ) ) {
        return;
    }

    add_filter( "bulk_actions-edit-{$post_type}", 'alox_sd_register_bulk_action' );
    add_filter( "handle_bulk_actions-edit-{$post_type}", 'alox_sd_handle_bulk_action', 10, 3 );
    add_action( 'admin_notices', 'alox_sd_bulk_notices' );
} );
