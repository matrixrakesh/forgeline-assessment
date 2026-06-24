<?php

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/WebhookIngester.php';
require_once __DIR__ . '/../src/EventProcessor.php';
require_once __DIR__ . '/../src/ReconciliationApi.php';

use App\WebhookIngester;
use App\EventProcessor;
use App\ReconciliationApi;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && $uri === '/webhook') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $ingester = new WebhookIngester();
    $ingester->handle($payload);
} 
elseif ($method === 'POST' && $uri === '/process') {
    // Manually trigger queue processing for the slice
    $processor = new EventProcessor();
    $processor->processPending();
    echo json_encode(['status' => 'processed']);
}
elseif ($method === 'GET' && $uri === '/api/reconciliation') {
    $api = new ReconciliationApi();
    $api->getExceptions();
}
else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}
