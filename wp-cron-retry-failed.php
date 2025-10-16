<?php

function bimsc_schedule_retry_failed() {
    if (!wp_next_scheduled('bimsc_retry_failed_orders')) {
        wp_schedule_event(time(), 'hourly', 'bimsc_retry_failed_orders');
    }
}
add_action('wp', 'bimsc_schedule_retry_failed');

function bimsc_retry_failed_orders_callback() {
    global $wpdb;
    
    // Buscar órdenes con meta bimsc_failed
    $failed_orders = $wpdb->get_results("
        SELECT post_id 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = 'bimsc_failed' 
        AND meta_value = '1'
        LIMIT 10
    ");
    
    foreach($failed_orders as $failed) {
        $order = wc_get_order($failed->post_id);
        
        // Intentar enviar nuevamente
        $bims_class = new BimsC();
        $result = $bims_class->so_payment_complete($failed->post_id);
        
        if($result) {
            // Éxito: remover flag de fallo
            delete_post_meta($failed->post_id, 'bimsc_failed');
            $order->add_order_note("Reintento exitoso: Orden enviada a BIMS ID: {$result}");
        }
    }
}
add_action('bimsc_retry_failed_orders', 'bimsc_retry_failed_orders_callback');