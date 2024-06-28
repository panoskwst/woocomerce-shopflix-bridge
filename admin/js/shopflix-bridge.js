jQuery(document).ready(function($) {
    $('#shopflix_fetch_order').click(function() {
        var orderId = $('#shopflix_fetch_order_id').val();
        $.ajax({
            url: shopflixAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'shopflix_fetch_order',
                order_id: orderId,
                nonce: shopflixAjax.nonce
            },
            success: function(response) {
                 alert(`${response.data.message}Order ID: ${response.data.order_id}`); // You can handle the response as per your requirement
            },
            error: function(errorThrown) {
                console.log(errorThrown);
            }
        });
    });
});