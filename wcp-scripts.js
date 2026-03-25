jQuery(document).ready(function($){
    // Mostra/nasconde info placeholder per l'email
    $('#wcp-email').on('focus', function(){
        $(this).siblings('.wcpcm-note')
            .text("Inserisci un’email valida a cui abilitare il coupon")
            .fadeIn(180);
    }).on('blur', function(){
        $(this).siblings('.wcpcm-note').fadeOut(120);
    });

    // Validazione lato client e invio AJAX
    $('#wcp-coupon-form').on('submit', function(e){
        e.preventDefault();

        let valid   = true;
        let msg     = '';
        let amount  = $('#wcp-amount').val();
        let email   = $('#wcp-email').val();
        let $form   = $(this);

        // Reset colori input
        $form.find('.wcpcm-input').css('border-color','#d8e2ff');

        // Validazione percentuale
        if(!amount || isNaN(amount) || amount < 1 || amount > 100){
            valid = false;
            msg += '<div>Inserisci una percentuale di sconto valida (1-100)</div>';
            $('#wcp-amount').css('border-color','#c0392b');
        }

        // Validazione email
        let emailregex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if(!email || !emailregex.test(email)){
            valid = false;
            msg += "<div>Inserisci un'email valida</div>";
            $('#wcp-email').css('border-color','#c0392b');
        }

        if(!valid){
            $('#wcp-form-msg').html('<div class="wcpcm-error">'+msg+'</div>');
            setTimeout(()=>{$('#wcp-form-msg').fadeOut(320)}, 3000);
            return false;
        }

        // INVIO AJAX coupon
        let formData = $form.serialize();
        $.ajax({
            url: typeof wcp_ajax !== "undefined" ? wcp_ajax.ajax_url : '',
            type: 'POST',
            data: formData + '&action=wcp_create_coupon&nonce=' + (wcp_ajax ? wcp_ajax.nonce : ''),
            dataType: 'json',
            success: function(response){
                if(response.success){
                    $('#wcp-form-msg').html('<div class="wcpcm-success">' + response.data.msg + '<br><b>Codice: ' + response.data.code + '</b></div>');
                    $form[0].reset();
                    // Facoltativo: ricarica la tabella codici (con AJAX o reload), qui puoi farlo con location.reload();
                    setTimeout(()=>{ location.reload(); }, 1600);
                } else {
                    $('#wcp-form-msg').html('<div class="wcpcm-error">'+response.data.msg+'</div>');
                }
            },
            error: function(){
                $('#wcp-form-msg').html('<div class="wcpcm-error">Errore di rete, riprova.</div>');
            }
        });

        return false;
    });

    // OPTIONAL: select2 per prodotti/categorie (se usi ajax per popolamento)
    if(typeof $.fn.select2 !== "undefined"){
        $('#wcp-products, #wcp-categories').select2({
            width: '100%',
            placeholder: 'Seleziona...',
            allowClear: true,
            ajax: function(){
                // Qui puoi aggiungere configurazioni per ajax product/category search
            }
        });
    }
});
