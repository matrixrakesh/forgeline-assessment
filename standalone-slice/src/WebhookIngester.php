<?php
namespace App;

use PDO;
use Exception;

class WebhookIngester {
    public function handle(array $payload) {
        $db = Db::getConnection();
        
        $eventId = $payload['event_id'] ?? null;
        if (!$eventId) {
            http_response_code(400);
            echo json_encode(["error" => "Missing event_id"]);
            return;
        }

        try {
            $stmt = $db->prepare("INSERT INTO forgeline_events (event_id, payload) VALUES (:event_id, :payload)");
            $stmt->execute([
                ':event_id' => $eventId,
                ':payload' => json_encode($payload)
            ]);
            
            http_response_code(200);
            echo json_encode(["status" => "accepted"]);
            
        } catch (\PDOException $e) {
            // Error 23000 is Integrity constraint violation (Duplicate entry)
            if ($e->getCode() == 23000) {
                // Idempotent return - we've already seen this
                http_response_code(200);
                echo json_encode(["status" => "ignored_duplicate"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Database error"]);
            }
        }
    }
}
