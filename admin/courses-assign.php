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
                
                <label class="form-label d-block mb-2">
                    <i class="fas fa-users"></i> Mitglieder auswählen (nur Mitglieder ohne diesen Lehrgang):
                </label>
                <div id="assignCourseMembersList" class="border rounded p-3" style="max-height: 400px; overflow-y: auto; background-color: #f8f9fa;" role="group" aria-label="Mitglieder auswählen">
                    <p class="text-muted text-center py-3">
                        <i class="fas fa-spinner fa-spin"></i> Lade Mitglieder...
                    </p>
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
    const courseId = document.getElementById('selectedCourseId').value;
    if (!courseId) {
        console.error('Keine course_id gefunden!');
        return;
    }
    
    console.log('Lade Mitglieder für Lehrgangszuweisung (ohne Lehrgang ID ' + courseId + ')...');
    fetch('get-members.php?course_id=' + courseId)
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
                
                if (data.members.length === 0) {
                    container.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Alle Mitglieder haben diesen Lehrgang bereits.</div>';
                    return;
                }
                
                let html = '<div class="row g-2">';
                data.members.forEach(member => {
                    html += '<div class="col-12 col-md-6 col-lg-4">';
                    html += '<div class="card member-select-card h-100">';
                    html += '<div class="card-body p-3">';
                    html += '<div class="form-check h-100 d-flex align-items-center">';
                    html += '<input class="form-check-input me-3" type="checkbox" name="member_ids[]" value="' + member.id + '" id="member_' + member.id + '" autocomplete="off">';
                    html += '<label class="form-check-label flex-grow-1" for="member_' + member.id + '" style="cursor: pointer;">';
                    html += '<div class="d-flex align-items-center">';
                    html += '<div class="member-avatar me-2">';
                    html += '<i class="fas fa-user-circle fa-2x text-primary"></i>';
                    html += '</div>';
                    html += '<div>';
                    html += '<div class="fw-bold">' + member.name + '</div>';
                    if (member.email) {
                        html += '<small class="text-muted"><i class="fas fa-envelope"></i> ' + member.email + '</small>';
                    }
                    html += '</div>';
                    html += '</div>';
                    html += '</label>';
                    html += '</div>';
                    html += '</div>';
                    html += '</div>';
                    html += '</div>';
                });
                html += '</div>';
                container.innerHTML = html;
                console.log('Mitglieder-Cards erstellt:', data.members.length);
            } else {
                const container = document.getElementById('assignCourseMembersList');
                container.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Keine Mitglieder gefunden.</div>';
            }
        })
        .catch(error => {
            console.error('Fehler beim Laden der Mitglieder:', error);
            const container = document.getElementById('assignCourseMembersList');
            container.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Fehler beim Laden der Mitglieder.</div>';
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

<style>
.member-select-card {
    transition: all 0.2s ease;
    border: 2px solid #e9ecef;
    cursor: pointer;
}

.member-select-card:hover {
    border-color: #0d6efd;
    box-shadow: 0 2px 8px rgba(13, 110, 253, 0.15);
    transform: translateY(-2px);
}

.member-select-card .form-check-input:checked ~ label {
    color: #0d6efd;
}

.member-select-card .form-check-input:checked ~ label .member-avatar i {
    color: #0d6efd !important;
}

.member-select-card .form-check-input {
    margin-top: 0.5rem;
}

.member-avatar {
    flex-shrink: 0;
}

#assignCourseMembersList {
    scrollbar-width: thin;
    scrollbar-color: #dee2e6 #f8f9fa;
}

#assignCourseMembersList::-webkit-scrollbar {
    width: 8px;
}

#assignCourseMembersList::-webkit-scrollbar-track {
    background: #f8f9fa;
    border-radius: 4px;
}

#assignCourseMembersList::-webkit-scrollbar-thumb {
    background: #dee2e6;
    border-radius: 4px;
}

#assignCourseMembersList::-webkit-scrollbar-thumb:hover {
    background: #adb5bd;
}
</style>

