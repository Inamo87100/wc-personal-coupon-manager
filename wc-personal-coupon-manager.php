<?php
/*
Plugin Name: WooCommerce Personal Coupon Manager
Description: Gestione coupon dall'area "Il mio account" solo per admin e utente 5584.
Version: 1.2
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
                wp_enqueue_style('wcp-style', plugin_dir_url(__FILE__).'style.css', [], '1.2');
                wp_enqueue_script('wcp-ajax', plugin_dir_url(__FILE__).'wcp-scripts.js', ['jquery', 'select2'], '1.2', true);

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
            echo '<p>Non hai i permessi per accedere a questa sezione.</p>';
            return;
        }
        ?>
        <h2>Crea un nuovo codice sconto</h2>
        <form id="wcp-coupon-form">
            <label>Percentuale Sconto (%) <span style="color:red">*</span>
                <input type="number" name="amount" required min="1" max="100">
            </label>
            <label>Prodotti (opzionale)
                <select name="products[]" id="wcp-products" multiple="multiple"></select>
            </label>
            <label>Categorie (opzionale)
                <select name="categories[]" id="wcp-categories" multiple="multiple"></select>
            </label>
            <label>Email Utente Abilitato <span style="color:red">*</span>
                <input type="email" name="email" required>
            </label>
            <button type="submit">Crea codice</button>
        </form>
        <div id="wcp-form-msg"></div>
        <hr>
        <h2>Codici sconto creati</h2>
        <?php
        $this->list_coupons();
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

        // DB: meta email_restrictions DEVE essere sempre array serializzato
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

        // Meta obbligatori per la tua specifica
        update_post_meta($new_coupon_id, 'discount_type', 'percent');
        update_post_meta($new_coupon_id, 'coupon_amount', $amount);
        update_post_meta($new_coupon_id, 'individual_use', 'yes');
        update_post_meta($new_coupon_id, 'usage_limit', 1);
        update_post_meta($new_coupon_id, 'email_restrictions', $email_arr); // Sempre array

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

        echo '<table><tr>
        <th>Codice</th>
        <th>Sconto (%)</th>
        <th>Email abilitata</th>
        <th>Prodotti</th>
        <th>Categorie</th>
        <th>Creato il</th></tr>';

        foreach ($coupons as $coupon) {
            $amount = get_post_meta($coupon->ID, 'coupon_amount', true);

            // Sempre array, preleva il primo elemento
            $email_list = get_post_meta($coupon->ID, 'email_restrictions', true);
            $email = is_array($email_list) ? reset($email_list) : $email_list;

            $prods_string = get_post_meta($coupon->ID, 'product_ids', true);
            $cats_string  = get_post_meta($coupon->ID, 'product_categories', true);

            // Recupero nomi amichevoli
            $prod_names = '';
            if ($prods_string) {
                $prods = explode(',', $prods_string);
                $prod_titles = array_map(function($id){
                    return get_the_title($id);
                }, array_filter($prods));
                $prod_names = implode(', ', $prod_titles);
            }

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
        echo '</table>';
    }
}

new WC_Personal_Coupon_Manager();
