jQuery(function ($) {

    // Aggiorna il credito residuo visualizzato
    function updateRemainingCredit(value) {
        $('#wcp-credit-remaining').text('\u20ac' + value);
    }

    // Submit form creazione utente
    $('#wcp-create-user-form').on('submit', function (e) {
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

        var $btn = $form.find('.wcpcm-btn');
        $btn.prop('disabled', true).text('Creazione in corso...');

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
                $btn.prop('disabled', false).text('Crea utente');
            }
        });

        return false;
    });
});
