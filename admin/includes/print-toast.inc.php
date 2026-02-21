<!-- Toast-Benachrichtigung für Druckaufträge -->
<style>
@keyframes printToastSlideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.print-toast {
    animation: printToastSlideIn 0.3s ease;
    min-width: 300px;
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
    border: none;
}
</style>
<script>
function showPrintToast(msg, success) {
    var div = document.createElement('div');
    div.className = 'alert alert-' + (success ? 'success' : 'danger') + ' position-fixed top-0 end-0 m-3 print-toast d-flex align-items-center';
    div.style.zIndex = '9999';
    div.setAttribute('role', 'alert');
    div.innerHTML = '<i class="fas fa-' + (success ? 'check-circle' : 'exclamation-circle') + ' me-2 fs-5"></i><span>' + (msg || '').replace(/</g, '&lt;') + '</span>';
    document.body.appendChild(div);
    setTimeout(function() {
        div.style.opacity = '0';
        div.style.transition = 'opacity 0.3s ease';
        setTimeout(function() { div.remove(); }, 300);
    }, 3500);
}
</script>
