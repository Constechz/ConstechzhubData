<?php
// DEPRECATED: Direct Paystack payments for data bundles are no longer supported
// All data bundle purchases must use wallet payments only
header('Content-Type: application/json');
http_response_code(403);
echo json_encode([
    'success' => false, 
    'message' => 'Direct Paystack payments for data bundles are no longer supported. Please top up your wallet first, then purchase data bundles using your wallet balance.',
    'error_code' => 'PAYSTACK_BUNDLES_DISABLED'
]);
exit();
