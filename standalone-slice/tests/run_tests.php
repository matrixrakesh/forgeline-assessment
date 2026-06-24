<?php

function post($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($result, true)];
}

function get($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

$baseUrl = 'http://localhost:8000';

echo "1. Testing Normal Ingestion (Case 1)...\n";
$normalPayload = [
    'event_id' => 'evt_123',
    'type' => 'order.created',
    'occurred_at' => date('Y-m-d\TH:i:s\Z'),
    'data' => ['order_ref' => 'ORD-001']
];
$res = post("$baseUrl/webhook", $normalPayload);
assert($res['status'] == 200, "Normal ingestion failed");

echo "2. Testing Duplicate Idempotency (Case 2)...\n";
$res2 = post("$baseUrl/webhook", $normalPayload);
assert($res2['status'] == 200, "Duplicate should return 200");
assert($res2['body']['status'] == 'ignored_duplicate', "Should be ignored");

echo "3. Testing Out-of-Order Delivery (Case 3)...\n";
$shippedPayload = [
    'event_id' => 'evt_124',
    'type' => 'order.line.shipped',
    'occurred_at' => '2024-01-01T10:05:00Z',
    'data' => ['order_ref' => 'ORD-001', 'line_ref' => 'L1']
];
post("$baseUrl/webhook", $shippedPayload);

$acceptedPayload = [
    'event_id' => 'evt_125',
    'type' => 'order.line.accepted',
    'occurred_at' => '2024-01-01T10:00:00Z', // OLDER timestamp!
    'data' => ['order_ref' => 'ORD-001', 'line_ref' => 'L1']
];
post("$baseUrl/webhook", $acceptedPayload);

echo "4. Testing Malformed Batch Item (Case 10)...\n";
$batchPayload = [
    'event_id' => 'evt_batch_1',
    'orders' => [
        [
            'order_ref' => 'ORD-002',
            'lines' => [
                ['line_ref' => 'L1', 'qty' => 10], // Good line
                ['line_ref' => 'L2', 'qty' => 'oops'] // Garbage line
            ]
        ]
    ]
];
$resBatch = post("$baseUrl/webhook", $batchPayload);
assert($resBatch['status'] == 200, "Batch ingestion failed");

echo "5. Testing Retry of Completed Event (Case 13)...\n";
$completedRetryPayload = [
    'event_id' => 'evt_126',
    'type' => 'order.line.shipped',
    'occurred_at' => '2024-01-01T10:05:00Z', // Same as shipped above
    'data' => ['order_ref' => 'ORD-001', 'line_ref' => 'L1']
];
post("$baseUrl/webhook", $completedRetryPayload);

echo "Processing Queues...\n";
post("$baseUrl/process", []);

echo "6. Checking Reconciliation API...\n";
$recon = get("$baseUrl/api/reconciliation");

// Out of order check
$lineStatus = '';
foreach ($recon['stuck_lines'] as $line) {
    if ($line['order_ref'] == 'ORD-001' && $line['line_ref'] == 'L1') {
        $lineStatus = $line['status'];
    }
}
assert($lineStatus === '', "Out-of-order check failed. Line should be 'shipped' and not stuck.");

echo "All tests passed!\n";
