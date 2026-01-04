<!-- Lehrgang hinterlegen -->
<div class="card">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Lehrgang hinterlegen</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" id="assignCourseForm">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="assign_course" value="1">
            <input type="hidden" name="course_id" id="selectedCourseId" value="" autocomplete="off">
            
            <div class="mb-3">
                <label class="form-label d-block">Lehrgang auswählen:</label>
                <div class="d-flex flex-wrap gap-2" id="courseButtonsForAssign" role="group" aria-label="Lehrgang auswählen">
                    <?php foreach ($courses as $course): ?>
                        <button type="button" class="btn btn-outline-success course-assign-btn" data-course-id="<?php echo $course['id']; ?>" onclick="selectCourseForAssign(<?php echo $course['id']; ?>, '<?php echo htmlspecialchars($course['name'], ENT_QUOTES); ?>')" aria-label="Lehrgang <?php echo htmlspecialchars($course['name']); ?> auswählen">
                            <?php echo htmlspecialchars($course['name']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div id="selectedCourseName" class="mt-2" style="display: none;">
                    <span class="badge bg-success">Ausgewählt: <span id="selectedCourseNameText"></span></span>
                </div>
            </div>
            
            <div id="assignCourseMembers" style="display: none;">
                <div class="mb-3">
                    <label for="completionYear" class="form-label">Abschlussjahr:</label>
                    <select class="form-select" id="completionYear" name="completion_year" required autocomplete="off">
                        <option value="">Bitte wählen...</option>
                        <?php
                        $currentYear = (int)date('Y');
                        for ($year = $currentYear; $year >= 1950; $year--) {
                            echo '<option value="' . $year . '">' . $year . '</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <label class="form-label d-block">Mitglieder auswählen:</label>
                <div id="assignCourseMembersList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;" role="group" aria-label="Mitglieder auswählen">
                    <p class="text-muted">Lade Mitglieder...</p>
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-success" id="saveCourseAssignBtn" disabled>
                    <i class="fas fa-save"></i> Speichern
                </button>
                <a href="courses.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>

<script>
let selectedCourseForAssign = null;

function selectCourseForAssign(courseId, courseName) {
    console.log('selectCourseForAssign aufgerufen:', courseId, courseName);
    selectedCourseForAssign = courseId;
    const courseIdInput = document.getElementById('selectedCourseId');
    if (!courseIdInput) {
        console.error('selectedCourseId Input nicht gefunden!');
        return;
    }
    courseIdInput.value = courseId;
    console.log('course_id Input gesetzt auf:', courseIdInput.value);
    document.getElementById('selectedCourseNameText').textContent = courseName;
    document.getElementById('selectedCourseName').style.display = 'block';
    document.getElementById('assignCourseMembers').style.display = 'block';
    document.getElementById('saveCourseAssignBtn').disabled = false;
    
    // Button-Stile aktualisieren
    document.querySelectorAll('.course-assign-btn').forEach(btn => {
        if (parseInt(btn.dataset.courseId) === courseId) {
            btn.classList.add('active');
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-success');
        } else {
            btn.classList.remove('active');
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-success');
        }
    });
    
    loadMembersForCourseAssignment();
}

function loadMembersForCourseAssignment() {
    console.log('Lade Mitglieder für Lehrgangszuweisung...');
    fetch('get-members.php')
        .then(response => {
            console.log('Response Status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Mitglieder-Daten erhalten:', data);
            if (data.success && data.members) {
                const container = document.getElementById('assignCourseMembersList');
                const form = document.getElementById('assignCourseForm');
                if (!form) {
                    console.error('Formular nicht gefunden!');
                    return;
                }
                let html = '';
                data.members.forEach(member => {
                    html += '<div class="form-check">';
                    html += '<input class="form-check-input" type="checkbox" name="member_ids[]" value="' + member.id + '" id="member_' + member.id + '" autocomplete="off">';
                    html += '<label class="form-check-label" for="member_' + member.id + '">' + member.name + '</label>';
                    html += '</div>';
                });
                container.innerHTML = html;
                console.log('Mitglieder-Checkboxen erstellt:', data.members.length);
            } else {
                const container = document.getElementById('assignCourseMembersList');
                container.innerHTML = '<p class="text-muted">Keine Mitglieder gefunden.</p>';
            }
        })
        .catch(error => {
            console.error('Fehler beim Laden der Mitglieder:', error);
            const container = document.getElementById('assignCourseMembersList');
            container.innerHTML = '<p class="text-danger">Fehler beim Laden der Mitglieder.</p>';
        });
}

// Formular-Validierung vor dem Absenden
document.getElementById('assignCourseForm')?.addEventListener('submit', function(e) {
    console.log('=== SUBMIT EVENT AUSGELÖST ===');
    const courseId = document.getElementById('selectedCourseId').value;
    const memberCheckboxes = document.querySelectorAll('#assignCourseMembersList input[type="checkbox"][name="member_ids[]"]:checked');
    
    console.log('=== FORMULAR WIRD ABGESENDET ===');
    console.log('courseId:', courseId);
    console.log('Anzahl ausgewählter Mitglieder:', memberCheckboxes.length);
    
    if (!courseId || courseId === '') {
        e.preventDefault();
        alert('Bitte wählen Sie einen Lehrgang aus.');
        return false;
    }
    
    if (memberCheckboxes.length === 0) {
        e.preventDefault();
        alert('Bitte wählen Sie mindestens ein Mitglied aus.');
        return false;
    }
    
    const completionYear = document.getElementById('completionYear').value;
    if (!completionYear || completionYear === '') {
        e.preventDefault();
        alert('Bitte wählen Sie ein Abschlussjahr aus.');
        return false;
    }
    
    // Button deaktivieren während der Übertragung
    const submitBtn = document.getElementById('saveCourseAssignBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Wird gespeichert...';
    
    console.log('Formular wird jetzt abgesendet...');
});
</script>

