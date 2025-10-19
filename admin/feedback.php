<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Admin-Berechtigung prüfen
if (!hasAdminPermission()) {
    header('Location: ../login.php');
    exit;
}

// Feedback-Status aktualisieren
if ($_POST['action'] ?? '' === 'update_status') {
    $feedback_id = (int)($_POST['feedback_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $admin_notes = sanitize_input($_POST['admin_notes'] ?? '');
    
    if ($feedback_id > 0 && in_array($status, ['new', 'in_progress', 'resolved', 'closed'])) {
        try {
            $stmt = $db->prepare("UPDATE feedback SET status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $admin_notes, $feedback_id]);
            $success_message = "Status erfolgreich aktualisiert";
        } catch (Exception $e) {
            $error_message = "Fehler beim Aktualisieren: " . $e->getMessage();
        }
    }
}

// Feedback abrufen
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if ($type_filter !== 'all') {
    $where_conditions[] = "feedback_type = ?";
    $params[] = $type_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$sql = "
    SELECT f.*, u.username, u.first_name, u.last_name 
    FROM feedback f 
    LEFT JOIN users u ON f.user_id = u.id 
    $where_clause 
    ORDER BY f.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$feedback_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiken
$stats_sql = "SELECT status, COUNT(*) as count FROM feedback GROUP BY status";
$stats_stmt = $db->query($stats_sql);
$stats = $stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback-Verwaltung - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a class="nav-link" href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Abmelden
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h2><i class="fas fa-comment-dots"></i> Feedback-Verwaltung</h2>
                
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                
                <!-- Statistiken -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-primary"><?php echo $stats['new'] ?? 0; ?></h5>
                                <p class="card-text">Neue</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-warning"><?php echo $stats['in_progress'] ?? 0; ?></h5>
                                <p class="card-text">In Bearbeitung</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-success"><?php echo $stats['resolved'] ?? 0; ?></h5>
                                <p class="card-text">Gelöst</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-secondary"><?php echo $stats['closed'] ?? 0; ?></h5>
                                <p class="card-text">Geschlossen</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filter -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Alle</option>
                                    <option value="new" <?php echo $status_filter === 'new' ? 'selected' : ''; ?>>Neu</option>
                                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Bearbeitung</option>
                                    <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Gelöst</option>
                                    <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Geschlossen</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="type" class="form-label">Typ</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>Alle</option>
                                    <option value="bug" <?php echo $type_filter === 'bug' ? 'selected' : ''; ?>>Fehler</option>
                                    <option value="feature" <?php echo $type_filter === 'feature' ? 'selected' : ''; ?>>Funktionswunsch</option>
                                    <option value="improvement" <?php echo $type_filter === 'improvement' ? 'selected' : ''; ?>>Verbesserung</option>
                                    <option value="general" <?php echo $type_filter === 'general' ? 'selected' : ''; ?>>Allgemein</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-filter"></i> Filtern
                                </button>
                                <a href="feedback.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Zurücksetzen
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Feedback-Liste -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($feedback_list)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-comment-slash fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Kein Feedback gefunden</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Typ</th>
                                            <th>Betreff</th>
                                            <th>Benutzer</th>
                                            <th>Status</th>
                                            <th>Datum</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($feedback_list as $feedback): ?>
                                            <tr>
                                                <td>#<?php echo $feedback['id']; ?></td>
                                                <td>
                                                    <?php
                                                    $type_labels = [
                                                        'bug' => '<span class="badge bg-danger">Fehler</span>',
                                                        'feature' => '<span class="badge bg-info">Funktion</span>',
                                                        'improvement' => '<span class="badge bg-warning">Verbesserung</span>',
                                                        'general' => '<span class="badge bg-secondary">Allgemein</span>'
                                                    ];
                                                    echo $type_labels[$feedback['feedback_type']] ?? $feedback['feedback_type'];
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($feedback['subject']); ?></td>
                                                <td>
                                                    <?php if ($feedback['username']): ?>
                                                        <?php echo htmlspecialchars($feedback['first_name'] . ' ' . $feedback['last_name']); ?>
                                                        <br><small class="text-muted">@<?php echo htmlspecialchars($feedback['username']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Gast</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_labels = [
                                                        'new' => '<span class="badge bg-primary">Neu</span>',
                                                        'in_progress' => '<span class="badge bg-warning">In Bearbeitung</span>',
                                                        'resolved' => '<span class="badge bg-success">Gelöst</span>',
                                                        'closed' => '<span class="badge bg-secondary">Geschlossen</span>'
                                                    ];
                                                    echo $status_labels[$feedback['status']] ?? $feedback['status'];
                                                    ?>
                                                </td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($feedback['created_at'])); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#feedbackModal<?php echo $feedback['id']; ?>">
                                                        <i class="fas fa-eye"></i> Anzeigen
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Feedback-Detail-Modals -->
    <?php foreach ($feedback_list as $feedback): ?>
        <div class="modal fade" id="feedbackModal<?php echo $feedback['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Feedback #<?php echo $feedback['id']; ?> - 
                            <?php echo htmlspecialchars($feedback['subject']); ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Typ:</strong> 
                                <?php echo $type_labels[$feedback['feedback_type']] ?? $feedback['feedback_type']; ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Status:</strong> 
                                <?php echo $status_labels[$feedback['status']] ?? $feedback['status']; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Nachricht:</strong>
                            <div class="border p-3 mt-2 bg-light">
                                <?php echo nl2br(htmlspecialchars($feedback['message'])); ?>
                            </div>
                        </div>
                        
                        <?php if ($feedback['email']): ?>
                            <div class="mb-3">
                                <strong>E-Mail für Rückfragen:</strong> 
                                <a href="mailto:<?php echo htmlspecialchars($feedback['email']); ?>">
                                    <?php echo htmlspecialchars($feedback['email']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Eingereicht am:</strong> 
                                <?php echo date('d.m.Y H:i:s', strtotime($feedback['created_at'])); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Letzte Änderung:</strong> 
                                <?php echo date('d.m.Y H:i:s', strtotime($feedback['updated_at'])); ?>
                            </div>
                        </div>
                        
                        <?php if ($feedback['admin_notes']): ?>
                            <div class="mb-3">
                                <strong>Admin-Notizen:</strong>
                                <div class="border p-3 mt-2 bg-light">
                                    <?php echo nl2br(htmlspecialchars($feedback['admin_notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="feedback_id" value="<?php echo $feedback['id']; ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="status<?php echo $feedback['id']; ?>" class="form-label">Status ändern</label>
                                    <select class="form-select" id="status<?php echo $feedback['id']; ?>" name="status">
                                        <option value="new" <?php echo $feedback['status'] === 'new' ? 'selected' : ''; ?>>Neu</option>
                                        <option value="in_progress" <?php echo $feedback['status'] === 'in_progress' ? 'selected' : ''; ?>>In Bearbeitung</option>
                                        <option value="resolved" <?php echo $feedback['status'] === 'resolved' ? 'selected' : ''; ?>>Gelöst</option>
                                        <option value="closed" <?php echo $feedback['status'] === 'closed' ? 'selected' : ''; ?>>Geschlossen</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="admin_notes<?php echo $feedback['id']; ?>" class="form-label">Admin-Notizen</label>
                                    <textarea class="form-control" id="admin_notes<?php echo $feedback['id']; ?>" name="admin_notes" rows="3" placeholder="Interne Notizen..."><?php echo htmlspecialchars($feedback['admin_notes']); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Status aktualisieren
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
