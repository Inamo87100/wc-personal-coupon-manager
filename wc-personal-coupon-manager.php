<?php
/*
Plugin Name: WooCommerce Personal Coupon Manager
Description: Gestione coupon dall'area "Il mio account" solo per admin e utente 5584, con interfaccia moderna.
Version: 2.0
Author: Inamo87100
*/

if (!defined('ABSPATH')) exit;

class WC_Personal_Coupon_Manager {

    public function __construct() {
        add_action('init', [$this, 'add_my_account_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_codici-sconto_endpoint', [$this, 'endpoint_content']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wcp_create_coupon', [$this, 'handle_coupon_creation']);
        add_action('wp_ajax_nopriv_wcp_create_coupon', [$this, 'handle_coupon_creation']);
    }

    // 1. EndPoint
    public function add_my_account_endpoint() {
        add_rewrite_endpoint('codici-sconto', EP_PAGES);
    }

    // 2. Voce nel menu My Account
    public function add_menu_item($items) {
        if ($this->can_access()) {
            $items = array_slice($items, 0, 1, true) +
                     ['codici-sconto' => 'Codici sconto'] +
                     array_slice($items, 1, null, true);
        }
        return $items;
    }

    // 3. Controllo permessi
    private function can_access() {
        $user = wp_get_current_user();
        return in_array('administrator', $user->roles) || $user->ID == 5584;
    }

    // 4. JS/CSS Select2 + Custom
    public function enqueue_assets() {
        if (function_exists('is_account_page') && is_account_page()) {
            global $wp;
            if (isset($wp->query_vars['codici-sconto'])) {

                // Select2 (CDN)
                wp_enqueue_style(
                    'select2',
                    'https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css',
                    [],
                    '4.1.0'
                );
                wp_enqueue_script(
                    'select2',
                    'https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js',
                    ['jquery'],
                    '4.1.0',
                    true
                );

                // Custom CSS+JS
                wp_enqueue_style('wcp-style', plugin_dir_url(__FILE__).'style.css', [], '2.0');
                wp_enqueue_script('wcp-ajax', plugin_dir_url(__FILE__).'wcp-scripts.js', ['jquery', 'select2'], '2.0', true);

                // Nonce JSON search WC
                $search_products_nonce   = wp_create_nonce('search-products');
                $search_categories_nonce = wp_create_nonce('search-categories');

                wp_localize_script('wcp-ajax', 'wcp_ajax', [
                    'ajax_url'         => admin_url('admin-ajax.php'),
                    'nonce'            => wp_create_nonce('wcp_nonce'),
                    'products_nonce'   => $search_products_nonce,
                    'categories_nonce' => $search_categories_nonce,
                ]);
            }
        }
    }

    // 5. Form + lista coupon (tab)
    public function endpoint_content() {
        if (!$this->can_access()) {
            echo '<div class="wcpcm-form-container"><p>Non hai i permessi per accedere a questa sezione.</p></div>';
            return;
        }
        ?>
        <div class="wcpcm-form-container">
            <h2 style="margin-bottom:1em;color:#274690;">Crea un nuovo codice sconto</h2>
            <form id="wcp-coupon-form" class="wcpcm-create-coupon-form">
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-amount">Percentuale Sconto (%) <span style="color:red">*</span></label>
                    <input class="wcpcm-input" type="number" name="amount" id="wcp-amount" required min="1" max="100" placeholder="Es: 10">
                </div>
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-products">Prodotti (opzionale)</label>
                    <select name="products[]" id="wcp-products" multiple="multiple" class="wcpcm-input"></select>
                </div>
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-categories">Categorie (opzionale)</label>
                    <select name="categories[]" id="wcp-categories" multiple="multiple" class="wcpcm-input"></select>
                </div>
                <div class="wcpcm-form-group">
                    <label class="wcpcm-label" for="wcp-email">Email Utente Abilitato <span style="color:red">*</span></label>
                    <input class="wcpcm-input" type="email" name="email" id="wcp-email" required placeholder="Inserisci email abilitata">
                    <div class="wcpcm-note" style="display:none"></div>
                </div>
                <button class="wcpcm-btn" type="submit">Crea codice</button>
            </form>
            <div id="wcp-form-msg"></div>
        </div>
        <div class="wcpcm-table-container">
            <h3 style="color:#274690; margin:1.8em 0 0.8em 0;">Codici sconto creati</h3>
            <?php $this->list_coupons(); ?>
        </div>
        <?php
    }

    // 6. Creazione coupon via AJAX
    public function handle_coupon_creation() {
        check_ajax_referer('wcp_nonce', 'nonce');

        if (!$this->can_access()) {
            wp_send_json_error(['msg' => 'Non hai i permessi.']);
        }

        $amount     = isset($_POST['amount']) ? intval($_POST['amount']) : 0;
        $products   = isset($_POST['products']) ? array_map('intval', (array)$_POST['products']) : [];
        $categories = isset($_POST['categories']) ? array_map('intval', (array)$_POST['categories']) : [];
        $email      = isset($_POST['email']) ? trim(sanitize_email($_POST['email'])) : '';

        // Validazione
        if (!$amount || !$email || !is_email($email)) {
            wp_send_json_error(['msg' => "Compila tutti i campi obbligatori e inserisci un'email valida."]);
        }

        // Mail come array
        $email_arr = [$email];

        // Crea codice coupon unico
        $code = strtolower(wp_generate_password(10, false));

        $coupon = [
            'post_title'    => $code,
            'post_content'  => '',
            'post_status'   => 'publish',
            'post_author'   => get_current_user_id(),
            'post_type'     => 'shop_coupon',
        ];
        $new_coupon_id = wp_insert_post($coupon);

        // Meta obbligatori
        update_post_meta($new_coupon_id, 'discount_type', 'percent');
        update_post_meta($new_coupon_id, 'coupon_amount', $amount);
        update_post_meta($new_coupon_id, 'individual_use', 'yes');
        update_post_meta($new_coupon_id, 'usage_limit', 1);
        update_post_meta($new_coupon_id, 'email_restrictions', $email_arr); // legacy, opzionale
        update_post_meta($new_coupon_id, 'customer_email', $email_arr);     // fondamentale per backend WooCommerce

        if (!empty($products)) {
            update_post_meta($new_coupon_id, 'product_ids', implode(',', $products));
        } else {
            update_post_meta($new_coupon_id, 'product_ids', '');
        }
        if (!empty($categories)) {
            update_post_meta($new_coupon_id, 'product_categories', implode(',', $categories));
        } else {
            update_post_meta($new_coupon_id, 'product_categories', '');
        }

        wp_send_json_success(['msg' => 'Coupon creato con successo!', 'code' => $code]);
    }

    // 7. Tabella dei coupon creati
    public function list_coupons() {
        $args = [
            'posts_per_page' => 30,
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'author'         => get_current_user_id(),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $coupons = get_posts($args);
        if (!$coupons) {
            echo "<p>Nessun codice trovato.</p>";
            return;
        }

        echo '<table class="wcpcm-table"><thead><tr>
        <th>Codice</th>
        <th>Sconto (%)</th>
        <th>Email abilitata</th>
        <th>Prodotti</th>
        <th>Categorie</th>
        <th>Creato il</th></tr></thead><tbody>';

        foreach ($coupons as $coupon) {
            $amount = get_post_meta($coupon->ID, 'coupon_amount', true);

            // Recupera l'email da customer_email (array), se mancante fallback su email_restrictions
            $email_list = get_post_meta($coupon->ID, 'customer_email', true);
            if (empty($email_list)) {
                $email_list = get_post_meta($coupon->ID, 'email_restrictions', true);
            }
            $email = is_array($email_list) ? reset($email_list) : $email_list;

            $prods_string = get_post_meta($coupon->ID, 'product_ids', true);
            $cats_string  = get_post_meta($coupon->ID, 'product_categories', true);

            // Nomi prodotti
            $prod_names = '';
            if ($prods_string) {
                $prods = explode(',', $prods_string);
                $prod_titles = array_map(function($id){
                    return get_the_title($id);
                }, array_filter($prods));
                $prod_names = implode(', ', $prod_titles);
            }

            // Nomi categorie
            $cat_names = '';
            if ($cats_string) {
                $cats = explode(',', $cats_string);
                $cat_titles = array_map(function($id){
                    $term = get_term($id, 'product_cat');
                    return $term ? $term->name : '';
                }, array_filter($cats));
                $cat_names = implode(', ', $cat_titles);
            }

            echo "<tr>
            <td>{$coupon->post_title}</td>
            <td>{$amount}%</td>
            <td>{$email}</td>
            <td>{$prod_names}</td>
            <td>{$cat_names}</td>
            <td>".date('d/m/Y', strtotime($coupon->post_date))."</td>
            </tr>";
        }
        echo '</tbody></table>';
    }
}

new WC_Personal_Coupon_Manager();
