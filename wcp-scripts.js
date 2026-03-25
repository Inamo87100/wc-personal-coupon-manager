jQuery(function($){
    // Submit form AJAX
    $('#wcp-coupon-form').on('submit', function(e){
        e.preventDefault();
        let form = $(this);
        let data = form.serialize();
        data += '&action=wcp_create_coupon&nonce='+wcp_ajax.nonce;
        $.post(wcp_ajax.ajax_url, data, function(res){
            $('#wcp-form-msg').html('<p style="color:'+(res.success?'green':'red')+'">'+res.data.msg+'</p>');
            if(res.success) setTimeout(()=>window.location.reload(), 1000);
        });
    });

    // Select2 per prodotti
    $('#wcp-products').select2({
        ajax: {
            url: wcp_ajax.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    term: params.term,
                    action: 'woocommerce_json_search_products_and_variations',
                    security: wcp_ajax.products_nonce,
                    limit: 20
                };
            },
            processResults: function(data) {
                let results = [];
                $.each(data, function(id, text) {
                    results.push({id: id, text: text});
                });
                return { results: results };
            },
            cache: true
        },
        minimumInputLength: 3,
        placeholder: 'Digita almeno 3 lettere del prodotto'
    });

    // Select2 per categorie
    $('#wcp-categories').select2({
        ajax: {
            url: wcp_ajax.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    term: params.term,
                    action: 'woocommerce_json_search_product_categories',
                    security: wcp_ajax.categories_nonce,
                    limit: 20
                };
            },
            processResults: function(data) {
                let results = [];
                $.each(data, function(id, text) {
                    results.push({id: id, text: text});
                });
                return { results: results };
            },
            cache: true
        },
        minimumInputLength: 3,
        placeholder: 'Digita almeno 3 lettere della categoria'
    });
});
