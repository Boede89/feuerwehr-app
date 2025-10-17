<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    // Login ist für Atemschutzeinträge nicht erforderlich
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $entry_id = (int)($input['entry_id'] ?? 0);
    
    if ($entry_id <= 0) {
        throw new Exception('Ungültige Eintrag-ID');
    }
    
    // Lade Atemschutzeintrag
    $stmt = $db->prepare("
        SELECT ae.*, 
               COALESCE(u.first_name, 'Unbekannt') as first_name, 
               COALESCE(u.last_name, '') as last_name, 
               COALESCE(u.email, '') as email
        FROM atemschutz_entries ae
        LEFT JOIN users u ON ae.requester_id = u.id
        WHERE ae.id = ? AND ae.status = 'pending'
    ");
    $stmt->execute([$entry_id]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$entry) {
        throw new Exception('Atemschutzeintrag nicht gefunden oder bereits bearbeitet');
    }
    
    $db->beginTransaction();
    
    try {
        if ($action === 'approve') {
            // Atemschutzeintrag genehmigen
            $stmt = $db->prepare("
                UPDATE atemschutz_entries 
                SET status = 'approved', approved_by = ?, approved_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $entry_id]);
            
            // Aktualisiere die entsprechenden Zertifikate der Geräteträger
            $stmt = $db->prepare("
                SELECT aet.traeger_id 
                FROM atemschutz_entry_traeger aet 
                WHERE aet.entry_id = ?
            ");
            $stmt->execute([$entry_id]);
            $traeger_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($traeger_ids as $traeger_id) {
                if ($entry['entry_type'] === 'einsatz' || $entry['entry_type'] === 'uebung') {
                    // Aktualisiere Übung/Einsatz Datum
                    $stmt = $db->prepare("
                        UPDATE atemschutz_traeger 
                        SET uebung_am = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$entry['entry_date'], $traeger_id]);
                } elseif ($entry['entry_type'] === 'atemschutzstrecke') {
                    // Aktualisiere Strecke Datum
                    $stmt = $db->prepare("
                        UPDATE atemschutz_traeger 
                        SET strecke_am = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$entry['entry_date'], $traeger_id]);
                } elseif ($entry['entry_type'] === 'g263') {
                    // Aktualisiere G26.3 Datum
                    $stmt = $db->prepare("
                        UPDATE atemschutz_traeger 
                        SET g263_am = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$entry['entry_date'], $traeger_id]);
                }
            }
            
            // Sende Bestätigungs-E-Mail an Antragsteller
            $subject = "✅ Atemschutzeintrag genehmigt - " . $entry['first_name'] . ' ' . $entry['last_name'];
            $message = createAtemschutzApprovalEmailHTML($entry);
            send_email($entry['email'], $subject, $message);
            
            // Logge Aktivität
            log_activity($_SESSION['user_id'], 'atemschutz_entry_approved', "Atemschutzeintrag #$entry_id genehmigt");
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Atemschutzeintrag wurde genehmigt']);
            
        } elseif ($action === 'reject') {
            $reason = trim($input['reason'] ?? '');
            if (empty($reason)) {
                throw new Exception('Ablehnungsgrund ist erforderlich');
            }
            
            // Atemschutzeintrag ablehnen
            $stmt = $db->prepare("
                UPDATE atemschutz_entries 
                SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reason, $_SESSION['user_id'], $entry_id]);
            
            // Sende Ablehnungs-E-Mail an Antragsteller
            $subject = "❌ Atemschutzeintrag abgelehnt - " . $entry['first_name'] . ' ' . $entry['last_name'];
            $message = createAtemschutzRejectionEmailHTML($entry, $reason);
            send_email($entry['email'], $subject, $message);
            
            // Logge Aktivität
            log_activity($_SESSION['user_id'], 'atemschutz_entry_rejected', "Atemschutzeintrag #$entry_id abgelehnt");
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Atemschutzeintrag wurde abgelehnt']);
            
        } elseif ($action === 'delete') {
            // Atemschutzeintrag löschen
            $stmt = $db->prepare("DELETE FROM atemschutz_entries WHERE id = ?");
            $stmt->execute([$entry_id]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Atemschutzeintrag wurde gelöscht']);
            
        } else {
            throw new Exception('Ungültige Aktion');
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Process Atemschutz Entry Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// HTML-E-Mail für Atemschutzeintrag-Genehmigung erstellen
function createAtemschutzApprovalEmailHTML($entry) {
    $type_names = [
        'einsatz' => 'Einsatz',
        'uebung' => 'Übung',
        'atemschutzstrecke' => 'Atemschutzstrecke',
        'g263' => 'G26.3'
    ];
    
    $type_name = $type_names[$entry['entry_type']] ?? $entry['entry_type'];
    $formatted_date = date('d.m.Y', strtotime($entry['entry_date']));
    
    return '
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Atemschutzeintrag genehmigt</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .header .icon { font-size: 48px; margin-bottom: 10px; }
            .content { padding: 30px; }
            .success-badge { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center; font-weight: bold; }
            .details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .detail-row { display: flex; margin-bottom: 12px; align-items: center; }
            .detail-label { font-weight: bold; color: #495057; width: 120px; flex-shrink: 0; }
            .detail-value { color: #212529; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; border-top: 1px solid #dee2e6; }
            .highlight { color: #28a745; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="icon">✅</div>
                <h1>Atemschutzeintrag genehmigt!</h1>
            </div>
            <div class="content">
                <div class="success-badge">
                    🎉 Ihr Atemschutzeintrag wurde erfolgreich genehmigt!
                </div>
                
                <p>Hallo <strong>' . htmlspecialchars($entry['first_name'] . ' ' . $entry['last_name']) . '</strong>,</p>
                
                <p>wir freuen uns, Ihnen mitteilen zu können, dass Ihr Atemschutzeintrag genehmigt wurde.</p>
                
                <div class="details">
                    <h3 style="margin-top: 0; color: #495057;">📋 Eintragsdetails</h3>
                    <div class="detail-row">
                        <div class="detail-label">🎭 Typ:</div>
                        <div class="detail-value highlight">' . $type_name . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📅 Datum:</div>
                        <div class="detail-value">' . $formatted_date . '</div>
                    </div>
                </div>
                
                <p>Der Eintrag wurde in den entsprechenden Zertifikaten der beteiligten Geräteträger aktualisiert.</p>
            </div>
            <div class="footer">
                <p><strong>Mit freundlichen Grüßen</strong><br>Ihre Feuerwehr</p>
            </div>
        </div>
    </body>
    </html>';
}

// HTML-E-Mail für Atemschutzeintrag-Ablehnung erstellen
function createAtemschutzRejectionEmailHTML($entry, $reason) {
    $type_names = [
        'einsatz' => 'Einsatz',
        'uebung' => 'Übung',
        'atemschutzstrecke' => 'Atemschutzstrecke',
        'g263' => 'G26.3'
    ];
    
    $type_name = $type_names[$entry['entry_type']] ?? $entry['entry_type'];
    $formatted_date = date('d.m.Y', strtotime($entry['entry_date']));
    
    return '
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Atemschutzeintrag abgelehnt</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #dc3545, #e74c3c); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .header .icon { font-size: 48px; margin-bottom: 10px; }
            .content { padding: 30px; }
            .rejection-badge { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center; font-weight: bold; }
            .details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .detail-row { display: flex; margin-bottom: 12px; align-items: center; }
            .detail-label { font-weight: bold; color: #495057; width: 120px; flex-shrink: 0; }
            .detail-value { color: #212529; }
            .rejection-reason { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .rejection-reason h4 { margin-top: 0; color: #856404; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; border-top: 1px solid #dee2e6; }
            .highlight { color: #dc3545; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="icon">❌</div>
                <h1>Atemschutzeintrag abgelehnt</h1>
            </div>
            <div class="content">
                <div class="rejection-badge">
                    😔 Leider mussten wir Ihren Atemschutzeintrag ablehnen
                </div>
                
                <p>Hallo <strong>' . htmlspecialchars($entry['first_name'] . ' ' . $entry['last_name']) . '</strong>,</p>
                
                <p>wir bedauern, Ihnen mitteilen zu müssen, dass Ihr Atemschutzeintrag nicht genehmigt werden konnte.</p>
                
                <div class="details">
                    <h3 style="margin-top: 0; color: #495057;">📋 Eintragsdetails</h3>
                    <div class="detail-row">
                        <div class="detail-label">🎭 Typ:</div>
                        <div class="detail-value highlight">' . $type_name . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📅 Datum:</div>
                        <div class="detail-value">' . $formatted_date . '</div>
                    </div>
                </div>
                
                <div class="rejection-reason">
                    <h4>🚫 Ablehnungsgrund</h4>
                    <p>' . nl2br(htmlspecialchars($reason)) . '</p>
                </div>
                
                <p>Bitte wenden Sie sich bei Fragen gerne an uns. Wir helfen Ihnen gerne bei der Korrektur des Antrags.</p>
            </div>
            <div class="footer">
                <p><strong>Mit freundlichen Grüßen</strong><br>Ihre Feuerwehr</p>
            </div>
        </div>
    </body>
    </html>';
}
?>
