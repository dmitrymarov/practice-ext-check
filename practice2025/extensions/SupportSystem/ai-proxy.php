<?php
/**
 * Прямой прокси-скрипт для AI-сервиса
 */

// Разрешаем CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Обрабатываем предзапросы OPTIONS для CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// URL AI-сервиса (Фиксированный адрес внутри Docker-сети)
$aiServiceUrl = "http://ai-service:5000/api/search_ai";

// Получаем данные запроса
$requestData = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $requestData = json_decode($input, true) ?? [];
} else {
    $requestData = [
        'query' => $_GET['query'] ?? '',
        'context' => json_decode($_GET['context'] ?? '[]', true),
        'user_id' => $_GET['user_id'] ?? null
    ];
}

// Если запрос пустой, возвращаем ошибку
if (empty($requestData['query'])) {
    echo json_encode([
        'success' => false,
        'answer' => 'Пожалуйста, укажите поисковый запрос',
        'sources' => []
    ]);
    exit;
}

// Выполняем запрос к AI-сервису
$ch = curl_init($aiServiceUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Обработка ошибок
if ($error) {
    echo json_encode([
        'success' => false,
        'answer' => 'Ошибка соединения с AI-сервисом: ' . $error,
        'sources' => []
    ]);
    exit;
}

if ($httpCode >= 400) {
    echo json_encode([
        'success' => false,
        'answer' => 'AI-сервис вернул ошибку (HTTP ' . $httpCode . ')',
        'sources' => []
    ]);
    exit;
}

// Передаем ответ от AI-сервиса
echo $response;