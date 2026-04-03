<?php
/**
 * Snippet Sito B — WC Personal Coupon Manager
 *
 * Da incollare nel functions.php del tema del Sito B
 * (oppure in un plugin must-use).
 *
 * Configurazione:
 *   - La costante WCP_SECRET_KEY è già impostata con la chiave condivisa.
 *     Assicurati che corrisponda alla chiave nelle impostazioni del plugin sul Sito A.
 */

if (!defined('ABSPATH')) exit;

// ---------------------------------------------------------------------------
// Costante chiave segreta — deve corrispondere a quella impostata sul Sito A
// ---------------------------------------------------------------------------
if (!defined('WCP_SECRET_KEY')) {
    define('WCP_SECRET_KEY', 'wcp_a8f3k9x2z5m7q1p4r6t0y8n3j6v2w5b');
}

// ---------------------------------------------------------------------------
// Registrazione endpoint REST
// ---------------------------------------------------------------------------

add_action('rest_api_init', function () {
    register_rest_route('wcp/v1', '/create-coupon', [
        'methods'             => 'POST',
        'callback'            => 'wcp_b_create_coupon',
        'permission_callback' => 'wcp_b_verify_secret',
    ]);

    register_rest_route('wcp/v1', '/coupon-status', [
        'methods'             => 'GET',
        'callback'            => 'wcp_b_coupon_status',
        'permission_callback' => 'wcp_b_verify_secret',
    ]);

    register_rest_route('wcp/v1', '/delete-coupon', [
        'methods'             => 'DELETE',
        'callback'            => 'wcp_b_delete_coupon',
        'permission_callback' => 'wcp_b_verify_secret',
    ]);
});

// ---------------------------------------------------------------------------
// Autenticazione tramite header X-WCP-Secret
// ---------------------------------------------------------------------------

function wcp_b_verify_secret(WP_REST_Request $request) {
    $secret = defined('WCP_SECRET_KEY') ? WCP_SECRET_KEY : get_option('wcp_b_secret_key', '');
    if (empty($secret)) {
        return new WP_Error('no_secret', 'Chiave segreta non configurata sul Sito B.', ['status' => 500]);
    }
    $provided = $request->get_header('X-WCP-Secret');
    if (empty($provided) || !hash_equals($secret, $provided)) {
        return new WP_Error('forbidden', 'Chiave segreta non valida.', ['status' => 403]);
    }
    return true;
}

// ---------------------------------------------------------------------------
// POST /wp-json/wcp/v1/create-coupon
// Body JSON: { amount, email, product_id }
// Risposta: { success: true, code: "xxxxx" }
// ---------------------------------------------------------------------------

function wcp_b_create_coupon(WP_REST_Request $request) {
    $amount     = $request->get_param('amount');
    $email      = sanitize_email($request->get_param('email'));
    $product_id = intval($request->get_param('product_id'));

    if (!$amount || (float) $amount <= 0) {
        return new WP_Error('invalid_amount', 'Importo non valido.', ['status' => 400]);
    }
    if (!$email || !is_email($email)) {
        return new WP_Error('invalid_email', 'Email non valida.', ['status' => 400]);
    }
    if (!$product_id) {
        return new WP_Error('invalid_product', 'ID prodotto non valido.', ['status' => 400]);
    }

    $code      = strtolower(wp_generate_password(12, false));
    $coupon_id = wp_insert_post([
        'post_title'   => $code,
        'post_content' => '',
        'post_status'  => 'publish',
        'post_type'    => 'shop_coupon',
    ]);

    if (is_wp_error($coupon_id)) {
        return new WP_Error('coupon_creation_failed', 'Errore nella creazione del coupon.', ['status' => 500]);
    }

    update_post_meta($coupon_id, 'discount_type', 'fixed_product');
    update_post_meta($coupon_id, 'coupon_amount', round((float) $amount, 2));
    update_post_meta($coupon_id, 'individual_use', 'yes');
    update_post_meta($coupon_id, 'usage_limit', 1);
    update_post_meta($coupon_id, 'email_restrictions', [$email]);
    update_post_meta($coupon_id, 'customer_email', [$email]);
    update_post_meta($coupon_id, 'product_ids', (string) $product_id);

    return rest_ensure_response(['success' => true, 'code' => $code]);
}

// ---------------------------------------------------------------------------
// GET /wp-json/wcp/v1/coupon-status?code=XXXX
// Risposta: { used: true/false, usage_count: N }
// ---------------------------------------------------------------------------

function wcp_b_coupon_status(WP_REST_Request $request) {
    $code = sanitize_text_field($request->get_param('code'));
    if (!$code) {
        return new WP_Error('missing_code', 'Codice coupon mancante.', ['status' => 400]);
    }

    $posts = get_posts([
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'title'          => $code,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    if (empty($posts)) {
        return new WP_Error('not_found', 'Coupon non trovato.', ['status' => 404]);
    }
    $post_id = $posts[0];

    $usage_count = (int) get_post_meta($post_id, 'usage_count', true);
    $usage_limit = (int) get_post_meta($post_id, 'usage_limit', true);

    $used = ($usage_count > 0) || ($usage_limit > 0 && $usage_count >= $usage_limit);

    return rest_ensure_response([
        'used'        => $used,
        'usage_count' => $usage_count,
    ]);
}

// ---------------------------------------------------------------------------
// DELETE /wp-json/wcp/v1/delete-coupon?code=XXXX
// Risposta: { success: true }
// ---------------------------------------------------------------------------

function wcp_b_delete_coupon(WP_REST_Request $request) {
    $code = sanitize_text_field($request->get_param('code'));
    if (!$code) {
        return new WP_Error('missing_code', 'Codice coupon mancante.', ['status' => 400]);
    }

    $posts = get_posts([
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'title'          => $code,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    if (empty($posts)) {
        return new WP_Error('not_found', 'Coupon non trovato.', ['status' => 404]);
    }
    $post_id = $posts[0];

    $usage_count = (int) get_post_meta($post_id, 'usage_count', true);
    if ($usage_count > 0) {
        return new WP_Error('already_used', 'Il coupon è già stato utilizzato e non può essere eliminato.', ['status' => 409]);
    }

    $result = wp_delete_post($post_id, true);
    if (!$result) {
        return new WP_Error('delete_failed', "Errore nell'eliminazione del coupon.", ['status' => 500]);
    }

    return rest_ensure_response(['success' => true]);
}