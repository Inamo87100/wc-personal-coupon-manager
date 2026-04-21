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
                $style_version  = file_exists($style_path)  ? filemtime($style_path)  : '4.0';
                $script_version = file_exists($script_path) ? filemtime($script_path) : '4.0';

                wp_enqueue_style('wcp-style', plugin_dir_url(__FILE__) . 'style.css', [], $style_version);
                wp_enqueue_script('wcp-ajax', plugin_dir_url(__FILE__) . 'wcp-scripts.js', ['jquery'], $script_version, true);
                $user_id = get_current_user_id();
                wp_localize_script('wcp-ajax', 'wcp_ajax', [
                    'ajax_url'         => admin_url('admin-ajax.php'),
                    'nonce'            => wp_create_nonce('wcp_nonce'),
                    'unenroll_nonce'   => wp_create_nonce('wcp_nonce'),
                    'remaining_credit' => $this->get_user_remaining_credit($user_id),
                ]);
            }
        }
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wcp-manager') === false) {
            return;
        }
        $script_path    = plugin_dir_path(__FILE__) . 'wcp-scripts.js';
        $script_version = file_exists($script_path) ? filemtime($script_path) : '4.0';
        wp_enqueue_script('wcp-ajax-admin', plugin_dir_url(__FILE__) . 'wcp-scripts.js', ['jquery'], $script_version, true);
        wp_localize_script('wcp-ajax-admin', 'wcp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wcp_nonce'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Credit system – FIFO lots
    // -------------------------------------------------------------------------

    private function get_credit_products_map() {
        $map = get_option('wcp_credit_products_map', []);
        if (!is_array($map)) {
            return [];
        }
        $result = [];
        foreach ($map as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $product_id       = isset($entry['product_id'])       ? intval($entry['product_id'])       : 0;
            $credit_generated = isset($entry['credit_generated']) ? (float) $entry['credit_generated'] : 0.0;
            $users_allowed    = isset($entry['users_allowed'])    ? intval($entry['users_allowed'])    : 0;
            $cost_per_user    = isset($entry['cost_per_user'])    ? (float) $entry['cost_per_user']    : 0.0;
            if ($product_id > 0 && $credit_generated > 0 && $users_allowed > 0 && $cost_per_user > 0) {
                $result[] = [
                    'product_id'       => $product_id,
                    'credit_generated' => $credit_generated,
                    'users_allowed'    => $users_allowed,
                    'cost_per_user'    => $cost_per_user,
                ];
            }
        }
        return $result;
    }

    private function get_credit_lots($user_id) {
        $credit_map = $this->get_credit_products_map();
        if (empty($credit_map)) {
            return [];
        }

        $credit_map_by_id = [];
        foreach ($credit_map as $entry) {
            $credit_map_by_id[$entry['product_id']] = $entry;
        }

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status'      => 'completed',
            'limit'       => -1,
        ]);

        $lots = [];
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item_id => $item) {
                $pid = $item->get_product_id();
                if (!isset($credit_map_by_id[$pid])) {
                    continue;
                }
                $map_entry     = $credit_map_by_id[$pid];
                $qty           = max(1, (int) $item->get_quantity());
                $credit_total  = $map_entry['credit_generated'] * $qty;
                $users_total   = $map_entry['users_allowed'] * $qty;
                $cost_per_user = $map_entry['cost_per_user'];

                $consumed = get_user_meta($user_id, 'wcp_lot_' . $item_id, true);
                if (!is_array($consumed)) {
                    $consumed = ['consumed_users' => 0, 'consumed_credit' => 0.0];
                }
                $consumed_users  = isset($consumed['consumed_users'])  ? max(0, (int) $consumed['consumed_users'])    : 0;
                $consumed_credit = isset($consumed['consumed_credit']) ? max(0.0, (float) $consumed['consumed_credit']) : 0.0;

                $lots[] = [
                    'order_id'         => $order->get_id(),
                    'order_item_id'    => $item_id,
                    'product_id'       => $pid,
                    'date'             => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
                    'credit_total'     => $credit_total,
                    'users_total'      => $users_total,
                    'cost_per_user'    => $cost_per_user,
                    'consumed_users'   => $consumed_users,
                    'consumed_credit'  => $consumed_credit,
                    'remaining_users'  => max(0, $users_total - $consumed_users),
                    'remaining_credit' => max(0.0, $credit_total - $consumed_credit),
                ];
            }
        }

        // Sort by date ASC (FIFO)
        usort($lots, function ($a, $b) {
            return $a['date'] <=> $b['date'];
        });

        return $lots;
    }

    public function get_user_credit_total($user_id) {
        $lots  = $this->get_credit_lots($user_id);
        $total = 0.0;
        foreach ($lots as $lot) {
            $total += $lot['credit_total'];
        }
        return $total;
    }

    public function get_user_remaining_credit($user_id) {
        $lots      = $this->get_credit_lots($user_id);
        $remaining = 0.0;
        foreach ($lots as $lot) {
            $remaining += $lot['remaining_credit'];
        }
        return $remaining;
    }

    public function get_user_remaining_registrations($user_id) {
        $lots      = $this->get_credit_lots($user_id);
        $remaining = 0;
        foreach ($lots as $lot) {
            $remaining += (int) ($lot['remaining_users'] ?? 0);
        }
        return max(0, (int) $remaining);
    }

    private function consume_first_available_lot($user_id) {
        $lots = $this->get_credit_lots($user_id);
        foreach ($lots as $lot) {
            if ($lot['remaining_users'] > 0 && $lot['remaining_credit'] >= $lot['cost_per_user']) {
                $item_id  = $lot['order_item_id'];
                $consumed = get_user_meta($user_id, 'wcp_lot_' . $item_id, true);
                if (!is_array($consumed)) {
                    $consumed = ['consumed_users' => 0, 'consumed_credit' => 0.0];
                }
                $consumed['consumed_users']  = (int) $consumed['consumed_users'] + 1;
                $consumed['consumed_credit'] = (float) $consumed['consumed_credit'] + $lot['cost_per_user'];
                update_user_meta($user_id, 'wcp_lot_' . $item_id, $consumed);
                return $lot;
            }
        }
        return null;
    }

    private function rollback_lot_consumption($user_id, $order_item_id, $cost_per_user) {
        $key      = 'wcp_lot_' . $order_item_id;
        $consumed = get_user_meta($user_id, $key, true);
        if (!is_array($consumed)) {
            return;
        }
        $consumed['consumed_users']  = max(0, (int) $consumed['consumed_users'] - 1);
        $consumed['consumed_credit'] = max(0.0, (float) $consumed['consumed_credit'] - (float) $cost_per_user);
        update_user_meta($user_id, $key, $consumed);
    }

    // -------------------------------------------------------------------------
    // Endpoint content
    // -------------------------------------------------------------------------

    public function endpoint_content() {
        if (!$this->can_access()) {
            echo '<div class="wcpcm-form-container"><p>Non hai i permessi per accedere a questa sezione.</p></div>';
            return;
        }
        $user_id                    = get_current_user_id();
        $credit_remaining           = $this->get_user_remaining_credit($user_id);
        $registrazioni_disponibili  = $this->get_user_remaining_registrations($user_id);
        $products_map     = $this->get_normalized_products_map();
        $current_user     = wp_get_current_user();
        $default_first    = get_user_meta($user_id, 'first_name', true);
        $default_last     = get_user_meta($user_id, 'last_name', true);
        if (!$default_first) {
            $default_first = $current_user->first_name;
        }
        if (!$default_last) {
            $default_last = $current_user->last_name;
        }
        ?>
        <div class="wcpcm-form-container">
            <div class="wcpcm-credit-bar">
                <span class="wcpcm-credit-label">Registrazioni disponibili:</span>
                <span class="wcpcm-credit-total"><?php echo (int) $registrazioni_disponibili; ?></span>
                <span class="wcpcm-credit-sep">|</span>
                <span class="wcpcm-credit-label">Credito disponibile:</span>
                <span class="wcpcm-credit-remaining" id="wcp-credit-remaining">&euro;<?php echo number_format($credit_remaining, 2, ',', '.'); ?></span>
            </div>
            <h2 style="margin-bottom:1em;color:#274690;">Registrazione corsista su Nuova Formamentis</h2>
            <form id="wcp-create-user-form" class="wcpcm-create-coupon-form">
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-course">Corso <span style="color:red">*</span></label>
                    <select class="wcpcm-input" name="course_id" id="wcp-course" required>
                        <option value="">-- Seleziona un corso --</option>
                        <?php foreach ($products_map as $entry) : ?>
                            <?php if (!empty($entry['name']) && !empty($entry['course_id'])) : ?>
                                <option value="<?php echo esc_attr($entry['course_id']); ?>"><?php echo esc_html($entry['name']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-first-name">Nome <span style="color:red">*</span></label>
                    <input class="wcpcm-input" type="text" name="first_name" id="wcp-first-name" required value="<?php echo esc_attr($default_first); ?>" placeholder="Inserisci nome">
                </div>
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-last-name">Cognome <span style="color:red">*</span></label>
                    <input class="wcpcm-input" type="text" name="last_name" id="wcp-last-name" required value="<?php echo esc_attr($default_last); ?>" placeholder="Inserisci cognome">
                </div>
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-email">Email utente <span style="color:red">*</span></label>
                    <input class="wcpcm-input" type="email" name="email" id="wcp-email" required placeholder="Inserisci email utente">
                </div>
                <button class="wcpcm-btn" type="submit">Registra corsista</button>
            </form>
            <div id="wcp-form-msg"></div>
        </div>
        <div class="wcpcm-table-container">
            <h3 style="color:#274690; margin:1.8em 0 0.8em 0;">Storico attivazioni utenti</h3>
            <?php $this->list_activations($user_id); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: create user
    // -------------------------------------------------------------------------

    public function handle_user_creation() {
        check_ajax_referer('wcp_nonce', 'nonce');
        if (!$this->can_access()) {
            wp_send_json_error(['msg' => 'Non hai i permessi.']);
        }
        $user_id    = get_current_user_id();
        $course_id  = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
        // Backward compatibility for legacy frontend payloads.
        if ($course_id <= 0 && isset($_POST['product_id'])) {
            $course_id = intval($_POST['product_id']);
        }
        $email      = isset($_POST['email'])      ? sanitize_email($_POST['email'])           : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
        $last_name  = isset($_POST['last_name'])  ? sanitize_text_field($_POST['last_name'])  : '';

        if (!$course_id || !$email || !$first_name || !$last_name || !is_email($email)) {
            wp_send_json_error(['msg' => "Compila tutti i campi obbligatori e inserisci un'email valida."]);
        }

        $selected_entry = $this->get_products_map_entry_by_course_id($course_id);
        if (!$selected_entry) {
            wp_send_json_error(['msg' => 'Corso non valido.']);
        }

        // Consume first available FIFO lot
        $lot = $this->consume_first_available_lot($user_id);
        if (!$lot) {
            wp_send_json_error(['msg' => 'Nessun credito o slot utente disponibile.']);
        }

        $activation_cost = $lot['cost_per_user'];
        $course_name     = $selected_entry['name'];
        $current_user    = wp_get_current_user();
        $payload = [
            'action'     => 'create_user',
            'user_email' => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'course_ids' => [intval($course_id)],
        ];
        if ($current_user->exists() && $current_user->user_email !== '') {
            $creator_login = sanitize_user($current_user->user_login, true);
            $creator_email = sanitize_email($current_user->user_email);
            if ($creator_login !== '' && $creator_email !== '') {
                $payload['created_by'] = sprintf('%s (%s)', $creator_login, $creator_email);
            }
        }

        $response = $this->call_remote_api('/wp-json/nf/v1/create-user', 'POST', $payload);

        if (is_wp_error($response)) {
            $this->rollback_lot_consumption($user_id, $lot['order_item_id'], $lot['cost_per_user']);
            wp_send_json_error(['msg' => 'Errore di connessione al sito remoto: ' . $response->get_error_message()]);
        }

        if (isset($response['success']) && !$response['success']) {
            $this->rollback_lot_consumption($user_id, $lot['order_item_id'], $lot['cost_per_user']);
            $error_message = isset($response['message']) ? sanitize_text_field($response['message']) : 'Errore nella creazione/aggiornamento utente remoto.';
            wp_send_json_error(['msg' => $error_message]);
        }

        $post_id = wp_insert_post([
            'post_title'  => sanitize_text_field($email . ' - ' . $course_name),
            'post_status' => 'publish',
            'post_type'   => 'wcp_user_activation',
            'post_author' => $user_id,
        ]);

        if (is_wp_error($post_id)) {
            $this->rollback_lot_consumption($user_id, $lot['order_item_id'], $lot['cost_per_user']);
            wp_send_json_error(['msg' => 'Utente creato/aggiornato sul sito remoto ma errore nel salvataggio storico locale.']);
        }

        update_post_meta($post_id, 'wcp_credit_cost',       $activation_cost);
        update_post_meta($post_id, 'wcp_course_id',         intval($course_id));
        update_post_meta($post_id, 'wcp_course_name',       $course_name);
        update_post_meta($post_id, 'wcp_email',             $email);
        update_post_meta($post_id, 'wcp_first_name',        $first_name);
        update_post_meta($post_id, 'wcp_last_name',         $last_name);
        update_post_meta($post_id, 'wcp_order_id',          $lot['order_id']);
        update_post_meta($post_id, 'wcp_order_item_id',     $lot['order_item_id']);
        update_post_meta($post_id, 'wcp_credit_product_id', $lot['product_id']);
        update_post_meta($post_id, 'wcp_created_by_user_id', $user_id);
        if (isset($payload['created_by'])) {
            update_post_meta($post_id, 'wcp_created_by_display', $payload['created_by']);
        }

        $new_remaining = $this->get_user_remaining_credit($user_id);

        wp_send_json_success([
            'msg'              => 'Utente creato/aggiornato con successo su Nuova Formamentis.',
            'remaining_credit' => number_format($new_remaining, 2, ',', '.'),
        ]);
    }

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

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'Non hai i permessi per annullare questa registrazione dal frontend.']);
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
            wp_send_json_error(['msg' => 'Errore di connessione al sito remoto: ' . $response->get_error_message()]);
        }

        $success        = isset($response['success']) ? (bool) $response['success'] : false;
        $msg            = isset($response['msg'])     ? $response['msg']     : (isset($response['message']) ? $response['message'] : '');
        $user_not_found = false;

        if (!$success) {
            if (stripos((string) $msg, 'non trovato') !== false || stripos((string) $msg, 'not found') !== false) {
                $user_not_found = true;
            }
        }

        if (!$success && !$user_not_found) {
            wp_send_json_error(['msg' => $msg ?: 'Errore durante la disiscrizione.']);
        }

        // Success or user_not_found: rollback lot and delete record
        if ($order_item_id > 0 && $cost > 0) {
            $this->rollback_lot_consumption((int) $post->post_author, $order_item_id, $cost);
        }

        wp_delete_post($activation_id, true);

        $new_remaining = $this->get_user_remaining_credit((int) $post->post_author);

        wp_send_json_success([
            'remaining_credit' => number_format($new_remaining, 2, ',', '.'),
            'post_id'          => $activation_id,
        ]);
    }

    // -------------------------------------------------------------------------
    // List activations
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
    // Remote API helper
    // -------------------------------------------------------------------------

    public function call_remote_api($endpoint, $method, $data) {
        $base_url   = rtrim(get_option('wcp_remote_site_url', ''), '/');
        $secret_key = get_option('wcp_secret_key', '');

        if (!$base_url) {
            return new WP_Error('no_remote_url', 'URL del sito remoto non configurato.');
        }

        if (strpos($base_url, 'https://') !== 0) {
            return new WP_Error('insecure_url', 'L\'URL del sito remoto deve utilizzare HTTPS.');
        }

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-NF-SECRET'  => $secret_key,
            ],
            'timeout' => 15,
        ];

        if (!empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($base_url . $endpoint, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new WP_Error('invalid_response', 'Risposta non valida dal sito remoto.');
        }
        return $body;
    }

    // -------------------------------------------------------------------------
    // Admin menu
    // -------------------------------------------------------------------------

    public function register_admin_menu() {
        add_menu_page(
            'WC User Activation Manager',
            'WC User Activation Manager',
            'manage_options',
            'wcp-manager',
            [$this, 'render_coupons_page'],
            'dashicons-admin-users',
            56
        );
        add_submenu_page(
            'wcp-manager',
            'Attivazioni utenti',
            'Attivazioni utenti',
            'manage_options',
            'wcp-manager',
            [$this, 'render_coupons_page']
        );
        add_submenu_page(
            'wcp-manager',
            'Impostazioni',
            'Impostazioni',
            'manage_options',
            'wcp-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_coupons_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        require_once plugin_dir_path(__FILE__) . 'includes/class-wcp-coupons-table.php';
        $table = new WCP_Coupons_Table($this);
        $table->prepare_items();

        $filter_order_id   = isset($_GET['filter_order_id'])   ? intval($_GET['filter_order_id'])   : 0;
        $filter_creator_id = isset($_GET['filter_creator_id']) ? intval($_GET['filter_creator_id']) : 0;
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">WC User Activation Manager &mdash; Storico attivazioni</h1>
            <hr class="wp-header-end">
            <form method="get">
                <input type="hidden" name="page" value="wcp-manager">
                <?php if ($filter_order_id > 0) : ?>
                    <input type="hidden" name="filter_order_id" value="<?php echo esc_attr((string) $filter_order_id); ?>">
                <?php endif; ?>
                <?php if ($filter_creator_id > 0) : ?>
                    <input type="hidden" name="filter_creator_id" value="<?php echo esc_attr((string) $filter_creator_id); ?>">
                <?php endif; ?>
                <?php $table->search_box('Cerca attivazione', 'wcp_activation_search'); ?>
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    public function admin_notices_wcp() {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if (!in_array($page, ['wcp-manager', 'wcp-settings'], true)) {
            return;
        }
        if (isset($_GET['wcp_message'])) {
            $message = sanitize_text_field(urldecode($_GET['wcp_message']));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        if (isset($_GET['wcp_error'])) {
            $error = sanitize_text_field(urldecode($_GET['wcp_error']));
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
        }
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $remote_url          = get_option('wcp_remote_site_url', '');
        $secret_key          = get_option('wcp_secret_key', '');
        $credit_products_map = get_option('wcp_credit_products_map', []);
        if (!is_array($credit_products_map)) {
            $credit_products_map = [];
        }

        // Migration: if credit products map is empty but old IDs option exists, pre-populate rows
        $show_migration_notice = false;
        if (empty($credit_products_map)) {
            $old_ids_raw = get_option('wcp_credit_product_ids', '');
            if ($old_ids_raw) {
                $old_ids = preg_split('/[\s,]+/', (string) $old_ids_raw, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($old_ids as $oid) {
                    $credit_products_map[] = [
                        'product_id'       => intval($oid),
                        'credit_generated' => '0.00',
                        'users_allowed'    => 0,
                        'cost_per_user'    => '0.00',
                    ];
                }
                $show_migration_notice = !empty($credit_products_map);
            }
        }

        $products_map  = $this->get_normalized_products_map();
        $allowed_roles = get_option('wcp_allowed_roles', ['administrator']);
        if (!is_array($allowed_roles)) {
            $allowed_roles = ['administrator'];
        }
        $all_roles = wp_roles()->get_names();

        $saved     = isset($_GET['saved'])     && $_GET['saved']     === '1';
        $generated = isset($_GET['generated']) && $_GET['generated'] === '1';
        ?>
        <div class="wrap">
            <h1>WC User Activation Manager &mdash; Impostazioni</h1>
            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p>Impostazioni salvate.</p></div>
            <?php endif; ?>
            <?php if ($generated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Chiave segreta generata e salvata.</strong> Copia la chiave dal campo qui sotto e usala su Sito B come valore dell'header <code>X-NF-SECRET</code>.</p>
                </div>
            <?php endif; ?>
            <?php if ($show_migration_notice) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p>Configura i nuovi campi prodotti credito: per ogni riga inserisci credito generato, utenti e costo per utente.</p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wcp_save_settings">
                <?php wp_nonce_field('wcp_settings_nonce', 'wcp_settings_nonce_field'); ?>

                <h2>1. Connessione Sito B</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="wcp_remote_site_url">URL Sito B</label></th>
                        <td>
                            <input type="url" id="wcp_remote_site_url" name="wcp_remote_site_url"
                                   value="<?php echo esc_attr($remote_url); ?>"
                                   class="regular-text" placeholder="https://negozio.it">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcp_secret_key">Chiave segreta</label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <input type="password" id="wcp_secret_key" name="wcp_secret_key"
                                       value="<?php echo esc_attr($secret_key); ?>"
                                       class="regular-text" placeholder="chiave condivisa X-NF-SECRET"
                                       autocomplete="new-password">
                                <button type="button" id="wcp-toggle-secret" class="button"
                                        onclick="(function(btn){var f=document.getElementById('wcp_secret_key');f.type=f.type==='password'?'text':'password';btn.textContent=f.type==='password'?'Mostra':'Nascondi';})(this)">Mostra</button>
                            </div>
                            <p class="description">Chiave condivisa usata nell'header <code>X-NF-SECRET</code>.</p>
                        </td>
                    </tr>
                </table>

                <h2>2. Prodotti che generano credito (Sito A)</h2>
                <p>Definisci i prodotti di Sito A che generano credito, il credito generato, gli utenti creabili e il costo per registrazione (deve valere: credito generato = utenti &times; costo per utente).</p>
                <div id="wcp-credit-products-map">
                    <?php
                    if (!empty($credit_products_map)) {
                        foreach ($credit_products_map as $i => $entry) {
                            $pid = isset($entry['product_id'])       ? intval($entry['product_id'])    : 0;
                            $cg  = isset($entry['credit_generated']) ? $entry['credit_generated']      : '0.00';
                            $ua  = isset($entry['users_allowed'])    ? intval($entry['users_allowed']) : 0;
                            $cpu = isset($entry['cost_per_user'])    ? $entry['cost_per_user']         : '0.00';
                            echo '<div class="wcp-credit-row" style="display:flex;gap:8px;margin-bottom:6px;">';
                            echo '<input type="number" name="wcp_credit_products_map[' . $i . '][product_id]" value="' . esc_attr($pid) . '" placeholder="ID prodotto" min="1" step="1" style="flex:1;">';
                            echo '<input type="text" inputmode="decimal" name="wcp_credit_products_map[' . $i . '][credit_generated]" value="' . esc_attr($cg) . '" placeholder="Credito &euro;" style="flex:1;">';
                            echo '<input type="number" name="wcp_credit_products_map[' . $i . '][users_allowed]" value="' . esc_attr($ua) . '" placeholder="N. utenti" min="1" step="1" style="flex:1;">';
                            echo '<input type="text" inputmode="decimal" name="wcp_credit_products_map[' . $i . '][cost_per_user]" value="' . esc_attr($cpu) . '" placeholder="Costo/utente &euro;" style="flex:1;">';
                            echo '<button type="button" class="button wcp-remove-credit-row">Rimuovi</button>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="wcp-credit-row" style="display:flex;gap:8px;margin-bottom:6px;">';
                        echo '<input type="number" name="wcp_credit_products_map[0][product_id]" value="" placeholder="ID prodotto" min="1" step="1" style="flex:1;">';
                        echo '<input type="text" inputmode="decimal" name="wcp_credit_products_map[0][credit_generated]" value="" placeholder="Credito &euro;" style="flex:1;">';
                        echo '<input type="number" name="wcp_credit_products_map[0][users_allowed]" value="" placeholder="N. utenti" min="1" step="1" style="flex:1;">';
                        echo '<input type="text" inputmode="decimal" name="wcp_credit_products_map[0][cost_per_user]" value="" placeholder="Costo/utente &euro;" style="flex:1;">';
                        echo '<button type="button" class="button wcp-remove-credit-row">Rimuovi</button>';
                        echo '</div>';
                    }
                    ?>
                </div>
                <button type="button" id="wcp-add-credit-row" class="button">+ Aggiungi prodotto credito</button>
                <script>
                (function () {
                    var container = document.getElementById('wcp-credit-products-map');
                    var idx = container.querySelectorAll('.wcp-credit-row').length;
                    document.getElementById('wcp-add-credit-row').addEventListener('click', function () {
                        var row = document.createElement('div');
                        row.className = 'wcp-credit-row';
                        row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
                        row.innerHTML =
                            '<input type="number" name="wcp_credit_products_map[' + idx + '][product_id]" value="" placeholder="ID prodotto" min="1" step="1" style="flex:1;">'
                            + '<input type="text" inputmode="decimal" name="wcp_credit_products_map[' + idx + '][credit_generated]" value="" placeholder="Credito \u20ac" style="flex:1;">'
                            + '<input type="number" name="wcp_credit_products_map[' + idx + '][users_allowed]" value="" placeholder="N. utenti" min="1" step="1" style="flex:1;">'
                            + '<input type="text" inputmode="decimal" name="wcp_credit_products_map[' + idx + '][cost_per_user]" value="" placeholder="Costo/utente \u20ac" style="flex:1;">'
                            + '<button type="button" class="button wcp-remove-credit-row">Rimuovi</button>';
                        container.appendChild(row);
                        idx++;
                    });
                    container.addEventListener('click', function (e) {
                        if (e.target && e.target.classList.contains('wcp-remove-credit-row')) {
                            e.target.closest('.wcp-credit-row').remove();
                        }
                    });
                })();
                </script>

                <h2>3. Corsi disponibili (Sito B)</h2>
                <p>Mappa "Nome visualizzato" &rarr; "ID corso su Sito B".</p>
                <div id="wcp-products-map">
                    <?php
                    if (!empty($products_map)) {
                        foreach ($products_map as $i => $entry) {
                            $name      = isset($entry['name'])      ? $entry['name']      : '';
                            $course_id = isset($entry['course_id']) ? $entry['course_id'] : '';
                            echo '<div class="wcp-map-row" style="display:flex;gap:8px;margin-bottom:6px;">';
                            echo '<input type="text" name="wcp_products_map[' . $i . '][name]" value="' . esc_attr($name) . '" placeholder="Nome prodotto" style="flex:2;">';
                            echo '<input type="number" name="wcp_products_map[' . $i . '][course_id]" value="' . esc_attr($course_id) . '" placeholder="ID corso su Sito B" min="1" step="1" style="flex:1;">';
                            echo '<button type="button" class="button wcp-remove-row">Rimuovi</button>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="wcp-map-row" style="display:flex;gap:8px;margin-bottom:6px;">';
                        echo '<input type="text" name="wcp_products_map[0][name]" value="" placeholder="Nome prodotto" style="flex:2;">';
                        echo '<input type="number" name="wcp_products_map[0][course_id]" value="" placeholder="ID corso su Sito B" min="1" step="1" style="flex:1;">';
                        echo '<button type="button" class="button wcp-remove-row">Rimuovi</button>';
                        echo '</div>';
                    }
                    ?>
                </div>
                <button type="button" id="wcp-add-row" class="button">+ Aggiungi prodotto</button>
                <script>
                (function () {
                    var container = document.getElementById('wcp-products-map');
                    var idx = container.querySelectorAll('.wcp-map-row').length;
                    document.getElementById('wcp-add-row').addEventListener('click', function () {
                        var row = document.createElement('div');
                        row.className = 'wcp-map-row';
                        row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
                        row.innerHTML =
                            '<input type="text" name="wcp_products_map[' + idx + '][name]" value="" placeholder="Nome prodotto" style="flex:2;">'
                            + '<input type="number" name="wcp_products_map[' + idx + '][course_id]" value="" placeholder="ID corso su Sito B" min="1" step="1" style="flex:1;">'
                            + '<button type="button" class="button wcp-remove-row">Rimuovi</button>';
                        container.appendChild(row);
                        idx++;
                    });
                    container.addEventListener('click', function (e) {
                        if (e.target && e.target.classList.contains('wcp-remove-row')) {
                            e.target.closest('.wcp-map-row').remove();
                        }
                    });
                })();
                </script>

                <h2>4. Ruoli autorizzati</h2>
                <p>Seleziona quali ruoli WordPress possono accedere al pannello attivazioni.</p>
                <table class="form-table">
                    <tr>
                        <th>Ruoli</th>
                        <td>
                            <?php foreach ($all_roles as $role_slug => $role_name) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="wcp_allowed_roles[]"
                                           value="<?php echo esc_attr($role_slug); ?>"
                                           <?php checked(in_array($role_slug, $allowed_roles)); ?>
                                    >
                                    <?php echo esc_html($role_name); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Salva impostazioni'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <input type="hidden" name="action" value="wcp_generate_secret">
                <?php wp_nonce_field('wcp_generate_secret_nonce', 'wcp_generate_secret_nonce_field'); ?>
                <button type="submit" class="button button-secondary"
                        onclick="return confirm('Generare una nuova chiave segreta? La chiave attuale sarà sovrascritta.');">
                    🔑 Genera chiave segreta automaticamente
                </button>
            </form>
        </div>
        <?php
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato.');
        }
        check_admin_referer('wcp_settings_nonce', 'wcp_settings_nonce_field');

        // Validate credit products map FIRST – if any row fails, do NOT save anything
        $raw_credit_map   = isset($_POST['wcp_credit_products_map']) ? (array) $_POST['wcp_credit_products_map'] : [];
        $credit_map_clean = [];
        foreach ($raw_credit_map as $entry) {
            $pid = isset($entry['product_id'])       ? intval($entry['product_id'])                          : 0;
            $cg  = isset($entry['credit_generated']) ? $this->parse_eur_cost($entry['credit_generated'])     : 0.0;
            $ua  = isset($entry['users_allowed'])    ? intval($entry['users_allowed'])                       : 0;
            $cpu = isset($entry['cost_per_user'])    ? $this->parse_eur_cost($entry['cost_per_user'])        : 0.0;

            // Skip fully empty rows
            if (!$pid && !$cg && !$ua && !$cpu) {
                continue;
            }

            if (abs($cg - ($ua * $cpu)) > self::CREDIT_VALIDATION_TOLERANCE) {
                $expected = $ua * $cpu;
                $error_msg = urlencode(sprintf(
                    'Errore validazione prodotto ID %d: credito generato (%.2f) deve essere uguale a utenti (%d) × costo per utente (%.2f) = %.2f.',
                    $pid, $cg, $ua, $cpu, $expected
                ));
                wp_redirect(admin_url('admin.php?page=wcp-settings&wcp_error=' . $error_msg));
                exit;
            }

            $credit_map_clean[] = [
                'product_id'       => $pid,
                'credit_generated' => round($cg, 2),
                'users_allowed'    => $ua,
                'cost_per_user'    => round($cpu, 2),
            ];
        }

        // All validation passed – save everything
        $remote_url = isset($_POST['wcp_remote_site_url']) ? esc_url_raw(trim($_POST['wcp_remote_site_url'])) : '';
        update_option('wcp_remote_site_url', $remote_url);

        $secret_key = isset($_POST['wcp_secret_key']) ? sanitize_text_field($_POST['wcp_secret_key']) : '';
        update_option('wcp_secret_key', $secret_key);

        update_option('wcp_credit_products_map', $credit_map_clean);

        $raw_map      = isset($_POST['wcp_products_map']) ? (array) $_POST['wcp_products_map'] : [];
        $products_map = [];
        foreach ($raw_map as $entry) {
            $name      = isset($entry['name']) ? sanitize_text_field($entry['name']) : '';
            $course_id = $this->get_course_id_from_map_entry($entry);
            if ($name && $course_id) {
                $products_map[] = [
                    'name'      => $name,
                    'course_id' => $course_id,
                ];
            }
        }
        update_option('wcp_products_map', $products_map);

        $raw_roles     = isset($_POST['wcp_allowed_roles']) ? (array) $_POST['wcp_allowed_roles'] : [];
        $allowed_roles = array_map('sanitize_text_field', $raw_roles);
        if (empty($allowed_roles)) {
            $allowed_roles = ['administrator'];
        }
        update_option('wcp_allowed_roles', $allowed_roles);

        wp_redirect(admin_url('admin.php?page=wcp-settings&saved=1'));
        exit;
    }

    public function generate_secret_key() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato.');
        }
        check_admin_referer('wcp_generate_secret_nonce', 'wcp_generate_secret_nonce_field');

        // wp_generate_password(48) produces 48 random characters; base64_encode()
        // converts them to exactly 64 URL/define-safe characters (A-Z, a-z, 0-9, +, /, =).
        $raw        = wp_generate_password(48, true, false);
        $secret_key = base64_encode($raw);

        update_option('wcp_secret_key', $secret_key);

        wp_redirect(admin_url('admin.php?page=wcp-settings&generated=1'));
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function get_products_map_entry_by_course_id($course_id) {
        $products_map = $this->get_normalized_products_map();
        foreach ($products_map as $entry) {
            if ((int) $entry['course_id'] === (int) $course_id) {
                return $entry;
            }
        }
        return null;
    }

    private function get_normalized_products_map() {
        $raw_map = get_option('wcp_products_map', []);
        if (!is_array($raw_map)) {
            return [];
        }

        $products_map = [];
        foreach ($raw_map as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name      = isset($entry['name']) ? sanitize_text_field($entry['name']) : '';
            $course_id = $this->get_course_id_from_map_entry($entry);
            if (!$name || !$course_id) {
                continue;
            }
            $products_map[] = [
                'name'      => $name,
                'course_id' => $course_id,
            ];
        }
        return $products_map;
    }

    private function get_course_id_from_map_entry($entry) {
        if (!is_array($entry)) {
            return 0;
        }
        if (isset($entry['course_id'])) {
            return intval($entry['course_id']);
        }
        if (isset($entry['id'])) {
            return intval($entry['id']);
        }
        return 0;
    }

    private function parse_eur_cost($raw_cost) {
        $normalized = trim((string) $raw_cost);
        if ($normalized === '') {
            return 0.0;
        }

        $normalized = preg_replace('/\s+/', '', $normalized);
        $has_comma  = strpos($normalized, ',') !== false;
        $has_dot    = strpos($normalized, '.') !== false;

        if ($has_comma && $has_dot) {
            // Assume the last separator is decimal (supports 1.234,56 and 1,234.56).
            if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($has_comma) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!preg_match(self::VALID_COST_PATTERN, $normalized)) {
            return 0.0;
        }

        $cost = (float) $normalized;
        if ($cost <= 0) {
            return 0.0;
        }

        return round($cost, 2);
    }
}

new WC_Personal_Coupon_Manager();
