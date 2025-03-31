<?php
/**
 * Прямой прокси-скрипт для Redmine
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

// Настройки Redmine
$redmineUrl = "http://redmine:3000";
$apiKey = "c177337d75a1da3bb43d67ec9b9bb139b299502f";

// Получаем операцию и другие параметры
$operation = $_GET['operation'] ?? ($_POST['operation'] ?? '');
if (empty($operation)) {
    echo json_encode([
        'success' => false,
        'error' => 'Операция не указана'
    ]);
    exit;
}

// Обрабатываем операцию
switch ($operation) {
    case 'create_ticket':
        createTicket();
        break;
    case 'list_tickets':
        listTickets();
        break;
    case 'get_ticket':
        getTicket();
        break;
    case 'add_comment':
        addComment();
        break;
    default:
        echo json_encode([
            'success' => false,
            'error' => 'Неизвестная операция: ' . $operation
        ]);
        exit;
}

/**
 * Создание тикета
 */
function createTicket()
{
    global $redmineUrl, $apiKey;

    // Получаем параметры
    $subject = $_POST['subject'] ?? ($_GET['subject'] ?? '');
    $description = $_POST['description'] ?? ($_GET['description'] ?? '');
    $priority = $_POST['priority'] ?? ($_GET['priority'] ?? 'normal');

    if (empty($subject) || empty($description)) {
        echo json_encode([
            'success' => false,
            'error' => 'Требуются subject и description'
        ]);
        exit;
    }

    // Подготовка данных
    $priorityId = getPriorityId($priority);
    $data = [
        'issue' => [
            'subject' => $subject,
            'description' => $description,
            'project_id' => 1,
            'priority_id' => $priorityId
        ]
    ];

    // Выполняем запрос
    $url = "{$redmineUrl}/issues.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Redmine-API-Key: ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Обработка ответа
    if ($error) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка соединения с Redmine: ' . $error
        ]);
        exit;
    }

    if ($httpCode >= 400) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка создания тикета (HTTP ' . $httpCode . '): ' . $response
        ]);
        exit;
    }

    // Передаем ответ
    $data = json_decode($response, true);
    echo json_encode([
        'success' => true,
        'ticket' => $data['issue'] ?? []
    ]);
}

/**
 * Получение списка тикетов
 */
function listTickets()
{
    global $redmineUrl, $apiKey;

    // Выполняем запрос
    $url = "{$redmineUrl}/issues.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Redmine-API-Key: ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Обработка ответа
    if ($error) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка соединения с Redmine: ' . $error
        ]);
        exit;
    }

    if ($httpCode >= 400) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка получения списка тикетов (HTTP ' . $httpCode . ')'
        ]);
        exit;
    }

    // Передаем ответ
    $data = json_decode($response, true);
    echo json_encode([
        'success' => true,
        'tickets' => $data['issues'] ?? []
    ]);
}

/**
 * Получение информации о тикете
 */
function getTicket()
{
    global $redmineUrl, $apiKey;

    // Получаем ID тикета
    $ticketId = $_GET['ticket_id'] ?? '';
    if (empty($ticketId)) {
        echo json_encode([
            'success' => false,
            'error' => 'Не указан ticket_id'
        ]);
        exit;
    }

    // Выполняем запрос
    $url = "{$redmineUrl}/issues/{$ticketId}.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Redmine-API-Key: ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Обработка ответа
    if ($error) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка соединения с Redmine: ' . $error
        ]);
        exit;
    }

    if ($httpCode >= 400) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка получения тикета (HTTP ' . $httpCode . ')'
        ]);
        exit;
    }

    // Передаем ответ
    $data = json_decode($response, true);
    echo json_encode([
        'success' => true,
        'ticket' => $data['issue'] ?? []
    ]);
}

/**
 * Добавление комментария к тикету
 */
function addComment()
{
    global $redmineUrl, $apiKey;

    // Получаем параметры
    $ticketId = $_POST['ticket_id'] ?? ($_GET['ticket_id'] ?? '');
    $comment = $_POST['comment'] ?? ($_GET['comment'] ?? '');

    if (empty($ticketId) || empty($comment)) {
        echo json_encode([
            'success' => false,
            'error' => 'Требуются ticket_id и comment'
        ]);
        exit;
    }

    // Подготовка данных
    $data = [
        'issue' => [
            'notes' => $comment
        ]
    ];

    // Выполняем запрос
    $url = "{$redmineUrl}/issues/{$ticketId}.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Redmine-API-Key: ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Обработка ответа
    if ($error) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка соединения с Redmine: ' . $error
        ]);
        exit;
    }

    if ($httpCode >= 400) {
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка добавления комментария (HTTP ' . $httpCode . ')'
        ]);
        exit;
    }

    // Возвращаем успех
    echo json_encode([
        'success' => true
    ]);
}

/**
 * Получение ID приоритета
 * 
 * @param string $priority Приоритет
 * @return int ID приоритета
 */
function getPriorityId($priority)
{
    $priorities = [
        'low' => 1,
        'normal' => 2,
        'high' => 3,
        'urgent' => 4
    ];

    return $priorities[strtolower($priority)] ?? 2;
}