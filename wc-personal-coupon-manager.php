<?php
/*
Plugin Name: WooCommerce Personal Coupon Manager
Description: Gestione coupon dall'area "Il mio account" con sistema credito e creazione remota su Sito B.
Version: 3.0
Author: Inamo87100
*/
if (!defined('ABSPATH')) exit;

class WC_Personal_Coupon_Manager {

    public function __construct() {
        add_action('init', [$this, 'add_my_account_endpoint']);
        add_action('init', [$this, 'register_cpt']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_codici-sconto_endpoint', [$this, 'endpoint_content']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wcp_create_coupon', [$this, 'handle_coupon_creation']);
        add_action('wp_ajax_wcp_delete_coupon', [$this, 'handle_coupon_deletion']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_post_wcp_save_settings', [$this, 'save_settings']);
    }

    // -------------------------------------------------------------------------
    // CPT
    // -------------------------------------------------------------------------

    public function register_cpt() {
        register_post_type('wcp_coupon', [
            'labels'   => ['name' => 'WCP Coupon', 'singular_name' => 'WCP Coupon'],
            'public'   => false,
            'supports' => ['title', 'author'],
        ]);
    }

    // -------------------------------------------------------------------------
    // My Account endpoint
    // -------------------------------------------------------------------------

    public function add_my_account_endpoint() {
        add_rewrite_endpoint('codici-sconto', EP_PAGES);
    }

    public function add_menu_item($items) {
        if ($this->can_access()) {
            $items = array_slice($items, 0, 1, true)
                   + ['codici-sconto' => 'Codici sconto']
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
            if (isset($wp->query_vars['codici-sconto'])) {
                wp_enqueue_style('wcp-style', plugin_dir_url(__FILE__) . 'style.css', [], '3.0');
                wp_enqueue_script('wcp-ajax', plugin_dir_url(__FILE__) . 'wcp-scripts.js', ['jquery'], '3.0', true);
                $user_id = get_current_user_id();
                wp_localize_script('wcp-ajax', 'wcp_ajax', [
                    'ajax_url'         => admin_url('admin-ajax.php'),
                    'nonce'            => wp_create_nonce('wcp_nonce'),
                    'delete_nonce'     => wp_create_nonce('wcp_delete_nonce'),
                    'remaining_credit' => $this->get_user_remaining_credit($user_id),
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Credit system
    // -------------------------------------------------------------------------

    public function get_user_credit_total($user_id) {
        $credit_product_ids = $this->get_credit_product_ids();
        if (empty($credit_product_ids)) {
            return 0.0;
        }
        $total = 0.0;
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status'      => 'completed',
            'limit'       => -1,
        ]);
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if (in_array($item->get_product_id(), $credit_product_ids)) {
                    $total += (float) $item->get_total();
                }
            }
        }
        return $total;
    }

    public function get_user_active_coupons_total($user_id) {
        $posts = get_posts([
            'post_type'      => 'wcp_coupon',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        $total = 0.0;
        foreach ($posts as $post_id) {
            $used = get_post_meta($post_id, 'wcp_used', true);
            if ($used) {
                continue;
            }
            $code   = get_the_title($post_id);
            $status = $this->call_remote_api('/wp-json/wcp/v1/coupon-status?code=' . urlencode($code), 'GET', []);
            if (is_wp_error($status)) {
                $total += (float) get_post_meta($post_id, 'wcp_amount', true);
                continue;
            }
            if (!empty($status['used'])) {
                update_post_meta($post_id, 'wcp_used', true);
            } else {
                $total += (float) get_post_meta($post_id, 'wcp_amount', true);
            }
        }
        return $total;
    }

    public function get_user_remaining_credit($user_id) {
        $total  = $this->get_user_credit_total($user_id);
        $active = $this->get_user_active_coupons_total($user_id);
        return max(0.0, $total - $active);
    }

    // -------------------------------------------------------------------------
    // Endpoint content
    // -------------------------------------------------------------------------

    public function endpoint_content() {
        if (!$this->can_access()) {
            echo '<div class="wcpcm-form-container"><p>Non hai i permessi per accedere a questa sezione.</p></div>';
            return;
        }
        $user_id          = get_current_user_id();
        $credit_total     = $this->get_user_credit_total($user_id);
        $credit_remaining = $this->get_user_remaining_credit($user_id);
        $products_map     = get_option('wcp_products_map', []);
        ?>
        <div class="wcpcm-form-container">
            <div class="wcpcm-credit-bar">
                <span class="wcpcm-credit-label">Credito totale:</span>
                <span class="wcpcm-credit-total">&euro;<?php echo number_format($credit_total, 2, ',', '.'); ?></span>
                <span class="wcpcm-credit-sep">|</span>
                <span class="wcpcm-credit-label">Credito disponibile:</span>
                <span class="wcpcm-credit-remaining" id="wcp-credit-remaining">&euro;<?php echo number_format($credit_remaining, 2, ',', '.'); ?></span>
            </div>
            <h2 style="margin-bottom:1em;color:#274690;">Crea un nuovo codice sconto</h2>
            <form id="wcp-coupon-form" class="wcpcm-create-coupon-form">
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-product">Prodotto <span style="color:red">*</span></label>
                    <select class="wcpcm-input" name="product_id" id="wcp-product" required>
                        <option value="">-- Seleziona un prodotto --</option>
                        <?php foreach ($products_map as $entry) : ?>
                            <?php if (!empty($entry['name']) && !empty($entry['id'])) : ?>
                                <option value="<?php echo esc_attr($entry['id']); ?>"><?php echo esc_html($entry['name']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-amount">Importo Sconto (&euro;) <span style="color:red">*</span></label>
                    <input class="wcpcm-input" type="number" name="amount" id="wcp-amount" required min="0.01" step="0.01" placeholder="Es: 10.00">
                </div>
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-email">Email Utente Abilitato <span style="color:red">*</span></label>
                    <input class="wcpcm-input" type="email" name="email" id="wcp-email" required placeholder="Inserisci email abilitata">
                </div>
                <button class="wcpcm-btn" type="submit">Crea codice</button>
            </form>
            <div id="wcp-form-msg"></div>
        </div>
        <div class="wcpcm-table-container">
            <h3 style="color:#274690; margin:1.8em 0 0.8em 0;">Codici sconto creati</h3>
            <?php $this->list_coupons($user_id); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: create coupon
    // -------------------------------------------------------------------------

    public function handle_coupon_creation() {
        check_ajax_referer('wcp_nonce', 'nonce');
        if (!$this->can_access()) {
            wp_send_json_error(['msg' => 'Non hai i permessi.']);
        }
        $user_id    = get_current_user_id();
        $amount     = isset($_POST['amount']) ? round((float) $_POST['amount'], 2) : 0;
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $email      = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if ($amount <= 0 || !$product_id || !$email || !is_email($email)) {
            wp_send_json_error(['msg' => "Compila tutti i campi obbligatori e inserisci un'email valida."]);
        }

        $remaining = $this->get_user_remaining_credit($user_id);
        if ($amount > $remaining) {
            wp_send_json_error(['msg' => sprintf('Credito insufficiente. Credito disponibile: &euro;%s', number_format($remaining, 2, ',', '.'))]);
        }

        $product_name = $this->get_product_name_by_id($product_id);

        $response = $this->call_remote_api('/wp-json/wcp/v1/create-coupon', 'POST', [
            'amount'     => $amount,
            'email'      => $email,
            'product_id' => $product_id,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['msg' => 'Errore di connessione al sito remoto: ' . $response->get_error_message()]);
        }

        if (empty($response['success']) || empty($response['code'])) {
            wp_send_json_error(['msg' => 'Errore nella creazione del coupon remoto.']);
        }

        $code = sanitize_text_field($response['code']);

        $post_id = wp_insert_post([
            'post_title'  => $code,
            'post_status' => 'publish',
            'post_type'   => 'wcp_coupon',
            'post_author' => $user_id,
        ]);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['msg' => 'Coupon creato sul sito remoto ma errore nel salvataggio locale.']);
        }

        update_post_meta($post_id, 'wcp_amount', $amount);
        update_post_meta($post_id, 'wcp_product_id_b', $product_id);
        update_post_meta($post_id, 'wcp_product_name', $product_name);
        update_post_meta($post_id, 'wcp_email', $email);
        update_post_meta($post_id, 'wcp_used', false);

        $new_remaining = $this->get_user_remaining_credit($user_id);

        wp_send_json_success([
            'msg'              => 'Codice sconto creato con successo!',
            'code'             => $code,
            'remaining_credit' => number_format($new_remaining, 2, ',', '.'),
        ]);
    }

    // -------------------------------------------------------------------------
    // AJAX: delete coupon
    // -------------------------------------------------------------------------

    public function handle_coupon_deletion() {
        check_ajax_referer('wcp_delete_nonce', 'nonce');
        if (!$this->can_access()) {
            wp_send_json_error(['msg' => 'Non hai i permessi.']);
        }
        $user_id = get_current_user_id();
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error(['msg' => 'ID coupon non valido.']);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'wcp_coupon' || (int) $post->post_author !== $user_id) {
            wp_send_json_error(['msg' => 'Coupon non trovato o non autorizzato.']);
        }

        $code = $post->post_title;

        $status = $this->call_remote_api('/wp-json/wcp/v1/coupon-status?code=' . urlencode($code), 'GET', []);
        if (is_wp_error($status)) {
            wp_send_json_error(['msg' => 'Errore nel verificare lo stato del coupon sul sito remoto.']);
        }
        if (!empty($status['used'])) {
            wp_send_json_error(['msg' => 'Il coupon è già stato utilizzato e non può essere eliminato.']);
        }

        $delete_response = $this->call_remote_api('/wp-json/wcp/v1/delete-coupon?code=' . urlencode($code), 'DELETE', []);
        if (is_wp_error($delete_response)) {
            wp_send_json_error(['msg' => 'Errore nell\'eliminazione del coupon sul sito remoto.']);
        }
        if (empty($delete_response['success'])) {
            wp_send_json_error(['msg' => 'Il sito remoto non ha confermato l\'eliminazione del coupon.']);
        }

        wp_delete_post($post_id, true);

        $new_remaining = $this->get_user_remaining_credit($user_id);

        wp_send_json_success([
            'msg'              => 'Coupon eliminato. Il credito è stato ripristinato.',
            'remaining_credit' => number_format($new_remaining, 2, ',', '.'),
        ]);
    }

    // -------------------------------------------------------------------------
    // List coupons
    // -------------------------------------------------------------------------

    public function list_coupons($user_id) {
        $posts = get_posts([
            'post_type'      => 'wcp_coupon',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if (!$posts) {
            echo '<p>Nessun codice trovato.</p>';
            return;
        }

        echo '<table class="wcpcm-table"><thead><tr>
            <th>Codice</th>
            <th>Importo (&euro;)</th>
            <th>Prodotto</th>
            <th>Email abilitata</th>
            <th>Creato il</th>
            <th>Stato</th>
            <th>Azione</th>
        </tr></thead><tbody>';

        foreach ($posts as $post) {
            $amount       = (float) get_post_meta($post->ID, 'wcp_amount', true);
            $product_name = get_post_meta($post->ID, 'wcp_product_name', true);
            $email        = get_post_meta($post->ID, 'wcp_email', true);
            $used_cached  = get_post_meta($post->ID, 'wcp_used', true);

            $status = $this->call_remote_api('/wp-json/wcp/v1/coupon-status?code=' . urlencode($post->post_title), 'GET', []);
            if (!is_wp_error($status) && isset($status['used'])) {
                $is_used = (bool) $status['used'];
                update_post_meta($post->ID, 'wcp_used', $is_used);
            } else {
                $is_used = (bool) $used_cached;
            }

            $badge  = $is_used
                ? '<span class="wcpcm-badge wcpcm-badge-used">Usato</span>'
                : '<span class="wcpcm-badge wcpcm-badge-active">Attivo</span>';

            $delete_btn = '';
            if (!$is_used) {
                $delete_btn = sprintf(
                    '<button class="wcpcm-btn-delete" data-post-id="%d" data-code="%s">Elimina</button>',
                    esc_attr($post->ID),
                    esc_attr($post->post_title)
                );
            }

            echo '<tr>';
            echo '<td><code>' . esc_html($post->post_title) . '</code></td>';
            echo '<td>&euro;' . number_format($amount, 2, ',', '.') . '</td>';
            echo '<td>' . esc_html($product_name) . '</td>';
            echo '<td>' . esc_html($email) . '</td>';
            echo '<td>' . esc_html(date_i18n('d/m/Y', strtotime($post->post_date))) . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td>' . $delete_btn . '</td>';
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

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WCP-Secret' => $secret_key,
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
    // Settings page
    // -------------------------------------------------------------------------

    public function register_settings_page() {
        add_options_page(
            'WC Coupon Manager',
            'WC Coupon Manager',
            'manage_options',
            'wcp-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $remote_url    = get_option('wcp_remote_site_url', '');
        $secret_key    = get_option('wcp_secret_key', '');
        $credit_ids    = get_option('wcp_credit_product_ids', '206657');
        $products_map  = get_option('wcp_products_map', []);
        $allowed_roles = get_option('wcp_allowed_roles', ['administrator']);
        if (!is_array($allowed_roles)) {
            $allowed_roles = ['administrator'];
        }
        $all_roles = wp_roles()->get_names();

        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        ?>
        <div class="wrap">
            <h1>WC Coupon Manager &mdash; Impostazioni</h1>
            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p>Impostazioni salvate.</p></div>
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
                            <input type="text" id="wcp_secret_key" name="wcp_secret_key"
                                   value="<?php echo esc_attr($secret_key); ?>"
                                   class="regular-text" placeholder="chiave condivisa X-WCP-Secret">
                        </td>
                    </tr>
                </table>

                <h2>2. Prodotti credito (Sito A)</h2>
                <p>Inserisci gli ID prodotto del Sito A che danno credito (uno per riga o separati da virgola).</p>
                <table class="form-table">
                    <tr>
                        <th><label for="wcp_credit_product_ids">ID prodotti credito</label></th>
                        <td>
                            <textarea id="wcp_credit_product_ids" name="wcp_credit_product_ids"
                                      rows="4" class="large-text"><?php echo esc_textarea($credit_ids); ?></textarea>
                        </td>
                    </tr>
                </table>

                <h2>3. Prodotti disponibili (Sito B)</h2>
                <p>Mappa "Nome visualizzato" &rarr; "ID prodotto su Sito B". Ogni riga corrisponde a una voce del menu a tendina.</p>
                <div id="wcp-products-map">
                    <?php
                    if (!empty($products_map)) {
                        foreach ($products_map as $i => $entry) {
                            $name = isset($entry['name']) ? $entry['name'] : '';
                            $pid  = isset($entry['id']) ? $entry['id'] : '';
                            echo '<div class="wcp-map-row" style="display:flex;gap:8px;margin-bottom:6px;">';
                            echo '<input type="text" name="wcp_products_map[' . $i . '][name]" value="' . esc_attr($name) . '" placeholder="Nome prodotto" style="flex:2;">';
                            echo '<input type="number" name="wcp_products_map[' . $i . '][id]" value="' . esc_attr($pid) . '" placeholder="ID su Sito B" style="flex:1;">';
                            echo '<button type="button" class="button wcp-remove-row">Rimuovi</button>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="wcp-map-row" style="display:flex;gap:8px;margin-bottom:6px;">';
                        echo '<input type="text" name="wcp_products_map[0][name]" value="" placeholder="Nome prodotto" style="flex:2;">';
                        echo '<input type="number" name="wcp_products_map[0][id]" value="" placeholder="ID su Sito B" style="flex:1;">';
                        echo '<button type="button" class="button wcp-remove-row">Rimuovi</button>';
                        echo '</div>';
                    }
                    ?>
                </div>
                <button type="button" id="wcp-add-row" class="button">+ Aggiungi prodotto</button>
                <script>
                (function(){
                    var container = document.getElementById('wcp-products-map');
                    var idx = container.querySelectorAll('.wcp-map-row').length;
                    document.getElementById('wcp-add-row').addEventListener('click', function(){
                        var row = document.createElement('div');
                        row.className = 'wcp-map-row';
                        row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
                        row.innerHTML = '<input type="text" name="wcp_products_map['+idx+'][name]" value="" placeholder="Nome prodotto" style="flex:2;">'
                            + '<input type="number" name="wcp_products_map['+idx+'][id]" value="" placeholder="ID su Sito B" style="flex:1;">'
                            + '<button type="button" class="button wcp-remove-row">Rimuovi</button>';
                        container.appendChild(row);
                        idx++;
                    });
                    container.addEventListener('click', function(e){
                        if (e.target && e.target.classList.contains('wcp-remove-row')) {
                            e.target.closest('.wcp-map-row').remove();
                        }
                    });
                })();
                </script>

                <h2>4. Ruoli autorizzati</h2>
                <p>Seleziona quali ruoli WordPress possono accedere al pannello coupon.</p>
                <table class="form-table">
                    <tr>
                        <th>Ruoli</th>
                        <td>
                            <?php foreach ($all_roles as $role_slug => $role_name) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="wcp_allowed_roles[]"
                                           value="<?php echo esc_attr($role_slug); ?>"
                                           <?php checked(in_array($role_slug, $allowed_roles)); ?>>
                                    <?php echo esc_html($role_name); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Salva impostazioni'); ?>
            </form>
        </div>
        <?php
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato.');
        }
        check_admin_referer('wcp_settings_nonce', 'wcp_settings_nonce_field');

        $remote_url = isset($_POST['wcp_remote_site_url']) ? esc_url_raw(trim($_POST['wcp_remote_site_url'])) : '';
        update_option('wcp_remote_site_url', $remote_url);

        $secret_key = isset($_POST['wcp_secret_key']) ? sanitize_text_field($_POST['wcp_secret_key']) : '';
        update_option('wcp_secret_key', $secret_key);

        $credit_ids_raw = isset($_POST['wcp_credit_product_ids']) ? sanitize_textarea_field($_POST['wcp_credit_product_ids']) : '206657';
        update_option('wcp_credit_product_ids', $credit_ids_raw);

        $raw_map      = isset($_POST['wcp_products_map']) ? (array) $_POST['wcp_products_map'] : [];
        $products_map = [];
        foreach ($raw_map as $entry) {
            $name = isset($entry['name']) ? sanitize_text_field($entry['name']) : '';
            $id   = isset($entry['id']) ? intval($entry['id']) : 0;
            if ($name && $id) {
                $products_map[] = ['name' => $name, 'id' => $id];
            }
        }
        update_option('wcp_products_map', $products_map);

        $raw_roles     = isset($_POST['wcp_allowed_roles']) ? (array) $_POST['wcp_allowed_roles'] : [];
        $allowed_roles = array_map('sanitize_text_field', $raw_roles);
        if (empty($allowed_roles)) {
            $allowed_roles = ['administrator'];
        }
        update_option('wcp_allowed_roles', $allowed_roles);

        wp_redirect(admin_url('options-general.php?page=wcp-settings&saved=1'));
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function get_credit_product_ids() {
        $raw = get_option('wcp_credit_product_ids', '206657');
        $ids = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_map('intval', $ids);
    }

    private function get_product_name_by_id($product_id) {
        $products_map = get_option('wcp_products_map', []);
        foreach ($products_map as $entry) {
            if ((int) $entry['id'] === (int) $product_id) {
                return $entry['name'];
            }
        }
        return (string) $product_id;
    }
}

new WC_Personal_Coupon_Manager();
