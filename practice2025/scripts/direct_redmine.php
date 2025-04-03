<?php
// Включаем отображение ошибок
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Конфигурация
$redmineUrl = 'http://redmine:3000';
$apiKey = 'e0d62b7b9695048dd4a4d44bbc9f074c865fcf2f';

echo "===== Direct Redmine API Test =====\n\n";
echo "Using Redmine URL: $redmineUrl\n";
echo "Using API key: " . substr($apiKey, 0, 5) . "...\n\n";

// 1. Получаем список тикетов
echo "1. Listing tickets...\n";
$command = "curl -s -H \"X-Redmine-API-Key: $apiKey\" \"$redmineUrl/issues.json?limit=2\"";
echo "Command: $command\n";
$result = shell_exec($command);
echo "Result: " . substr($result, 0, 200) . "...\n\n";

// 2. Создаем тикет
echo "2. Creating a ticket...\n";
$ticket = [
    'issue' => [
        'subject' => 'Test Ticket ' . date('Y-m-d H:i:s'),
        'description' => 'This is a test ticket created via direct PHP curl',
        'project_id' => 1,
        'tracker_id' => 1,
        'priority_id' => 2,
        'status_id' => 1
    ]
];

$jsonData = json_encode($ticket);
$command = "curl -s -X POST \"$redmineUrl/issues.json\" -H \"Content-Type: application/json\" -H \"X-Redmine-API-Key: $apiKey\" -d '$jsonData'";
echo "Command: $command\n";
$result = shell_exec($command);
echo "Result: $result\n\n";

// Извлекаем ID созданного тикета
$data = json_decode($result, true);
$ticketId = $data['issue']['id'] ?? null;

if ($ticketId) {
    echo "Ticket created successfully with ID: $ticketId\n\n";

    // 3. Добавляем комментарий
    echo "3. Adding a comment to ticket #$ticketId...\n";
    $comment = [
        'issue' => [
            'notes' => 'This is a test comment added at ' . date('Y-m-d H:i:s')
        ]
    ];

    $jsonData = json_encode($comment);
    $command = "curl -s -X PUT \"$redmineUrl/issues/$ticketId.json\" -H \"Content-Type: application/json\" -H \"X-Redmine-API-Key: $apiKey\" -d '$jsonData'";
    echo "Command: $command\n";
    $result = shell_exec($command);
    echo "Comment added. Status code: ";
    $lastCode = shell_exec("echo $?");
    echo $lastCode == '0' ? "Success\n\n" : "Failed ($lastCode)\n\n";

    // 4. Получаем обновленный тикет
    echo "4. Getting updated ticket #$ticketId...\n";
    $command = "curl -s -H \"X-Redmine-API-Key: $apiKey\" \"$redmineUrl/issues/$ticketId.json?include=journals\"";
    echo "Command: $command\n";
    $result = shell_exec($command);
    echo "Result: " . substr($result, 0, 500) . "...\n";
} else {
    echo "Failed to create ticket or extract ticket ID.\n";
}

echo "\n===== Test Completed =====\n";