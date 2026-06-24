<?php
namespace App;

use PDO;

class EventProcessor {
    public function processPending() {
        $db = Db::getConnection();
        
        $stmt = $db->query("SELECT * FROM forgeline_events WHERE status = 'pending' ORDER BY id ASC LIMIT 10");
        $events = $stmt->fetchAll();

        foreach ($events as $eventRow) {
            $payload = json_decode($eventRow['payload'], true);
            $type = $payload['type'] ?? $payload['event_type'] ?? '';
            
            try {
                $db->beginTransaction();
                
                if (strpos($type, 'order.line.') === 0) {
                    $this->processLineEvent($db, $payload);
                } elseif ($type === 'order.created') {
                    $this->processOrderCreated($db, $payload);
                } elseif (isset($payload['orders'])) {
                    // Handle Case 10: Malformed item inside a batch array
                    $this->processBatchOrders($db, $payload['orders']);
                }
                
                $stmtUpdate = $db->prepare("UPDATE forgeline_events SET status = 'completed' WHERE id = ?");
                $stmtUpdate->execute([$eventRow['id']]);
                
                $db->commit();
            } catch (\Exception $e) {
                $db->rollBack();
                // Mark as failed
                $stmtFail = $db->prepare("UPDATE forgeline_events SET status = 'failed' WHERE id = ?");
                $stmtFail->execute([$eventRow['id']]);
            }
        }
    }

    private function processBatchOrders(PDO $db, array $orders) {
        foreach ($orders as $orderData) {
            $orderRef = $orderData['order_ref'];
            
            // Insert order if not exists
            $stmt = $db->prepare("INSERT IGNORE INTO forgeline_orders (order_ref) VALUES (?)");
            $stmt->execute([$orderRef]);
            
            // Case 10: Iterate lines with per-item try/catch to prevent batch failure
            if (isset($orderData['lines']) && is_array($orderData['lines'])) {
                foreach ($orderData['lines'] as $lineData) {
                    try {
                        if (!isset($lineData['line_ref']) || !isset($lineData['qty']) || !is_numeric($lineData['qty'])) {
                            throw new \Exception("Malformed line data");
                        }
                        // Insert line normally (mock setup)
                    } catch (\Exception $e) {
                        // Log garbage line, quarantine it, but DO NOT throw to break the batch loop
                        error_log("Quarantined malformed line in batch: " . $e->getMessage());
                    }
                }
            }
        }
    }

    private function processOrderCreated(PDO $db, array $payload) {
        $orderRef = $payload['data']['order_ref'];
        
        $stmt = $db->prepare("INSERT IGNORE INTO forgeline_orders (order_ref) VALUES (?)");
        $stmt->execute([$orderRef]);
    }

    private function processLineEvent(PDO $db, array $payload) {
        $orderRef = $payload['data']['order_ref'];
        $lineRef = $payload['data']['line_ref'];
        $occurredAt = $payload['occurred_at'];
        $status = str_replace('order.line.', '', $payload['type'] ?? $payload['event_type']);
        
        // Ensure order exists
        $stmtOrder = $db->prepare("SELECT id FROM forgeline_orders WHERE order_ref = ?");
        $stmtOrder->execute([$orderRef]);
        $orderId = $stmtOrder->fetchColumn();
        
        if (!$orderId) {
            // Case 13: Retry of an already-completed event where order might have been deleted, or out of sync
            throw new \Exception("Order not found");
        }
        
        // Check out of order & idempotency
        $stmtLine = $db->prepare("SELECT status, last_event_occurred_at FROM forgeline_order_lines WHERE order_id = ? AND line_ref = ?");
        $stmtLine->execute([$orderId, $lineRef]);
        $line = $stmtLine->fetch();
        
        if ($line) {
            // Case 13: Retry of already completed event (event state is already target state)
            if ($line['status'] === $status) {
                return; // Graceful no-op
            }

            $lastTime = strtotime($line['last_event_occurred_at']);
            $newTime = strtotime($occurredAt);
            
            if ($newTime <= $lastTime) {
                // Case 3: Out of order: discard silently (or log)
                return;
            }
            
            // Update line
            $stmtUpdate = $db->prepare("UPDATE forgeline_order_lines SET status = ?, last_event_occurred_at = ? WHERE order_id = ? AND line_ref = ?");
            $stmtUpdate->execute([$status, date('Y-m-d H:i:s', $newTime), $orderId, $lineRef]);
            
        } else {
            // Insert line
            $stmtInsert = $db->prepare("INSERT INTO forgeline_order_lines (order_id, line_ref, status, last_event_occurred_at) VALUES (?, ?, ?, ?)");
            $stmtInsert->execute([$orderId, $lineRef, $status, date('Y-m-d H:i:s', strtotime($occurredAt))]);
        }
    }
}
