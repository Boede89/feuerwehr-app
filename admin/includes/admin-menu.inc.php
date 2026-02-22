<?php
/**
 * Admin-Menü (Hamburger-Dropdown) – auf allen Admin-Seiten einblendbar.
 * Setzt Berechtigungen falls nicht vorhanden, gibt Menü-HTML aus.
 */
if (!isset($_SESSION['user_id'])) return;
if (!function_exists('has_permission')) return;

if (!isset($can_reservations)) {
    $u = $user ?? null;
    if (!$u && isset($db)) {
        $st = $db->prepare("SELECT is_admin, can_reservations, can_atemschutz, can_settings, can_members, can_forms FROM users WHERE id = ?");
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
    }
    $is_adm = $u && (!empty($u['is_admin']) || $u['is_admin'] == 1);
    $can_reservations = $is_adm ? true : has_permission('reservations');
    $can_atemschutz = $is_adm ? true : has_permission('atemschutz');
    $can_settings = $is_adm ? true : has_permission('settings');
    $can_members = $is_adm ? true : has_permission('members');
    $can_forms = $is_adm ? true : has_permission('forms');
}
$has_any = $can_reservations || $can_atemschutz || $can_settings || $can_members || $can_forms;
$btn_class = (!empty($admin_menu_in_navbar)) ? 'btn-outline-light' : 'btn-outline-primary';
$base = isset($admin_menu_base) ? $admin_menu_base : '';
$logout_url = isset($admin_menu_logout) ? $admin_menu_logout : '../logout.php';
$index_url = isset($admin_menu_index) ? $admin_menu_index : '../index.php';
?>
<div class="dropdown ms-2" data-bs-boundary="viewport">
    <button class="btn <?php echo $btn_class; ?> btn-sm px-3 py-2 d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" title="Menü öffnen">
        <i class="fas fa-bars"></i>
        <span class="fw-semibold">Menü</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?php echo htmlspecialchars($index_url); ?>"><i class="fas fa-home text-primary me-2"></i>Startseite</a></li>
        <li><a class="dropdown-item" href="<?php echo $base; ?>dashboard.php"><i class="fas fa-tachometer-alt text-primary me-2"></i>Dashboard</a></li>
        <?php if (function_exists('get_accessible_units') && count(get_accessible_units()) > 1): ?>
        <li><a class="dropdown-item" href="<?php echo ($base === 'admin/' || $base === 'admin') ? '../unit-select.php' : 'unit-select.php'; ?>"><i class="fas fa-building me-2"></i>Einheit wechseln</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <?php if ($can_reservations): ?>
        <li><a class="dropdown-item" href="<?php echo $base; ?>reservations.php"><i class="fas fa-calendar text-primary me-2"></i>Reservierungen</a></li>
        <?php endif; ?>
        <?php if ($can_atemschutz): ?>
        <li><a class="dropdown-item" href="<?php echo $base; ?>atemschutz.php"><i class="fas fa-user-shield text-danger me-2"></i>Atemschutz</a></li>
        <?php endif; ?>
        <?php if ($can_members): ?>
        <li><a class="dropdown-item" href="<?php echo $base; ?>members.php"><i class="fas fa-users text-success me-2"></i>Mitgliederverwaltung</a></li>
        <?php endif; ?>
        <?php if ($can_forms): ?>
        <li><a class="dropdown-item" href="<?php echo $base; ?>formularcenter.php"><i class="fas fa-file-alt text-info me-2"></i>Formularcenter</a></li>
        <?php endif; ?>
        <?php if ($can_settings): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?php echo $base; ?>settings.php"><i class="fas fa-cog text-secondary me-2"></i>Einstellungen</a></li>
        <li><a class="dropdown-item" href="<?php echo $base; ?>feedback.php"><i class="fas fa-comment-dots text-info me-2"></i>Feedback</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?php echo $base; ?>profile.php"><i class="fas fa-user-edit me-2"></i>Profil</a></li>
        <li><a class="dropdown-item" href="<?php echo htmlspecialchars($logout_url); ?>"><i class="fas fa-sign-out-alt me-2"></i>Abmelden</a></li>
    </ul>
</div>
