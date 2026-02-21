<div class="toast-container position-fixed bottom-0 end-0 p-3" id="printToastContainer"></div>
<script>
function showPrintToast(message, success) {
    var container = document.getElementById('printToastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast align-items-center text-white ' + (success ? 'bg-success' : 'bg-danger') + ' border-0';
    toast.setAttribute('role', 'alert');
    toast.innerHTML = '<div class="d-flex"><div class="toast-body">' + (message || '').replace(/</g, '&lt;') + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    container.appendChild(toast);
    var bsToast = new bootstrap.Toast(toast, { delay: 4000 });
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', function() { toast.remove(); });
}
</script>
