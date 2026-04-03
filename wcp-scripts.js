jQuery(function ($) {

    // Aggiorna il credito residuo visualizzato
    function updateRemainingCredit(value) {
        $('#wcp-credit-remaining').text('\u20ac' + value);
    }

    // Submit form creazione coupon
    $('#wcp-coupon-form').on('submit', function (e) {
        e.preventDefault();

        var $form      = $(this);
        var amount     = $('#wcp-amount').val();
        var productId  = $('#wcp-product').val();
        var email      = $('#wcp-email').val();

        // Reset stili
        $form.find('.wcpcm-input').css('border-color', '#d8e2ff');
        $('#wcp-form-msg').html('');

        var valid = true;
        var errors = [];

        if (!productId) {
            valid = false;
            errors.push('Seleziona un prodotto.');
            $('#wcp-product').css('border-color', '#c0392b');
        }
        if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
            valid = false;
            errors.push("Inserisci un importo sconto valido (maggiore di 0).");
            $('#wcp-amount').css('border-color', '#c0392b');
        }
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email || !emailRegex.test(email)) {
            valid = false;
            errors.push("Inserisci un'email valida.");
            $('#wcp-email').css('border-color', '#c0392b');
        }

        if (!valid) {
            var errHtml = errors.map(function (m) { return '<div>' + m + '</div>'; }).join('');
            $('#wcp-form-msg').html('<div class="wcpcm-error">' + errHtml + '</div>').show();
            return false;
        }

        var $btn = $form.find('.wcpcm-btn');
        $btn.prop('disabled', true).text('Creazione in corso...');

        $.ajax({
            url:      wcp_ajax.ajax_url,
            type:     'POST',
            dataType: 'json',
            data: {
                action:     'wcp_create_coupon',
                nonce:      wcp_ajax.nonce,
                amount:     amount,
                product_id: productId,
                email:      email
            },
            success: function (response) {
                if (response.success) {
                    $('#wcp-form-msg').html(
                        '<div class="wcpcm-success">' +
                        response.data.msg +
                        '<br><b>Codice: ' + response.data.code + '</b>' +
                        '</div>'
                    );
                    updateRemainingCredit(response.data.remaining_credit);
                    $form[0].reset();
                    setTimeout(function () { location.reload(); }, 1800);
                } else {
                    $('#wcp-form-msg').html('<div class="wcpcm-error">' + response.data.msg + '</div>');
                }
            },
            error: function () {
                $('#wcp-form-msg').html('<div class="wcpcm-error">Errore di rete, riprova.</div>');
            },
            complete: function () {
                $btn.prop('disabled', false).text('Crea codice');
            }
        });

        return false;
    });

    // Click "Elimina" coupon
    $(document).on('click', '.wcpcm-btn-delete', function () {
        var $btn   = $(this);
        var postId = $btn.data('post-id');
        var code   = $btn.data('code');

        if (!confirm('Sei sicuro di voler eliminare il codice "' + code + '"?\nL\'importo verrà restituito al tuo credito.')) {
            return;
        }

        $btn.prop('disabled', true).text('Eliminazione...');

        $.ajax({
            url:      wcp_ajax.ajax_url,
            type:     'POST',
            dataType: 'json',
            data: {
                action:  'wcp_delete_coupon',
                nonce:   wcp_ajax.delete_nonce,
                post_id: postId
            },
            success: function (response) {
                if (response.success) {
                    updateRemainingCredit(response.data.remaining_credit);
                    $btn.closest('tr').fadeOut(400, function () { $(this).remove(); });
                } else {
                    alert(response.data.msg);
                    $btn.prop('disabled', false).text('Elimina');
                }
            },
            error: function () {
                alert('Errore di rete, riprova.');
                $btn.prop('disabled', false).text('Elimina');
            }
        });
    });
});
