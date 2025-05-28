jQuery(document).ready(function ($) {
    $('#wcbims_send_to_bims').on('click', function (e) {
        $('#wcbims_send_to_bims').prop('disabled', false);
        $('#wcbims_branch').prop('disabled', false);
        e.preventDefault();

        var orderId = $('input#post_ID').val();
        var branchId = $('#wcbims_branch').val();
        var nonce = wcbims_ajax.nonce;

        $.ajax({
            type: 'POST',
            url: wcbims_ajax.ajax_url,
            data: {
                action: 'wcbims_send_order',
                order_id: orderId,
                branch_id: branchId,
                nonce: nonce
            },
            success: function (response) {
                if (response.success) {
                    alert('Pedido enviado a BIMS con éxito. ID de BIMS: ' + response.data.bims_id);
                    $('#wcbims_send_to_bims').prop('disabled', true);
                    $('#wcbims_branch').prop('disabled', true);
                    $('#wcbims_meta_box').append('<p><strong>Este pedido ya ha sido enviado a BIMS.</strong></p>');
                } else {
                    $('#wcbims_send_to_bims').prop('disabled', false);
                    $('#wcbims_branch').prop('disabled', false);
                    alert('Error al enviar el pedido a BIMS.');
                }
            }
        });
    });
});
