<?php
/**
 * Admin-Menü (Hamburger-Dropdown) – auf allen Admin-Seiten einblendbar.
 * Setzt Berechtigungen falls nicht vorhanden, gibt Menü-HTML aus.
 */
if (!isset($_SESSION['user_id'])) return;
if (!function_exists('has_permission')) return;
if (isset($db) && file_exists(__DIR__ . '/../../includes/einheiten-setup.php')) {
    require_once __DIR__ . '/../../includes/einheiten-setup.php';
}
$can_switch = function_exists('can_switch_einheit') && can_switch_einheit();

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
    $can_auswertung = $is_adm ? true : has_permission('auswertung');
    $can_forms = $is_adm ? true : has_permission('forms');
    $can_forms_fill = $is_adm ? true : (function_exists('has_form_fill_permission') && has_form_fill_permission());
}
if (!isset($can_auswertung)) {
    $can_auswertung = function_exists('has_permission') && has_permission('auswertung');
}
if (!isset($can_forms_fill)) {
    $can_forms_fill = function_exists('has_form_fill_permission') && has_form_fill_permission();
}
$has_any = $can_reservations || $can_atemschutz || $can_settings || $can_members || $can_auswertung || $can_forms || $can_forms_fill;
$btn_class = (!empty($admin_menu_in_navbar)) ? 'btn-outline-light' : 'btn-outline-primary';
$base = isset($admin_menu_base) ? $admin_menu_base : '';
$dashboard_einheit = function_exists('get_current_einheit_id') ? get_current_einheit_id() : (function_exists('get_current_unit_id') ? get_current_unit_id() : null);
$logout_url = isset($admin_menu_logout) ? $admin_menu_logout : '../logout.php';
$index_url = isset($admin_menu_index) ? $admin_menu_index : '../index.php';
?>
<div class="dropdown ms-2" data-bs-boundary="viewport">
    <button class="btn <?php echo $btn_class; ?> btn-sm px-3 py-2 d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" title="Menü öffnen">
        <i class="fas fa-bars"></i>
        <span class="fw-semibold">Menü</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <?php if ($can_switch): 
            $user_eins = function_exists('get_user_einheiten') ? get_user_einheiten() : [];
            $cur_eid = function_exists('get_current_einheit_id') ? get_current_einheit_id() : null;
        ?>
        <li><h6 class="dropdown-header"><i class="fas fa-sitemap me-2"></i>Einheit wechseln</h6></li>
        <?php foreach ($user_eins as $ue): ?>
        <li><a class="dropdown-item <?php echo ($cur_eid && (int)$ue['id'] === (int)$cur_eid) ? 'active' : ''; ?>" href="<?php echo $base; ?>set-einheit.php?einheit_id=<?php echo (int)$ue['id']; ?>"><?php echo htmlspecialchars($ue['name']); ?></a></li>
        <?php endforeach; ?>
        <li><hr class="dropdown-divider"></li>
        <?php endif; ?>
        <li><a class="dropdown-item" href="<?php echo htmlspecialchars($index_url); ?>"><i class="fas fa-home text-primary me-2"></i>Startseite</a></li>
        <li><a class="dropdown-item" href="<?php echo $base; ?>dashboard.php<?php echo ($dashboard_einheit ?? $cur_eid ?? null) ? '?einheit_id=' . (int)($dashboard_einheit ?? $cur_eid ?? 0) : ''; ?>"><i class="fas fa-tachometer-alt text-primary me-2"></i>Dashboard</a></li>
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
        <?php if ($can_auswertung): ?>
        <li><a class="dropdown-item" href="<?php echo $base; ?>members-auswertung.php"><i class="fas fa-chart-pie text-info me-2"></i>Auswertung</a></li>
        <?php endif; ?>
        <?php if ($can_forms_fill): 
            $ff_eid = function_exists('get_current_einheit_id') ? get_current_einheit_id() : null;
            $ff_einheit = ($ff_eid && (int)$ff_eid > 0) ? '?einheit_id=' . (int)$ff_eid : '';
        ?>
        <li><a class="dropdown-item" href="<?php echo $base; ?>../formulare.php<?php echo $ff_einheit; ?>"><i class="fas fa-edit text-info me-2"></i>Formulare ausfüllen</a></li>
        <?php endif; ?>
        <?php if ($can_forms): 
            $fc_eid = function_exists('get_current_einheit_id') ? get_current_einheit_id() : null;
            $fc_einheit = ($fc_eid && (int)$fc_eid > 0) ? '?einheit_id=' . (int)$fc_eid : '';
        ?>
        <li><a class="dropdown-item" href="<?php echo $base; ?>formularcenter.php<?php echo $fc_einheit; ?>"><i class="fas fa-file-alt text-info me-2"></i>Formularcenter</a></li>
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
