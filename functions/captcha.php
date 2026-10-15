<?php
require_once __DIR__ . '/../includes/x_ads_config.php'; // loads .env
header('Content-Type: application/json; charset=utf-8');

if (!isset($_POST['hcaptcha'])) {
    echo json_encode(["status" => "error", "message" => "Missing hCaptcha token."]);
    exit;
}

$secret = getenv("HCAPTCHA_SECRET") ?: (isset($_ENV["HCAPTCHA_SECRET"]) ? $_ENV["HCAPTCHA_SECRET"] : "");
$data = [
    'secret' => $secret,
    'response' => $_POST['hcaptcha'],
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://hcaptcha.com/siteverify");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
if ($response === false) {
    echo json_encode(["status" => "error", "message" => "Verification request failed."]);
    curl_close($ch);
    exit;
}
curl_close($ch);

$responseData = json_decode($response, true);

if (!empty($responseData['success'])) {
    echo json_encode(["status" => "success", "message" => "hCaptcha verification successful."]);
} else {
    $err = $responseData['error-codes'] ?? [];
    echo json_encode(["status" => "error", "message" => "Robot verification failed.", "errors" => $err]);
}
