<?php
/**
 * Plugin Name:       Redirect Manager
 * Description:       Manage 301/302 redirects from WordPress admin — exact and regex rules, hit counts, CSV export. No .htaccess editing needed.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wrm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WRM_TABLE', 'wrm_redirects' );

/** Create the rules table on activation. */
function wrm_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . WRM_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        source varchar(500) NOT NULL,
        target varchar(500) NOT NULL,
        code smallint NOT NULL DEFAULT 301,
        is_regex tinyint(1) NOT NULL DEFAULT 0,
        active tinyint(1) NOT NULL DEFAULT 1,
        hits bigint(20) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY source (source(191))
    ) $charset;" );
}
register_activation_hook( __FILE__, 'wrm_activate' );

/** Normalise a request path: leading slash, no query string, no trailing slash (except root). */
function wrm_request_path() {
    $uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
    $path = strtok( $uri, '?' );
    $path = '/' . ltrim( $path, '/' );
    if ( strlen( $path ) > 1 ) {
        $path = rtrim( $path, '/' );
    }
    return $path;
}

/** Perform the redirect if a rule matches. Runs before templates load. */
function wrm_maybe_redirect() {
    if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . WRM_TABLE;
    $path  = wrm_request_path();
    $rules = $wpdb->get_results( "SELECT * FROM $table WHERE active = 1 ORDER BY is_regex ASC, id ASC" );
    if ( ! $rules ) {
        return;
    }
    foreach ( $rules as $rule ) {
        $target = null;
        if ( $rule->is_regex ) {
            // Regex rules are stored without delimiters; validate before use.
            if ( @preg_match( '#' . $rule->source . '#', '' ) === false ) {
                continue; // Skip broken patterns instead of fataling.
            }
            if ( preg_match( '#' . $rule->source . '#', ltrim( $path, '/' ), $m ) ) {
                $target = preg_replace( '#' . $rule->source . '#', $rule->target, ltrim( $path, '/' ) );
            }
        } elseif ( $rule->source === $path ) {
            $target = $rule->target;
        }
        if ( null === $target ) {
            continue;
        }
        $wpdb->query( $wpdb->prepare( "UPDATE $table SET hits = hits + 1 WHERE id = %d", $rule->id ) );
        $code = in_array( (int) $rule->code, array( 301, 302, 307 ), true ) ? (int) $rule->code : 301;
        // Relative targets stay on-site; absolute URLs are allowed (external redirects).
        wp_redirect( $target, $code );
        exit;
    }
}
add_action( 'template_redirect', 'wrm_maybe_redirect', 1 );

/** Admin page: add / delete / toggle rules, CSV export. */
function wrm_menu() {
    add_management_page( 'Redirect Manager', 'Redirects', 'manage_options', 'wrm', 'wrm_page' );
}
add_action( 'admin_menu', 'wrm_menu' );

function wrm_page() {
    global $wpdb;
    $table = $wpdb->prefix . WRM_TABLE;
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    if ( isset( $_POST['wrm_add'] ) && check_admin_referer( 'wrm_add_rule' ) ) {
        $source = '/' . ltrim( trim( wp_unslash( $_POST['wrm_source'] ) ), '/' );
        $target = trim( wp_unslash( $_POST['wrm_target'] ) );
        if ( '' !== $source && '' !== $target ) {
            $wpdb->insert( $table, array(
                'source'   => sanitize_text_field( $source ),
                'target'   => esc_url_raw( $target ),
                'code'     => in_array( (int) $_POST['wrm_code'], array( 301, 302, 307 ), true ) ? (int) $_POST['wrm_code'] : 301,
                'is_regex' => isset( $_POST['wrm_regex'] ) ? 1 : 0,
            ) );
            echo '<div class="notice notice-success"><p>Rule added.</p></div>';
        }
    }
    if ( isset( $_GET['wrm_delete'] ) && check_admin_referer( 'wrm_delete_' . (int) $_GET['wrm_delete'] ) ) {
        $wpdb->delete( $table, array( 'id' => (int) $_GET['wrm_delete'] ) );
    }
    if ( isset( $_GET['wrm_toggle'] ) && check_admin_referer( 'wrm_toggle_' . (int) $_GET['wrm_toggle'] ) ) {
        $id = (int) $_GET['wrm_toggle'];
        $wpdb->query( $wpdb->prepare( "UPDATE $table SET active = 1 - active WHERE id = %d", $id ) );
    }
    if ( isset( $_GET['wrm_export'] ) && check_admin_referer( 'wrm_export' ) ) {
        $rows = $wpdb->get_results( "SELECT source, target, code, is_regex, hits FROM $table ORDER BY id ASC", ARRAY_A );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=redirects.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'source', 'target', 'code', 'is_regex', 'hits' ) );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }

    $rules = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" );
    ?>
    <div class="wrap">
        <h1>Redirect Manager</h1>
        <p>Exact rules are checked first, then regex rules. Regex sources are matched without delimiters against the path (no leading slash), e.g. <code>old-category/(.*)</code> → <code>/new-category/$1</code>.</p>
        <form method="post">
            <?php wp_nonce_field( 'wrm_add_rule' ); ?>
            <table class="form-table">
                <tr><th><label for="wrm_source">Source path / pattern</label></th>
                    <td><input type="text" id="wrm_source" name="wrm_source" class="regular-text" placeholder="/old-page" required></td></tr>
                <tr><th><label for="wrm_target">Target URL</label></th>
                    <td><input type="text" id="wrm_target" name="wrm_target" class="regular-text" placeholder="/new-page" required></td></tr>
                <tr><th>Code</th>
                    <td><select name="wrm_code"><option value="301">301 Permanent</option><option value="302">302 Temporary</option><option value="307">307 Temporary</option></select>
                    <label><input type="checkbox" name="wrm_regex"> Regex rule</label></td></tr>
            </table>
            <?php submit_button( 'Add Rule', 'primary', 'wrm_add' ); ?>
        </form>
        <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=wrm&wrm_export=1' ), 'wrm_export' ) ); ?>">Export CSV</a></p>
        <table class="widefat striped">
            <thead><tr><th>Source</th><th>Target</th><th>Code</th><th>Regex</th><th>Hits</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ( (array) $rules as $r ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $r->source ); ?></code></td>
                    <td><code><?php echo esc_html( $r->target ); ?></code></td>
                    <td><?php echo esc_html( $r->code ); ?></td>
                    <td><?php echo $r->is_regex ? 'Yes' : 'No'; ?></td>
                    <td><?php echo esc_html( $r->hits ); ?></td>
                    <td><?php echo $r->active ? 'Active' : 'Paused'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=wrm&wrm_toggle=' . $r->id ), 'wrm_toggle_' . $r->id ) ); ?>"><?php echo $r->active ? 'Pause' : 'Resume'; ?></a> |
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=wrm&wrm_delete=' . $r->id ), 'wrm_delete_' . $r->id ) ); ?>" onclick="return confirm('Delete this rule?')">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rules ) ) : ?><tr><td colspan="7">No rules yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
