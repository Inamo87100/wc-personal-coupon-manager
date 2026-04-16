jQuery(function ($) {

    // Aggiorna il credito residuo visualizzato
    function updateRemainingCredit(value) {
        $('#wcp-credit-remaining').text('\u20ac' + value);
    }

    // Submit form registrazione corsista
    $(document).on('submit', '#wcp-create-user-form', function (e) {
        e.preventDefault();

        var $form     = $(this);
        var courseId  = $('#wcp-course').val();
        var email     = $('#wcp-email').val();
        var firstName = $('#wcp-first-name').val();
        var lastName  = $('#wcp-last-name').val();

        // Reset stili
        $form.find('.wcpcm-input').css('border-color', '#d8e2ff');
        $('#wcp-form-msg').html('');

        var valid = true;
        var errors = [];

        if (!courseId) {
            valid = false;
            errors.push('Seleziona un corso.');
            $('#wcp-course').css('border-color', '#c0392b');
        }
        if (!firstName) {
            valid = false;
            errors.push('Inserisci il nome.');
            $('#wcp-first-name').css('border-color', '#c0392b');
        }
        if (!lastName) {
            valid = false;
            errors.push('Inserisci il cognome.');
            $('#wcp-last-name').css('border-color', '#c0392b');
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

        if (!window.wcp_ajax || !wcp_ajax.ajax_url || !wcp_ajax.nonce) {
            $('#wcp-form-msg').html('<div class="wcpcm-error">Errore di configurazione: ricarica la pagina e riprova.</div>').show();
            return false;
        }

        var $btn = $form.find('.wcpcm-btn');
        $btn.prop('disabled', true).text('Registrazione in corso...');

        $.ajax({
            url:      wcp_ajax.ajax_url,
            type:     'POST',
            dataType: 'json',
            data: {
                action:     'wcp_create_user',
                nonce:      wcp_ajax.nonce,
                course_id:  courseId,
                email:      email,
                first_name: firstName,
                last_name:  lastName
            },
            success: function (response) {
                if (response.success) {
                    $('#wcp-form-msg').html(
                        '<div class="wcpcm-success">' +
                        response.data.msg +
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
                $btn.prop('disabled', false).text('Registra corsista');
            }
        });

        return false;
    });

    // Annulla registrazione (unenroll)
    $(document).on('click', '.wcp-unenroll-btn', function () {
        if (!confirm('Annullare questa registrazione?')) return;
        var $btn   = $(this);
        var postId = $btn.data('id');
        var nonce  = $btn.data('nonce') || (window.wcp_ajax && wcp_ajax.nonce) || '';
        $btn.prop('disabled', true).text('Annullamento...');
        $.ajax({
            url:      (window.wcp_ajax && wcp_ajax.ajax_url) || ajaxurl,
            type:     'POST',
            dataType: 'json',
            data: { action: 'wcp_unenroll_user', nonce: nonce, activation_id: postId },
            success: function (response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(400, function () { $(this).remove(); });
                    if (response.data && response.data.remaining_credit !== undefined) {
                        updateRemainingCredit(response.data.remaining_credit);
                    }
                } else {
                    alert('Errore: ' + (response.data ? response.data.msg : 'Errore sconosciuto'));
                    $btn.prop('disabled', false).text('Annulla');
                }
            },
            error: function () {
                alert('Errore di rete.');
                $btn.prop('disabled', false).text('Annulla');
            }
        });
    });
});
