# WooCommerce Personal Coupon Manager

Plugin WordPress per la gestione coupon personalizzati dall'area "Il mio account", con sistema credito e creazione remota dei coupon su un secondo sito WooCommerce (Sito B).

---

## Architettura

- **Sito A** (questo plugin): gestisce il credito dell'utente e l'interfaccia. Crea un record CPT locale (`wcp_coupon`) e chiama le API REST di Sito B.
- **Sito B**: espone gli endpoint REST che creano/verificano/eliminano fisicamente i coupon WooCommerce.

---

## Configurazione (Sito A)

1. Installa e attiva il plugin.
2. Vai su **Impostazioni → WC Coupon Manager** e configura:
   - **URL Sito B**: URL HTTPS del sito remoto (es. `https://negozio.it`)
   - **Chiave segreta**: stringa condivisa usata nell'header `X-WCP-Secret`
   - **ID prodotti credito**: ID dei prodotti WooCommerce su Sito A il cui acquisto genera credito
   - **Prodotti disponibili (Sito B)**: mappa nome → ID prodotto su Sito B da mostrare nel form
   - **Ruoli autorizzati**: ruoli WordPress che possono accedere alla sezione coupon

---

## Flusso di creazione coupon (v3.1+)

1. L'utente seleziona un prodotto e inserisce la propria email.
2. Sito A chiama `GET /wp-json/wcp/v1/product-price?product_id=<ID>` su Sito B per ottenere il prezzo corrente (IVA inclusa).
3. Sito A verifica che il credito residuo dell'utente sia ≥ del prezzo del prodotto.
4. Se sufficiente, Sito A chiama `POST /wp-json/wcp/v1/create-coupon` su Sito B, che crea un coupon **percentuale al 100%** vincolato al prodotto e all'email.
5. Sito A salva il record locale con `wcp_amount` = prezzo del prodotto (usato per il calcolo del credito impegnato).

---

## Snippet Sito B — Istruzioni di aggiornamento

Lo snippet PHP installato sul Sito B (solitamente in `functions.php` o come MU-plugin) deve esporre i seguenti endpoint REST autenticati tramite l'header `X-WCP-Secret`.

### Endpoint richiesti

| Metodo | Percorso | Descrizione |
|--------|----------|-------------|
| `GET`  | `/wp-json/wcp/v1/product-price` | Restituisce il prezzo corrente del prodotto (IVA inclusa) |
| `POST` | `/wp-json/wcp/v1/create-coupon` | Crea coupon percentuale 100% per prodotto + email |
| `GET`  | `/wp-json/wcp/v1/coupon-status` | Verifica se un coupon è stato usato |
| `DELETE` | `/wp-json/wcp/v1/delete-coupon` | Elimina un coupon esistente |

---

### Patch da applicare allo snippet su Sito B

Copia il codice seguente e sostituisci (o integra) lo snippet già presente sul Sito B.

```php
<?php
/**
 * Snippet WCP – Sito B (v3.1)
 * Da installare sul sito B in functions.php o come MU-plugin.
 *
 * Aggiornamenti rispetto alla versione precedente:
 *  - Nuovo endpoint GET /wp-json/wcp/v1/product-price
 *  - create-coupon crea sempre coupon percentuale 100% (campo 'amount' ignorato)
 */

add_action('rest_api_init', function () {

    $secret = defined('WCP_SECRET_KEY') ? WCP_SECRET_KEY : get_option('wcp_secret_key', '');

    // Callback di autenticazione comune
    $auth = function (WP_REST_Request $request) use ($secret) {
        if (empty($secret) || $request->get_header('X-WCP-Secret') !== $secret) {
            return new WP_Error('unauthorized', 'Accesso non autorizzato.', ['status' => 401]);
        }
        return true;
    };

    // ------------------------------------------------------------------
    // GET /wp-json/wcp/v1/product-price?product_id=<ID>
    // Restituisce il prezzo corrente (IVA inclusa) del prodotto.
    // ------------------------------------------------------------------
    register_rest_route('wcp/v1', '/product-price', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $request) {
            $product_id = intval($request->get_param('product_id'));
            if (!$product_id) {
                return new WP_Error('invalid_product', 'product_id non valido.', ['status' => 400]);
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_purchasable()) {
                return new WP_Error('product_not_found', 'Prodotto non trovato o non acquistabile.', ['status' => 404]);
            }

            // Prezzo corrente (include eventuale prezzo scontato/in saldo).
            // wc_get_price_including_tax() restituisce il prezzo IVA inclusa.
            $price = (float) wc_get_price_including_tax($product);

            return rest_ensure_response([
                'success' => true,
                'price'   => $price,
            ]);
        },
        'permission_callback' => $auth,
    ]);

    // ------------------------------------------------------------------
    // POST /wp-json/wcp/v1/create-coupon
    // Body JSON: { "email": "...", "product_id": 123 }
    // Crea un coupon WooCommerce percentuale 100% per quel prodotto + email.
    // ------------------------------------------------------------------
    register_rest_route('wcp/v1', '/create-coupon', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            $params     = $request->get_json_params();
            $email      = isset($params['email']) ? sanitize_email($params['email']) : '';
            $product_id = isset($params['product_id']) ? intval($params['product_id']) : 0;

            if (!is_email($email) || !$product_id) {
                return new WP_Error('invalid_params', 'Email o product_id non validi.', ['status' => 400]);
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return new WP_Error('product_not_found', 'Prodotto non trovato.', ['status' => 404]);
            }

            // Genera codice univoco
            $code = 'WCP-' . strtoupper(wp_generate_password(8, false));

            $coupon = new WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_discount_type('percent');   // Percentuale
            $coupon->set_amount(100);                 // 100%
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_email_restrictions([$email]);
            $coupon->set_product_ids([$product_id]);
            $coupon->set_individual_use(true);
            $coupon->save();

            return rest_ensure_response([
                'success' => true,
                'code'    => $code,
            ]);
        },
        'permission_callback' => $auth,
    ]);

    // ------------------------------------------------------------------
    // GET /wp-json/wcp/v1/coupon-status?code=<CODE>
    // Restituisce se il coupon è stato usato.
    // ------------------------------------------------------------------
    register_rest_route('wcp/v1', '/coupon-status', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $request) {
            $code   = sanitize_text_field($request->get_param('code'));
            $coupon = new WC_Coupon($code);

            if (!$coupon->get_id()) {
                return new WP_Error('coupon_not_found', 'Coupon non trovato.', ['status' => 404]);
            }

            $used_count = $coupon->get_usage_count();
            $usage_limit = $coupon->get_usage_limit();

            return rest_ensure_response([
                'success' => true,
                'code'    => $code,
                'used'    => ($usage_limit > 0 && $used_count >= $usage_limit),
            ]);
        },
        'permission_callback' => $auth,
    ]);

    // ------------------------------------------------------------------
    // DELETE /wp-json/wcp/v1/delete-coupon?code=<CODE>
    // Elimina il coupon.
    // ------------------------------------------------------------------
    register_rest_route('wcp/v1', '/delete-coupon', [
        'methods'             => 'DELETE',
        'callback'            => function (WP_REST_Request $request) {
            $code   = sanitize_text_field($request->get_param('code'));
            $coupon = new WC_Coupon($code);

            if (!$coupon->get_id()) {
                return new WP_Error('coupon_not_found', 'Coupon non trovato.', ['status' => 404]);
            }

            $used_count  = $coupon->get_usage_count();
            $usage_limit = $coupon->get_usage_limit();
            if ($usage_limit > 0 && $used_count >= $usage_limit) {
                return new WP_Error('coupon_used', 'Il coupon è già stato utilizzato.', ['status' => 409]);
            }

            wp_delete_post($coupon->get_id(), true);

            return rest_ensure_response([
                'success' => true,
            ]);
        },
        'permission_callback' => $auth,
    ]);
});
```

### Come definire la chiave segreta su Sito B

Aggiungi in `wp-config.php` (opzionale, altrimenti usa l'opzione DB `wcp_secret_key`):

```php
define('WCP_SECRET_KEY', 'la-tua-chiave-segreta-condivisa');
```

La stessa chiave deve essere configurata nelle **Impostazioni → WC Coupon Manager** su Sito A.

---

## Changelog

### v3.1
- Rimosso il campo "Importo sconto" dal form: il coupon è sempre percentuale 100%.
- Aggiunta chiamata a `GET /wp-json/wcp/v1/product-price` per ottenere il prezzo corrente del prodotto da Sito B (IVA inclusa) prima di creare il coupon.
- Il credito dell'utente viene ora scalato del **prezzo corrente del prodotto** su Sito B.
- Aggiornato snippet Sito B: nuovo endpoint `product-price`, `create-coupon` crea sempre coupon `percent` 100%.

### v3.0
- Prima release con architettura a due siti.
- Form con campo importo manuale.
- Credito scalato in base all'importo inserito dall'utente.
