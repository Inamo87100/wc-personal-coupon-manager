jQuery(function ($) {
    // update remaining credit helper (se presente nel tuo file originale, lascia invariato)
    function updateRemainingCredit(value) {
        // Se hai già questa funzione completa nel file, NON sostituirla con questo placeholder.
        var $el = $('#wcp-remaining-credit');
        if ($el.length) $el.text(value);
    }

    // Form submit: crea utente (rimane)
    $(document).on('submit', '#wcp-create-user-form', function (e) {
        e.preventDefault();

        var $form = $(this);
        var courseId   = $('#wcp-course').val();
        var email      = $('#wcp-email').val();
        var firstName  = $('#wcp-first-name').val();
        var lastName   = $('#wcp-last-name').val();

        var errors = [];
        var valid = true;

        if (!courseId) {
            valid = false;
            errors.push('Seleziona un corso.');
            $('#wcp-course').css('border-color', '#c0392b');
        } else {
            $('#wcp-course').css('border-color', '');
        }

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            valid = false;
            errors.push("Inserisci un'email valida.");
            $('#wcp-email').css('border-color', '#c0392b');
        } else {
            $('#wcp-email').css('border-color', '');
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
                $('#wcp-form-msg').html('<div class="wcpcm-error">Errore di rete, riprova.</div>').show();
            },
            complete: function () {
                $btn.prop('disabled', false).text('Registra corsista');
            }
        });

        return false;
    });

    // NOTE: blocco "Annulla registrazione (unenroll)" rimosso.
});
