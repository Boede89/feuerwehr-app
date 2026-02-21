<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 9999;" id="printToastContainer"></div>
<script>
function showPrintToast(message, success) {
    var container = document.getElementById('printToastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast align-items-center text-white ' + (success ? 'bg-success' : 'bg-danger') + ' border-0 shadow-lg';
    toast.setAttribute('role', 'alert');
    var icon = success ? '<i class="fas fa-check-circle me-2"></i>' : '<i class="fas fa-exclamation-circle me-2"></i>';
    toast.innerHTML = '<div class="d-flex"><div class="toast-body d-flex align-items-center">' + icon + '<strong>' + (success ? 'Erfolg: ' : 'Fehler: ') + '</strong>' + (message || '').replace(/</g, '&lt;') + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    container.appendChild(toast);
    var bsToast = new bootstrap.Toast(toast, { delay: 5000 });
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', function() { toast.remove(); });
}
</script>
