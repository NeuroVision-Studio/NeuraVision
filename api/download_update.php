<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'version' => '1.0.0',
    'download_url' => 'https://neuracreation.com/api/uploads/neuravision_update_v1.0.0.zip',
    'size' => 0,
    'checksum' => '',
    'message' => 'Update package not yet available. Please check back later.',
]);
