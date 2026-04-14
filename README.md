# WooCommerce Personal Coupon Manager

Plugin WordPress per la gestione attivazioni utente da **Il mio account** con sistema credito e integrazione REST verso Sito B.

---

## Architettura

- **Sito A** (questo plugin): gestisce il credito dell'utente, il form e lo storico locale delle attivazioni (`wcp_user_activation`).
- **Sito B**: espone l'endpoint REST `POST /wp-json/nf/v1/create-user` per creare/aggiornare utenti e associare corsi.

---

## Configurazione (Sito A)

1. Installa e attiva il plugin.
2. Vai su **Impostazioni → WC User Activation Manager** e configura:
   - **URL Sito B**: URL HTTPS del sito remoto (es. `https://formazione.it`)
   - **Chiave segreta**: usata nell'header `X-NF-SECRET`
   - **ID prodotti credito**: ID prodotti WooCommerce su Sito A che generano credito
   - **Corsi disponibili (Sito B)**: mappa nome → ID corso su Sito B da mostrare nel form
   - **Ruoli autorizzati**: ruoli WordPress che possono accedere alla sezione

---

## Flusso di attivazione utente

1. L'utente apre endpoint My Account `crea-utente`.
2. Seleziona corso, inserisce nome, cognome ed email.
3. Il plugin verifica il credito residuo.
4. Se sufficiente, chiama:

```http
POST {wcp_remote_site_url}/wp-json/nf/v1/create-user
X-NF-SECRET: {wcp_secret_key}
Content-Type: application/json
```

Body JSON:

```json
{
  "action": "create_user",
  "user_email": "utente@example.com",
  "first_name": "Mario",
  "last_name": "Rossi",
  "course_ids": [123]
}
```

5. In caso di successo salva uno storico locale (`wcp_user_activation`) e scala credito per l'attivazione.

---

## Changelog

### v3.3
- Endpoint WooCommerce My Account aggiornato da `codici-sconto` a `crea-utente`.
- Nuova integrazione REST verso `POST /wp-json/nf/v1/create-user`.
- Header autenticazione aggiornato a `X-NF-SECRET` (option `wcp_secret_key`).
- Rimosso flusso coupon remoto e introdotto storico locale dedicato alle attivazioni utente (`wcp_user_activation`).
- Credito scalato per ogni attivazione utente.

### v3.2
- Aggiunto pulsante **"🔑 Genera chiave segreta automaticamente"** nella pagina impostazioni.
- La chiave viene generata con `wp_generate_password(48)` e codificata in base64.

### v3.1
- Miglioramenti al flusso precedente basato su coupon.

### v3.0
- Prima release con architettura a due siti.
