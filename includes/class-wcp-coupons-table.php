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

    /**
     * Build admin URL preserving common query args, allowing explicit reset.
     * If an override value is '' or null the parameter is removed.
     */
    private function build_filter_url(array $overrides = []) {
        $args = [
            'page'              => isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'wcp-manager',
            's'                 => isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '',
            'orderby'           => isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '',
            'order'             => isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '',
            'filter_order_id'   => isset($_GET['filter_order_id']) ? intval($_GET['filter_order_id']) : '',
            'filter_creator_id' => isset($_GET['filter_creator_id']) ? intval($_GET['filter_creator_id']) : '',
            'paged'             => isset($_GET['paged']) ? intval($_GET['paged']) : '',
        ];

        // Apply overrides: '' or null means "remove param"
        foreach ($overrides as $k => $v) {
            if ($v === '' || $v === null) {
                unset($args[$k]);
            } else {
                $args[$k] = $v;
            }
        }

        // Remove empty values
        $args = array_filter($args, function ($v) {
            return $v !== '' && $v !== 0 && $v !== null;
        });

        return add_query_arg($args, admin_url('admin.php'));
    }

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
            'order_id'    => 'N. Ordine',
            'created_by'  => 'Creato da',
            'post_date'   => 'Data attivazione',
            'actions'     => 'Azioni',
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

    /**
     * Returns an associative array of user_id => "login (email)" for all users
     * who have at least one activation record (based on post_author).
     */
    private function get_creator_options() {
        global $wpdb;
        $author_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_author FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = %s",
                'wcp_user_activation',
                'publish'
            )
        );
        $options = [];
        foreach ($author_ids as $uid) {
            $user = get_userdata((int) $uid);
            if ($user) {
                $options[(int) $uid] = sprintf('%s (%s)', $user->user_login, $user->user_email);
            }
        }
        return $options;
    }

    public function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }

        $filter_order_id   = isset($_GET['filter_order_id'])   ? intval($_GET['filter_order_id'])   : 0;
        $filter_creator_id = isset($_GET['filter_creator_id']) ? intval($_GET['filter_creator_id']) : 0;
        $search            = isset($_GET['s'])                  ? sanitize_text_field($_GET['s'])    : '';
        $has_filters       = $filter_order_id > 0 || $filter_creator_id > 0 || $search !== '';
        $creators          = $this->get_creator_options();
        ?>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="filter_order_id"><?php esc_html_e('Filtra per N. Ordine', 'wcp'); ?></label>
            <input type="number" id="filter_order_id" name="filter_order_id" min="1"
                   value="<?php echo $filter_order_id > 0 ? esc_attr((string) $filter_order_id) : ''; ?>"
                   placeholder="N. Ordine" style="width:110px;">

            <?php if (!empty($creators)) : ?>
                <label class="screen-reader-text" for="filter_creator_id"><?php esc_html_e('Filtra per Creatore', 'wcp'); ?></label>
                <select id="filter_creator_id" name="filter_creator_id">
                    <option value=""><?php esc_html_e('— Tutti i creatori —', 'wcp'); ?></option>
                    <?php foreach ($creators as $uid => $label) : ?>
                        <option value="<?php echo esc_attr((string) $uid); ?>"
                            <?php selected($filter_creator_id, $uid); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php submit_button('Filtra', 'button', 'wcp_filter', false); ?>

            <?php if ($has_filters) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wcp-manager')); ?>" class="button">
                    <?php esc_html_e('Reset filtri', 'wcp'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    public function prepare_items() {
        $per_page          = 20;
        $current_page      = $this->get_pagenum();
        $search            = isset($_GET['s'])                  ? sanitize_text_field($_GET['s'])    : '';
        $orderby_raw       = isset($_GET['orderby'])            ? sanitize_text_field($_GET['orderby']) : 'post_date';
        $order_raw         = isset($_GET['order'])              ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
        $filter_order_id   = isset($_GET['filter_order_id'])    ? intval($_GET['filter_order_id'])   : 0;
        $filter_creator_id = isset($_GET['filter_creator_id'])  ? intval($_GET['filter_creator_id']) : 0;

        $orderby = in_array($orderby_raw, ['post_title', 'post_date'], true) ? $orderby_raw : 'post_date';
        $order   = in_array($order_raw, ['ASC', 'DESC'], true) ? $order_raw : 'DESC';

        $query_args = [
            'post_type'      => 'wcp_user_activation',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        if ($filter_creator_id > 0) {
            $query_args['author'] = $filter_creator_id;
        }

        if ($filter_order_id > 0) {
            $query_args['meta_query'] = [
                [
                    'key'     => 'wcp_order_id',
                    'value'   => $filter_order_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ];
        }

        $all_posts = get_posts($query_args);

        if ($search !== '') {
            $all_posts = array_values(array_filter($all_posts, function ($post) use ($search) {
                if (stripos($post->post_title, $search) !== false) {
                    return true;
                }
                $email      = (string) get_post_meta($post->ID, 'wcp_email', true);
                $full_name  = trim((string) get_post_meta($post->ID, 'wcp_first_name', true) . ' ' . (string) get_post_meta($post->ID, 'wcp_last_name', true));
                $created_by = (string) get_post_meta($post->ID, 'wcp_created_by_display', true);
                return stripos($email, $search) !== false
                    || stripos($full_name, $search) !== false
                    || stripos($created_by, $search) !== false;
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
                if ($amount <= 0) {
                    $amount = 1.0;
                }
                return '&euro;' . number_format($amount, 2, ',', '.');

            case 'order_id':
                $oid = (int) get_post_meta($item->ID, 'wcp_order_id', true);
                if ($oid <= 0) {
                    return '&mdash;';
                }

                // Click on #ID filters ONLY by order and resets creator filter
                $filter_url = $this->build_filter_url([
                    'filter_order_id'   => $oid,
                    'filter_creator_id' => '', // RESET
                    'paged'             => 1,
                ]);

                // Keep separate link to open WooCommerce order edit screen
                $edit_url = get_edit_post_link($oid);

                $html = '<a href="' . esc_url($filter_url) . '">#' . esc_html((string) $oid) . '</a>';
                if ($edit_url) {
                    $html .= ' <a href="' . esc_url($edit_url) . '" target="_blank" rel="noopener noreferrer" style="font-size:12px;">(Apri)</a>';
                }
                return $html;

            case 'created_by':
                // Prefer explicit meta, fallback to post_author
                $creator_id = (int) get_post_meta($item->ID, 'wcp_created_by_user_id', true);
                if ($creator_id <= 0) {
                    $creator_id = (int) $item->post_author;
                }

                $display = (string) get_post_meta($item->ID, 'wcp_created_by_display', true);

                // Fallback display: user_login (email)
                if ($display === '' && $creator_id > 0) {
                    $author = get_userdata($creator_id);
                    if ($author) {
                        $display = sprintf('%s (%s)', $author->user_login, $author->user_email);
                    }
                }

                if ($display === '' || $creator_id <= 0) {
                    return '&mdash;';
                }

                // Click filters ONLY by creator and resets order filter
                $filter_url = $this->build_filter_url([
                    'filter_creator_id' => $creator_id,
                    'filter_order_id'   => '', // RESET
                    'paged'             => 1,
                ]);

                return '<a href="' . esc_url($filter_url) . '">' . esc_html($display) . '</a>';

            case 'post_date':
                return esc_html(date_i18n('d/m/Y H:i', strtotime($item->post_date)));

            case 'actions':
                // Use server-side admin-post handler to ensure remote unenroll + local delete,
                // without relying on JS/AJAX.
                $delete_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'action'        => 'wcp_admin_unenroll',
                            'activation_id' => $item->ID,
                        ],
                        admin_url('admin-post.php')
                    ),
                    'wcp_admin_unenroll'
                );

                return '<a href="' . esc_url($delete_url) . '" class="button" style="color:#b32d2e;" '
                    . 'onclick="return confirm(\'Annullare questa registrazione? Verrà chiamato il sito remoto e la riga verrà eliminata.\');">'
                    . 'Annulla</a>';

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
