<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Johannesburg');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond(int $status, bool $success, string $message): never {
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Method not allowed.');
}

// TSA: only accept JSON posts from this site's own pages.
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($contentType, 'application/json')) {
    respond(415, false, 'Invalid request.');
}
$allowedHosts = ['www.zidedele.co.za', 'zidedele.co.za'];
$originHeader = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
$originHost = strtolower((string)parse_url($originHeader, PHP_URL_HOST));
$requestHost = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
if ($originHost === '' || !in_array($originHost, array_unique([...$allowedHosts, $requestHost]), true)) {
    respond(403, false, 'Invalid request.');
}

$raw = file_get_contents('php://input', false, null, 0, 20000);
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    respond(400, false, 'Invalid request.');
}

// Honeypot: bots should fill this; people should not.
if (!empty(trim((string)($data['_honey'] ?? '')))) {
    respond(200, true, 'Thank you.');
}

// TSA: time check - a person needs at least a few seconds to fill in the form.
$fillMs = (int)($data['_t'] ?? 0);
if ($fillMs < 4000) {
    respond(200, true, 'Thank you.');
}

// TSA: simple per-IP rate limit (5 enquiries per 15 minutes), stored outside the web root.
$rateDir = rtrim(sys_get_temp_dir(), '/') . '/zidedele-enquiry-rate';
if (!is_dir($rateDir)) {
    @mkdir($rateDir, 0700, true);
}
$rateFile = $rateDir . '/' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.json';
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $hits = json_decode((string)@file_get_contents($rateFile), true) ?: [];
}
$hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - 900));
if (count($hits) >= 5) {
    respond(429, false, 'Too many enquiries. Please try again later or call Zidedele directly.');
}

$clean = static function ($value, int $max = 500): string {
    $value = trim((string)$value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
};

$name = $clean($data['name'] ?? '', 120);
$company = $clean($data['company'] ?? '', 160);
$email = $clean($data['email'] ?? '', 200);
$phone = $clean($data['phone'] ?? '', 80);
$service = $clean($data['service'] ?? '', 100);
$date = $clean($data['date'] ?? '', 20);
$origin = $clean($data['origin'] ?? '', 200);
$destination = $clean($data['destination'] ?? '', 200);
$requirements = $clean($data['requirements'] ?? '', 3000);
$privacyConsent = $clean($data['privacy_consent'] ?? '', 10);

$allowedServices = ['Road transport', 'Warehousing', 'Materials handling', 'Cargo handling', 'Machinery', 'Other'];
$errors = [];
if ($name === '') $errors[] = 'Full name';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email';
if ($phone === '') $errors[] = 'Phone';
if (!in_array($service, $allowedServices, true)) $errors[] = 'Service required';
if ($origin === '') $errors[] = 'Collection / origin';
if ($destination === '') $errors[] = 'Delivery / destination';
if ($privacyConsent !== 'yes') $errors[] = 'Privacy consent';

$today = new DateTimeImmutable('today');
$selectedDate = DateTimeImmutable::createFromFormat('Y-m-d', $date);
if (!$selectedDate || $selectedDate->format('Y-m-d') !== $date || $selectedDate < $today) {
    $errors[] = 'Preferred date';
}

$wordCount = preg_match_all('/\S+/u', $requirements, $matches);
if ((function_exists('mb_strlen') ? mb_strlen($requirements, 'UTF-8') : strlen($requirements)) < 15 || $wordCount < 3) {
    $errors[] = 'Cargo / requirements';
}

if ($errors) {
    respond(422, false, 'Please complete the required fields correctly: ' . implode(', ', $errors) . '.');
}

$configPath = getenv('ZIDEDELE_MAIL_CONFIG') ?: dirname((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__)) . '/zidedele-mail-config.php';
if (!is_file($configPath)) {
    error_log('Zidedele mail config not found: ' . $configPath);
    respond(503, false, 'Email service is not configured yet.');
}
$config = require $configPath;
if (!is_array($config)) {
    respond(503, false, 'Email service configuration is invalid.');
}

$autoloadCandidates = array_filter([
    (string)($config['phpmailer_autoload'] ?? ''),
    __DIR__ . '/vendor/autoload.php',
]);
$loaded = false;
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        $loaded = true;
        break;
    }
}
if (!$loaded || !class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    error_log('PHPMailer autoload not found.');
    respond(503, false, 'Email service dependency is not available.');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$companyRow = $company !== '' ? '<tr><th>Company</th><td>' . $e($company) . '</td></tr>' : '';
$html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1c1c1c">'
    . '<h2 style="margin:0 0 18px;color:#e95732">New Zidedele website enquiry</h2>'
    . '<table cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:720px">'
    . '<tr><th align="left">Name</th><td>' . $e($name) . '</td></tr>'
    . $companyRow
    . '<tr><th align="left">Email</th><td>' . $e($email) . '</td></tr>'
    . '<tr><th align="left">Phone</th><td>' . $e($phone) . '</td></tr>'
    . '<tr><th align="left">Service</th><td>' . $e($service) . '</td></tr>'
    . '<tr><th align="left">Preferred date</th><td>' . $e($date) . '</td></tr>'
    . '<tr><th align="left">Collection / origin</th><td>' . $e($origin) . '</td></tr>'
    . '<tr><th align="left">Delivery / destination</th><td>' . $e($destination) . '</td></tr>'
    . '<tr><th align="left">Cargo / requirements</th><td>' . nl2br($e($requirements)) . '</td></tr>'
    . '<tr><th align="left">Privacy consent</th><td>Yes</td></tr>'
    . '</table></body></html>';

$plain = "New Zidedele website enquiry\n\n"
    . "Name: {$name}\n"
    . ($company !== '' ? "Company: {$company}\n" : '')
    . "Email: {$email}\nPhone: {$phone}\nService: {$service}\nPreferred date: {$date}\n"
    . "Collection / origin: {$origin}\nDelivery / destination: {$destination}\n"
    . "Cargo / requirements: {$requirements}\n"
    . "Privacy consent: Yes\n";

// Count this enquiry towards the rate limit only once it passes validation.
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits), LOCK_EX);

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string)$config['host'];
    $mail->Port = (int)($config['port'] ?? 587);
    $mail->SMTPAuth = true;
    $mail->Username = (string)$config['username'];
    $mail->Password = (string)$config['password'];
    $encryption = strtolower((string)($config['encryption'] ?? 'tls'));
    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string)$config['from_email'], (string)($config['from_name'] ?? 'Zidedele Logistics Website'));
    $mail->addAddress((string)$config['to_email'], (string)($config['to_name'] ?? 'Zidedele Logistics Solutions'));
    $mail->addReplyTo($email, $name);
    $mail->Subject = 'Website enquiry - ' . $service;
    $mail->isHTML(true);
    $mail->Body = $html;
    $mail->AltBody = $plain;
    $mail->send();
    respond(200, true, 'Your request was sent successfully.');
} catch (Throwable $ex) {
    error_log('Zidedele enquiry send failed: ' . $ex->getMessage());
    respond(500, false, 'We could not send your enquiry right now. Please call or email Zidedele directly.');
}
