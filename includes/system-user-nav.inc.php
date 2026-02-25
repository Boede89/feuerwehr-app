<?php
/**
 * Navbar für Systembenutzer: "Als Benutzer anmelden" Link.
 * Wird eingebunden, wenn ein Systembenutzer eingeloggt ist.
 */
if (!isset($_SESSION['user_id']) || !is_system_user()) return;
$su_login_url = (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false) ? '../login.php' : 'login.php';
?>
<div class="d-flex ms-auto align-items-center gap-2">
    <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="<?php echo htmlspecialchars($su_login_url); ?>?as_user=1">
        <i class="fas fa-sign-in-alt"></i>
        <span class="fw-semibold">Als Benutzer anmelden</span>
    </a>
</div>
