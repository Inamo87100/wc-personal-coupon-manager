jQuery(function($){

    // Attiva Select2 per prodotti
    $('#wcp-products').select2({
        width: '100%',
        placeholder: 'Cerca e seleziona i prodotti',
        minimumInputLength: 2,
        ajax: {
            url: wcp_ajax.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term,
                    nonce: wcp_ajax.products_nonce,
                    type: 'products'
                };
            },
            processResults: function(data) {
                return { results: data.results };
            }
        }
    });

    // Attiva Select2 per categorie
    $('#wcp-categories').select2({
        width: '100%',
        placeholder: 'Cerca e seleziona le categorie',
        minimumInputLength: 2,
        ajax: {
            url: wcp_ajax.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term,
                    nonce: wcp_ajax.categories_nonce,
                    type: 'categories'
                };
            },
            processResults: function(data) {
                return { results: data.results };
            }
        }
    });

    // Mostra/nasconde l'info sull'email
    $('#wcp-email').on('focus', function(){
        $(this).siblings('.wcpcm-note').text("Inserisci un’email valida per abilitare il coupon.").fadeIn(180);
    }).on('blur', function(){
        $(this).siblings('.wcpcm-note').fadeOut(120);
    });

    // Validazione e submit AJAX
    $('#wcp-coupon-form').on('submit', function(e){
        e.preventDefault();

        let valid = true;
        let msg   = '';
        let amount= $('#wcp-amount').val();
        let email = $('#wcp-email').val();
        let $form = $(this);

        $form.find('.wcpcm-input').css('border-color','#d8e2ff');
        if(!amount || isNaN(amount) || amount < 1 || amount > 100){
            valid = false;
            msg += '<div>Inserisci una percentuale di sconto valida (1-100)</div>';
            $('#wcp-amount').css('border-color','#c0392b');
        }
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

        let formData = $form.serialize();
        $.ajax({
            url: wcp_ajax.ajax_url,
            type: 'POST',
            data: formData + '&action=wcp_create_coupon&nonce=' + wcp_ajax.nonce,
            dataType: 'json',
            success: function(response){
                if(response.success){
                    $('#wcp-form-msg').html('<div class="wcpcm-success">' + response.data.msg + '<br><b>Codice: ' + response.data.code + '</b></div>');
                    $form[0].reset();
                    $('#wcp-products, #wcp-categories').val(null).trigger('change');
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
});
