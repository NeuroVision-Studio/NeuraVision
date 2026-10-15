<?php
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding("UTF-8");
mb_http_output("UTF-8");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer/src/Exception.php';
require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';

function get_client_ip() {
    $headers = [
        'HTTP_X_REAL_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ipList = explode(',', $_SERVER[$header]);
            foreach ($ipList as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }

                if (strpos($ip, '::ffff:') === 0) {
                    $mapped = substr($ip, strrpos($ip, ':') + 1);
                    if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return $mapped;
                    }
                }
            }
        }
    }
    return 'Unknown';
}

function generateUniqueId($ip) {
    $unique_id = md5($ip . time());
    $unique_id = substr($unique_id, 0, 5);
    if (!preg_match('/[a-zA-Z]/', $unique_id)) {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $unique_id[0] = $letters[rand(0, 25)];
    }
    return $unique_id;
}

function sendToTelegram($token, $chatId, $text) {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $postFields = [
        'chat_id' => $chatId,
        'text'    => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    return $err ? false : $response;
}

$ip = get_client_ip();
$country = 'Unknown';

if ($ip !== 'Unknown') {
    $ipinfo = 'http://ip-api.com/json/' . $ip;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $ipinfo);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['country'])) {
            $country = $data['country'];
        }
    }
    curl_close($ch);
}

$uniqueId = generateUniqueId($ip);

$fullName  = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$email     = isset($_POST['email']) ? trim($_POST['email']) : '';
$xProfile  = isset($_POST['x_profile']) ? trim($_POST['x_profile']) : '';
$message   = isset($_POST['message']) ? trim($_POST['message']) : '';

if (empty($email) && empty($xProfile)) {
    echo json_encode(['status' => 'error', 'message' => 'Email or Twitter required!']);
    exit;
}

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Email']);
    exit;
}

if (!empty($xProfile) && !preg_match('/^@?(\w){1,15}$/', $xProfile)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Twitter username']);
    exit;
}

$mailBody = "HELLO REQUEST\n\n"
          . "Unique ID: {$uniqueId}\n"
          . "Full Name: {$fullName}\n"
          . "Email: {$email}\n"
          . "X-Profile: {$xProfile}\n"
          . "Message: {$message}\n"
          . "IP: {$ip}\n"
          . "Country: {$country}";

$telegramText = "<b>HELLO REQUEST</b>\n\n"
          . "<b>User ID:</b> #" . $uniqueId . "\n"
          . "<b>Name:</b> " . ($fullName !== '' ? htmlspecialchars($fullName) : '—') . "\n"
          . "<b>Email:</b> " . ($email !== '' ? "<code>" . htmlspecialchars($email) . "</code>" : '—') . "\n"
          . "<b>X:</b> " . ($xProfile !== '' ? "<code>" . htmlspecialchars($xProfile) . "</code>" : '—') . "\n"
          . "<b>Message:</b> " . htmlspecialchars($message) . "\n"
          . "<b>IP:</b> <code>{$ip}</code>\n"
          . "<b>Country:</b> {$country}";

// Demo/lead notifications use their own bot+group when set, else the main one.
$telegramToken  = getenv('DEMO_TG_TOKEN') ?: (getenv('TG_BOT_TOKEN') ?: '');
$telegramChatId = getenv('DEMO_TG_CHAT')  ?: (getenv('TG_CHAT_ID')  ?: '');

$mail = new PHPMailer(true);

try {

    $mail->isMail();
    $mail->setFrom('support@neuracreation.com', 'Neuravision');
    $mail->addAddress('support@neuracreation.com');
    $mail->Subject = 'New Form Submission';
    $mail->Body    = $mailBody;

    $mailSent = $mail->send();

    $tgSent = false;
    if (!empty($telegramToken) && !empty($telegramChatId)) {
        $tgResult = sendToTelegram($telegramToken, $telegramChatId, $telegramText);
        $tgSent = $tgResult !== false;
    }

    if ($mailSent) {
        echo json_encode([
            'status' => 'success',
            'id' => $uniqueId,
            'telegram' => $tgSent ? 'ok' : 'failed'
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => $mail->ErrorInfo,
            'id' => $uniqueId
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => "Mailer Error: {$mail->ErrorInfo}",
        'id' => $uniqueId
    ]);
}