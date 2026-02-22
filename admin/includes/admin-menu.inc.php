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
?>
<div class="dropdown ms-2">
    <button class="btn <?php echo $btn_class; ?> btn-sm px-3 py-2 d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Menü öffnen">
        <i class="fas fa-bars"></i>
        <span class="fw-semibold">Menü</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <?php if ($can_reservations): ?>
        <li><a class="dropdown-item" href="reservations.php"><i class="fas fa-calendar text-primary me-2"></i>Reservierungen</a></li>
        <?php endif; ?>
        <?php if ($can_atemschutz): ?>
        <li><a class="dropdown-item" href="atemschutz.php"><i class="fas fa-user-shield text-danger me-2"></i>Atemschutz</a></li>
        <?php endif; ?>
        <?php if ($can_members): ?>
        <li><a class="dropdown-item" href="members.php"><i class="fas fa-users text-success me-2"></i>Mitgliederverwaltung</a></li>
        <?php endif; ?>
        <?php if ($can_forms): ?>
        <li><a class="dropdown-item" href="formularcenter.php"><i class="fas fa-file-alt text-info me-2"></i>Formularcenter</a></li>
        <?php endif; ?>
        <?php if ($can_settings): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog text-secondary me-2"></i>Einstellungen</a></li>
        <li><a class="dropdown-item" href="feedback.php"><i class="fas fa-comment-dots text-info me-2"></i>Feedback</a></li>
        <li><a class="dropdown-item" href="users.php"><i class="fas fa-user-cog text-secondary me-2"></i>Benutzerverwaltung</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt text-primary me-2"></i>Dashboard</a></li>
        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit me-2"></i>Profil</a></li>
        <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Abmelden</a></li>
    </ul>
</div>
