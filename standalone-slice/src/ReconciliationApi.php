<?php
namespace App;

use PDO;

class ReconciliationApi {
    public function getExceptions() {
        $db = Db::getConnection();
        
        // 1. Failed Events
        $stmtEvents = $db->query("SELECT * FROM forgeline_events WHERE status = 'failed'");
        $failedEvents = $stmtEvents->fetchAll();
        
        // 2. Orders stuck in 'pending' for a long time (mock logic: older than 10 mins)
        // For testing, we just show pending orders
        $stmtOrders = $db->query("SELECT o.order_ref, l.line_ref, l.status 
                                  FROM forgeline_orders o 
                                  JOIN forgeline_order_lines l ON o.id = l.order_id 
                                  WHERE l.status NOT IN ('shipped', 'cancelled', 'refunded')");
        $stuckLines = $stmtOrders->fetchAll();

        header('Content-Type: application/json');
        echo json_encode([
            'failed_events' => $failedEvents,
            'stuck_lines' => $stuckLines
        ]);
    }
}
