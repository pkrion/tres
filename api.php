<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

function jsonResponse($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getActiveSessionId(): ?int {
    $db = getDb();
    $stmt = $db->query("SELECT id FROM sessions WHERE closed_at IS NULL ORDER BY opened_at DESC LIMIT 1");
    $sessionId = $stmt->fetchColumn();
    return $sessionId === false ? null : (int)$sessionId;
}

function mapProductRow(array $row): array {
    return [
        'reference' => trim($row['reference'] ?? ''),
        'description' => trim($row['description'] ?? ''),
        'barcode' => trim($row['barcode'] ?? ''),
        'price' => (float) ($row['price'] ?? 0)
    ];
}

function ensureExportDirectory(string $path): string {
    $fullPath = __DIR__ . '/' . ltrim($path, '/');
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0775, true);
    }
    return rtrim($fullPath, '/');
}

try {
    $db = getDb();
} catch (Exception $e) {
    jsonResponse(['error' => 'DB connection failed', 'details' => $e->getMessage()], 500);
}

switch ($action) {
    case 'settings':
        $settings = [
            'printerName' => getSetting('printerName', ''),
            'ticketHeader' => getSetting('ticketHeader', 'Mi tienda'),
            'ticketFooter' => getSetting('ticketFooter', 'Gracias por su compra'),
            'defaultVat' => getSetting('defaultVat', 21),
            'exportPath' => getSetting('exportPath', 'exports')
        ];
        jsonResponse(['settings' => $settings]);
        break;

    case 'saveSettings':
        $settings = [
            'printerName' => $input['printerName'] ?? '',
            'ticketHeader' => $input['ticketHeader'] ?? 'Mi tienda',
            'ticketFooter' => $input['ticketFooter'] ?? 'Gracias por su compra',
            'defaultVat' => (float)($input['defaultVat'] ?? 21),
            'exportPath' => $input['exportPath'] ?? 'exports'
        ];
        foreach ($settings as $key => $value) {
            setSetting($key, $value);
        }
        jsonResponse(['saved' => true, 'settings' => $settings]);
        break;

    case 'products':
        $search = trim($input['search'] ?? ($_GET['search'] ?? ''));
        if ($search === '') {
            $stmt = $db->query('SELECT * FROM products ORDER BY reference LIMIT 100');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare('SELECT * FROM products WHERE reference LIKE :term OR description LIKE :term OR barcode LIKE :term ORDER BY reference LIMIT 100');
            $stmt->execute([':term' => "%$search%"]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        jsonResponse(['products' => $rows]);
        break;

    case 'importProducts':
        $items = $input['items'] ?? [];
        $inserted = 0;
        $stmt = $db->prepare('INSERT OR REPLACE INTO products (reference, description, barcode, price) VALUES (:reference, :description, :barcode, :price)');
        foreach ($items as $item) {
            $data = mapProductRow($item);
            if ($data['reference'] === '' || $data['price'] <= 0) {
                continue;
            }
            $stmt->execute([
                ':reference' => $data['reference'],
                ':description' => $data['description'],
                ':barcode' => $data['barcode'],
                ':price' => $data['price']
            ]);
            $inserted++;
        }
        jsonResponse(['imported' => $inserted]);
        break;

    case 'openSession':
        if (getActiveSessionId()) {
            jsonResponse(['message' => 'Ya hay una caja abierta']);
        }
        $openingCash = (float)($input['openingCash'] ?? 0);
        $stmt = $db->prepare('INSERT INTO sessions (opened_at, opening_cash) VALUES (:opened_at, :opening_cash)');
        $stmt->execute([
            ':opened_at' => date('c'),
            ':opening_cash' => $openingCash
        ]);
        jsonResponse(['sessionId' => $db->lastInsertId()]);
        break;

    case 'sessionStatus':
        $sessionId = getActiveSessionId();
        jsonResponse(['open' => $sessionId !== null, 'sessionId' => $sessionId]);
        break;

    case 'createTicket':
        $sessionId = getActiveSessionId();
        if (!$sessionId) {
            jsonResponse(['error' => 'No hay caja abierta.'], 400);
        }
        $items = $input['items'] ?? [];
        $vatRate = (float)($input['vatRate'] ?? getSetting('defaultVat', 21));
        $subtotal = 0;
        $lineStmt = $db->prepare('INSERT INTO ticket_items (ticket_id, reference, description, barcode, price, quantity, discount, line_total) VALUES (:ticket_id, :reference, :description, :barcode, :price, :quantity, :discount, :line_total)');

        $db->beginTransaction();
        try {
            foreach ($items as $item) {
                $quantity = max((float)($item['quantity'] ?? 1), 0.01);
                $price = (float)($item['price'] ?? 0);
                $discount = (float)($item['discount'] ?? 0);
                $line = $quantity * $price * (1 - ($discount / 100));
                $subtotal += $line;
            }
            $tax = $subtotal * ($vatRate / 100);
            $total = $subtotal + $tax;
            $ticketStmt = $db->prepare('INSERT INTO tickets (session_id, created_at, vat_rate, subtotal, tax, total) VALUES (:session_id, :created_at, :vat_rate, :subtotal, :tax, :total)');
            $ticketStmt->execute([
                ':session_id' => $sessionId,
                ':created_at' => date('c'),
                ':vat_rate' => $vatRate,
                ':subtotal' => $subtotal,
                ':tax' => $tax,
                ':total' => $total
            ]);
            $ticketId = (int)$db->lastInsertId();

            foreach ($items as $item) {
                $quantity = max((float)($item['quantity'] ?? 1), 0.01);
                $price = (float)($item['price'] ?? 0);
                $discount = (float)($item['discount'] ?? 0);
                $line = $quantity * $price * (1 - ($discount / 100));
                $lineStmt->execute([
                    ':ticket_id' => $ticketId,
                    ':reference' => $item['reference'] ?? '',
                    ':description' => $item['description'] ?? '',
                    ':barcode' => $item['barcode'] ?? '',
                    ':price' => $price,
                    ':quantity' => $quantity,
                    ':discount' => $discount,
                    ':line_total' => $line
                ]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'No se pudo guardar el ticket', 'details' => $e->getMessage()], 500);
        }

        $header = getSetting('ticketHeader', 'Mi tienda');
        $footer = getSetting('ticketFooter', 'Gracias por su compra');
        $ticketHtml = [
            'header' => $header,
            'footer' => $footer,
            'ticketId' => $ticketId,
            'items' => $items,
            'totals' => [
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'vatRate' => $vatRate
            ]
        ];
        jsonResponse(['ticketId' => $ticketId, 'ticket' => $ticketHtml]);
        break;

    case 'closeSession':
        $sessionId = getActiveSessionId();
        if (!$sessionId) {
            jsonResponse(['error' => 'No hay caja abierta.'], 400);
        }
        $stmt = $db->prepare('SELECT reference, description, SUM(quantity) as units, SUM(line_total) as revenue FROM ticket_items ti JOIN tickets t ON ti.ticket_id = t.id WHERE t.session_id = :session_id GROUP BY reference, description');
        $stmt->execute([':session_id' => $sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalStmt = $db->prepare('SELECT SUM(subtotal) as subtotal, SUM(tax) as tax, SUM(total) as total FROM tickets WHERE session_id = :session_id');
        $totalStmt->execute([':session_id' => $sessionId]);
        $agg = $totalStmt->fetch(PDO::FETCH_ASSOC);
        $base = (float)($agg['subtotal'] ?? 0);
        $closingTax = (float)($agg['tax'] ?? 0);
        $total = (float)($agg['total'] ?? 0);

        $exportPath = ensureExportDirectory(getSetting('exportPath', 'exports'));
        $filename = $exportPath . '/ventas_' . date('Ymd_His') . '.csv';
        $csvLines = ["referencia,descripcion,unidades,ingresos"];
        foreach ($rows as $row) {
            $csvLines[] = sprintf('"%s","%s",%s,%s', $row['reference'], $row['description'], $row['units'], number_format($row['revenue'], 2, '.', ''));
        }
        file_put_contents($filename, implode("\n", $csvLines));

        $db->prepare('UPDATE sessions SET closed_at = :closed_at, closing_total = :closing_total, export_path = :export_path WHERE id = :id')
            ->execute([
                ':closed_at' => date('c'),
                ':closing_total' => $total,
                ':export_path' => $filename,
                ':id' => $sessionId
            ]);

        $ticketHeader = getSetting('ticketHeader', 'Mi tienda');
        $ticketFooter = getSetting('ticketFooter', 'Gracias por su compra');
        $vatRate = $base > 0 ? round(($closingTax / $base) * 100, 2) : getSetting('defaultVat', 21);
        $closingTicket = [
            'header' => $ticketHeader,
            'footer' => $ticketFooter,
            'total' => $total,
            'base' => $base,
            'tax' => $closingTax,
            'vatRate' => $vatRate,
            'exportFile' => $filename
        ];
        jsonResponse([
            'closed' => true,
            'summary' => $rows,
            'total' => $total,
            'exportFile' => $filename,
            'closingTicket' => $closingTicket,
            'csvContent' => implode("\n", $csvLines)
        ]);
        break;

    case 'exportSales':
        $date = $input['date'] ?? date('Y-m-d');
        $stmt = $db->prepare('SELECT reference, description, SUM(quantity) as units FROM ticket_items ti JOIN tickets t ON ti.ticket_id = t.id WHERE date(t.created_at) = :date GROUP BY reference, description');
        $stmt->execute([':date' => $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $csvLines = ["referencia,descripcion,unidades"];
        foreach ($rows as $row) {
            $csvLines[] = sprintf('"%s","%s",%s', $row['reference'], $row['description'], $row['units']);
        }
        jsonResponse(['csvContent' => implode("\n", $csvLines)]);
        break;

    case 'clearHistory':
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;
        if (!$from || !$to) {
            jsonResponse(['error' => 'Fechas requeridas'], 400);
        }
        $db->beginTransaction();
        try {
            $ticketIds = $db->prepare('SELECT id FROM tickets WHERE date(created_at) BETWEEN :from AND :to');
            $ticketIds->execute([':from' => $from, ':to' => $to]);
            $ids = $ticketIds->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                $db->exec("DELETE FROM ticket_items WHERE ticket_id IN ($in)");
                $db->exec("DELETE FROM tickets WHERE id IN ($in)");
            }
            $db->prepare('DELETE FROM sessions WHERE date(opened_at) BETWEEN :from AND :to AND closed_at IS NOT NULL')->execute([':from' => $from, ':to' => $to]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'No se pudo limpiar el historial', 'details' => $e->getMessage()], 500);
        }
        jsonResponse(['cleared' => true]);
        break;

    default:
        jsonResponse(['error' => 'Acción no soportada'], 400);
}
