<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WCP_Coupons_Table
 *
 * Extends WP_List_Table to display and manage wcp_coupon CPT posts
 * in the dedicated admin menu page.
 */
class WCP_Coupons_Table extends WP_List_Table {

    /** @var WC_Personal_Coupon_Manager */
    private $plugin;

    public function __construct($plugin_instance) {
        parent::__construct([
            'singular' => 'coupon',
            'plural'   => 'coupon',
            'ajax'     => false,
        ]);
        $this->plugin = $plugin_instance;
    }

    // -------------------------------------------------------------------------
    // Column definitions
    // -------------------------------------------------------------------------

    public function get_columns() {
        return [
            'post_title'   => 'Codice',
            'author'       => 'Creato da',
            'product_name' => 'Prodotto',
            'email'        => 'Email',
            'amount'       => 'Prezzo (&euro;)',
            'status'       => 'Stato',
            'post_date'    => 'Data creazione',
        ];
    }

    public function get_sortable_columns() {
        return [
            'post_title' => ['post_title', false],
            'post_date'  => ['post_date', true],
        ];
    }

    protected function get_bulk_actions() {
        return [];
    }

    // -------------------------------------------------------------------------
    // Extra tablenav: status filter
    // -------------------------------------------------------------------------

    protected function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }
        $status_filter = isset($_GET['wcp_status']) ? sanitize_text_field($_GET['wcp_status']) : '';
        ?>
        <div class="alignleft actions">
            <label for="wcp-status-filter" class="screen-reader-text">Filtra per stato</label>
            <select name="wcp_status" id="wcp-status-filter">
                <option value="" <?php selected($status_filter, ''); ?>>Tutti gli stati</option>
                <option value="active" <?php selected($status_filter, 'active'); ?>>Attivi</option>
                <option value="used" <?php selected($status_filter, 'used'); ?>>Usati</option>
            </select>
            <?php submit_button('Filtra', 'secondary', 'filter_action', false); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Data preparation
    // -------------------------------------------------------------------------

    public function prepare_items() {
        $per_page      = 20;
        $current_page  = $this->get_pagenum();
        $search        = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['wcp_status']) ? sanitize_text_field($_GET['wcp_status']) : '';
        $orderby_raw   = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'post_date';
        $order_raw     = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';

        $orderby = in_array($orderby_raw, ['post_title', 'post_date'], true) ? $orderby_raw : 'post_date';
        $order   = in_array($order_raw, ['ASC', 'DESC'], true) ? $order_raw : 'DESC';

        $all_posts = get_posts([
            'post_type'      => 'wcp_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => $orderby,
            'order'          => $order,
        ]);

        // Filter by search term: coupon code (post_title) or email meta
        if ($search !== '') {
            $all_posts = array_values(array_filter($all_posts, function ($post) use ($search) {
                if (stripos($post->post_title, $search) !== false) {
                    return true;
                }
                $email = (string) get_post_meta($post->ID, 'wcp_email', true);
                return stripos($email, $search) !== false;
            }));
        }

        // Filter by status (uses cached wcp_used meta — value may be out of sync
        // until the admin visits individual coupons, but avoids N remote API calls)
        if ($status_filter === 'active' || $status_filter === 'used') {
            $all_posts = array_values(array_filter($all_posts, function ($post) use ($status_filter) {
                $is_used = (bool) get_post_meta($post->ID, 'wcp_used', true);
                return $status_filter === 'used' ? $is_used : !$is_used;
            }));
        }

        $total = count($all_posts);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / max(1, $per_page)),
        ]);

        $offset      = ($current_page - 1) * $per_page;
        $this->items = array_slice($all_posts, $offset, $per_page);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    // -------------------------------------------------------------------------
    // Column renderers
    // -------------------------------------------------------------------------

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'author':
                $user = get_userdata((int) $item->post_author);
                if ($user) {
                    return esc_html($user->display_name) . ' <small>(' . esc_html($user->user_login) . ')</small>';
                }
                return '&mdash;';

            case 'product_name':
                return esc_html((string) get_post_meta($item->ID, 'wcp_product_name', true));

            case 'email':
                return esc_html((string) get_post_meta($item->ID, 'wcp_email', true));

            case 'amount':
                $amount = (float) get_post_meta($item->ID, 'wcp_amount', true);
                return '&euro;' . number_format($amount, 2, ',', '.');

            case 'status':
                $is_used = (bool) get_post_meta($item->ID, 'wcp_used', true);
                return $is_used
                    ? '<span style="color:#d63638;font-weight:600;">&#10006; Usato</span>'
                    : '<span style="color:#00a32a;font-weight:600;">&#10004; Attivo</span>';

            case 'post_date':
                return esc_html(date_i18n('d/m/Y H:i', strtotime($item->post_date)));

            default:
                return '&mdash;';
        }
    }

    /**
     * Primary column: coupon code with row actions.
     * Delete action is shown only for non-used coupons.
     */
    public function column_post_title($item) {
        $is_used    = (bool) get_post_meta($item->ID, 'wcp_used', true);
        $delete_url = wp_nonce_url(
            admin_url('admin-post.php?action=wcp_admin_delete_coupon&post_id=' . $item->ID),
            'wcp_admin_delete_' . $item->ID
        );

        $actions = [];
        if (!$is_used) {
            $actions['delete'] = sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(\'Eliminare il coupon %s? Questa azione è irreversibile.\');">Elimina</a>',
                esc_url($delete_url),
                esc_js($item->post_title)
            );
        } else {
            $actions['status'] = '<span style="color:#aaa;" title="Il coupon è già stato utilizzato e non può essere eliminato.">Elimina (non disponibile)</span>';
        }

        return '<code>' . esc_html($item->post_title) . '</code>' . $this->row_actions($actions);
    }

    public function no_items() {
        echo 'Nessun coupon trovato.';
    }
}
