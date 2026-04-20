<?php
/*
Plugin Name: WooCommerce Personal Coupon Manager
Description: Gestione attivazioni utente dall'area "Il mio account" con sistema credito e integrazione remota su Sito B.
Version: 4.0
Author: Inamo87100
*/
if (!defined('ABSPATH')) exit;

class WC_Personal_Coupon_Manager {
    private const VALID_COST_PATTERN          = '/^(?:\d+|\d*\.\d+)$/';
    private const CREDIT_VALIDATION_TOLERANCE = 0.001;

    public function __construct() {
        add_action('init', [$this, 'add_my_account_endpoint']);
        add_action('init', [$this, 'register_cpt']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_registrazione-corsista_endpoint', [$this, 'endpoint_content']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wcp_create_user', [$this, 'handle_user_creation']);
        add_action('wp_ajax_wcp_unenroll_user', [$this, 'handle_unenroll']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_wcp_save_settings', [$this, 'save_settings']);
        add_action('admin_post_wcp_generate_secret', [$this, 'generate_secret_key']);
        add_action('admin_notices', [$this, 'admin_notices_wcp']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    // -------------------------------------------------------------------------
    // CPT
    // -------------------------------------------------------------------------

    public function register_cpt() {
        register_post_type('wcp_user_activation', [
            'labels'   => ['name' => 'WCP User Activations', 'singular_name' => 'WCP User Activation'],
            'public'   => false,
            'supports' => ['title', 'author'],
        ]);
    }

    // -------------------------------------------------------------------------
    // My Account endpoint
    // -------------------------------------------------------------------------

    public function add_my_account_endpoint() {
        add_rewrite_endpoint('registrazione-corsista', EP_PAGES);
    }

    public function add_menu_item($items) {
        if ($this->can_access()) {
            $items = array_slice($items, 0, 1, true)
                   + ['registrazione-corsista' => 'Registrazione corsista']
                   + array_slice($items, 1, null, true);
        }
        return $items;
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    public function can_access() {
        $user = wp_get_current_user();
        if (!$user->ID) {
            return false;
        }
        $allowed_roles = get_option('wcp_allowed_roles', ['administrator']);
        if (!is_array($allowed_roles)) {
            $allowed_roles = ['administrator'];
        }
        foreach ($allowed_roles as $role) {
            if (in_array($role, $user->roles)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Asset enqueue
    // -------------------------------------------------------------------------

    public function enqueue_assets() {
        if (function_exists('is_account_page') && is_account_page()) {
            global $wp;
            if (isset($wp->query_vars['registrazione-corsista'])) {
                $style_path     = plugin_dir_path(__FILE__) . 'style.css';
                $script_path    = plugin_dir_path(__FILE__) . 'wcp-scripts.js';
                $style_version  = file_exists($style_path) ? filemtime($style_path) : time();
                $script_version = file_exists($script_path) ? filemtime($script_path) : time();

                wp_enqueue_style('wcp-style', plugins_url('style.css', __FILE__), [], $style_version);
                wp_enqueue_script('wcp-scripts', plugins_url('wcp-scripts.js', __FILE__), ['jquery'], $script_version, true);
                wp_localize_script('wcp-scripts', 'wcp_ajax', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('wcp_nonce'),
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Endpoint content
    // -------------------------------------------------------------------------

    public function endpoint_content() {
        if (!$this->can_access()) {
            echo '<p>Non hai i permessi per accedere a questa sezione.</p>';
            return;
        }

        $user_id = get_current_user_id();

        echo '<div class="wcpcm-form-container">';
        $this->render_form($user_id);
        echo '</div>';

        echo '<div class="wcpcm-table-container">';
        echo '<h3>Storico attivazioni</h3>';
        $this->list_activations($user_id);
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // (… resto del file invariato: render_form, credito, ecc.)
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // AJAX: unenroll user
    // -------------------------------------------------------------------------

    public function handle_unenroll() {
        check_ajax_referer('wcp_nonce', 'nonce');

        $activation_id = isset($_POST['activation_id']) ? intval($_POST['activation_id']) : 0;
        if (!$activation_id) {
            wp_send_json_error(['msg' => 'ID attivazione non valido.']);
        }

        $post = get_post($activation_id);
        if (!$post || $post->post_type !== 'wcp_user_activation' || $post->post_status !== 'publish') {
            wp_send_json_error(['msg' => 'Attivazione non trovata.']);
        }

        // ADMIN ONLY: frontend users cannot unenroll
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'Operazione disponibile solo in amministrazione.']);
        }

        $email         = (string) get_post_meta($activation_id, 'wcp_email',        true);
        $course_id     = (int)    get_post_meta($activation_id, 'wcp_course_id',     true);
        $order_item_id = (int)    get_post_meta($activation_id, 'wcp_order_item_id', true);
        $cost          = (float)  get_post_meta($activation_id, 'wcp_credit_cost',   true);

        $response = $this->call_remote_api('/wp-json/nf/v1/unenroll-user', 'POST', [
            'action'     => 'unenroll_user',
            'user_email' => $email,
            'course_id'  => $course_id,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['msg' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($code < 200 || $code >= 300 || empty($json['success'])) {
            $msg = isset($json['data']['msg']) ? (string) $json['data']['msg'] : 'Errore remoto.';
            wp_send_json_error(['msg' => $msg]);
        }

        // Rollback credit consumption and set activation as draft/trash (keep your existing logic)
        $this->rollback_lot_consumption((int) $post->post_author, $order_item_id, $cost);

        // You likely already do something here (trash/unpublish). Keep behavior unchanged:
        wp_trash_post($activation_id);

        $new_remaining = $this->get_user_remaining_credit((int) $post->post_author);

        wp_send_json_success([
            'remaining_credit' => number_format($new_remaining, 2, ',', '.'),
            'post_id'          => $activation_id,
        ]);
    }

    // -------------------------------------------------------------------------
    // List activations (FRONTEND)
    // -------------------------------------------------------------------------

    public function list_activations($user_id) {
        $posts = get_posts([
            'post_type'      => 'wcp_user_activation',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if (!$posts) {
            echo '<p>Nessuna attivazione trovata.</p>';
            return;
        }

        // Non serve più il nonce perché non mostriamo più azioni lato frontend
        echo '<table class="wcpcm-table"><thead><tr>
            <th>Email</th>
            <th>Nome</th>
            <th>Cognome</th>
            <th>Corso</th>
            <th>Credito scalato (&euro;)</th>
            <th>N. Ordine</th>
            <th>Creato il</th>
        </tr></thead><tbody>';

        foreach ($posts as $post) {
            $amount      = (float)  get_post_meta($post->ID, 'wcp_credit_cost',  true);
            $first_name  = (string) get_post_meta($post->ID, 'wcp_first_name',   true);
            $last_name   = (string) get_post_meta($post->ID, 'wcp_last_name',    true);
            $course_name = (string) get_post_meta($post->ID, 'wcp_course_name',  true);
            $email       = (string) get_post_meta($post->ID, 'wcp_email',        true);
            $order_id    = (int)    get_post_meta($post->ID, 'wcp_order_id',     true);

            echo '<tr>';
            echo '<td>' . esc_html($email) . '</td>';
            echo '<td>' . esc_html($first_name) . '</td>';
            echo '<td>' . esc_html($last_name) . '</td>';
            echo '<td>' . esc_html($course_name) . '</td>';
            echo '<td>&euro;' . number_format($amount > 0 ? $amount : 1.0, 2, ',', '.') . '</td>';
            echo '<td>' . ($order_id > 0 ? esc_html((string) $order_id) : '&mdash;') . '</td>';
            echo '<td>' . esc_html(date_i18n('d/m/Y', strtotime($post->post_date))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // -------------------------------------------------------------------------
    // (… resto del file invariato)
    // -------------------------------------------------------------------------
}
