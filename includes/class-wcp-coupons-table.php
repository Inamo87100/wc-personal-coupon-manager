<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WCP_Coupons_Table
 *
 * Extends WP_List_Table to display wcp_user_activation CPT posts
 * in the dedicated admin menu page.
 */
class WCP_Coupons_Table extends WP_List_Table {

    /** @var WC_Personal_Coupon_Manager */
    private $plugin;

    public function __construct($plugin_instance) {
        parent::__construct([
            'singular' => 'attivazione',
            'plural'   => 'attivazioni',
            'ajax'     => false,
        ]);
        $this->plugin = $plugin_instance;
    }

    public function get_columns() {
        return [
            'post_title'  => 'Riferimento',
            'email'       => 'Email',
            'full_name'   => 'Nome completo',
            'course_name' => 'Corso',
            'amount'      => 'Credito scalato (&euro;)',
            'post_date'   => 'Data attivazione',
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

    public function prepare_items() {
        $per_page      = 20;
        $current_page  = $this->get_pagenum();
        $search        = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby_raw   = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'post_date';
        $order_raw     = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';

        $orderby = in_array($orderby_raw, ['post_title', 'post_date'], true) ? $orderby_raw : 'post_date';
        $order   = in_array($order_raw, ['ASC', 'DESC'], true) ? $order_raw : 'DESC';

        $all_posts = get_posts([
            'post_type'      => 'wcp_user_activation',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => $orderby,
            'order'          => $order,
        ]);

        if ($search !== '') {
            $all_posts = array_values(array_filter($all_posts, function ($post) use ($search) {
                if (stripos($post->post_title, $search) !== false) {
                    return true;
                }
                $email = (string) get_post_meta($post->ID, 'wcp_email', true);
                $full_name = trim((string) get_post_meta($post->ID, 'wcp_first_name', true) . ' ' . (string) get_post_meta($post->ID, 'wcp_last_name', true));
                return stripos($email, $search) !== false || stripos($full_name, $search) !== false;
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

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'email':
                return esc_html((string) get_post_meta($item->ID, 'wcp_email', true));

            case 'full_name':
                $full_name = trim((string) get_post_meta($item->ID, 'wcp_first_name', true) . ' ' . (string) get_post_meta($item->ID, 'wcp_last_name', true));
                return $full_name !== '' ? esc_html($full_name) : '&mdash;';

            case 'course_name':
                return esc_html((string) get_post_meta($item->ID, 'wcp_course_name', true));

            case 'amount':
                $amount = (float) get_post_meta($item->ID, 'wcp_credit_cost', true);
                return '&euro;' . number_format($amount, 2, ',', '.');

            case 'post_date':
                return esc_html(date_i18n('d/m/Y H:i', strtotime($item->post_date)));

            default:
                return '&mdash;';
        }
    }

    public function column_post_title($item) {
        return '<code>' . esc_html($item->post_title) . '</code>';
    }

    public function no_items() {
        echo 'Nessuna attivazione trovata.';
    }
}
