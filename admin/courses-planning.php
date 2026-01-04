<!-- Lehrgangsplanung -->
<div class="card">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Lehrgangsplanung</h5>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label d-block">Lehrgang auswählen:</label>
            <div class="d-flex flex-wrap gap-2" id="courseButtonsForPlanning" role="group" aria-label="Lehrgang auswählen">
                <?php foreach ($courses as $course): ?>
                    <button type="button" class="btn btn-outline-info course-planning-btn" data-course-id="<?php echo $course['id']; ?>" onclick="selectCourseForPlanning(<?php echo $course['id']; ?>, '<?php echo htmlspecialchars($course['name'], ENT_QUOTES); ?>')" aria-label="Lehrgang <?php echo htmlspecialchars($course['name']); ?> auswählen">
                        <?php echo htmlspecialchars($course['name']); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div id="selectedCourseNamePlanning" class="mt-2" style="display: none;">
                <span class="badge bg-info">Ausgewählt: <span id="selectedCourseNamePlanningText"></span></span>
            </div>
        </div>
        
        <div id="planningMembersSection" style="display: none;">
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="checkRequirements" checked onchange="loadMembersForPlanning()">
                    <label class="form-check-label" for="checkRequirements">
                        Voraussetzungen erfüllt
                    </label>
                </div>
            </div>
            
            <label class="form-label d-block mb-2">
                <i class="fas fa-users"></i> Mitglieder (ohne diesen Lehrgang):
            </label>
            <div id="planningMembersList" class="border rounded p-3" style="max-height: 500px; overflow-y: auto; background-color: #f8f9fa;">
                <p class="text-muted text-center py-3">
                    <i class="fas fa-spinner fa-spin"></i> Lade Mitglieder...
                </p>
            </div>
        </div>
    </div>
</div>

<script>
let selectedCourseForPlanning = null;

function selectCourseForPlanning(courseId, courseName) {
    selectedCourseForPlanning = courseId;
    document.getElementById('selectedCourseNamePlanningText').textContent = courseName;
    document.getElementById('selectedCourseNamePlanning').style.display = 'block';
    document.getElementById('planningMembersSection').style.display = 'block';
    
    // Button-Stile aktualisieren
    document.querySelectorAll('.course-planning-btn').forEach(btn => {
        if (parseInt(btn.dataset.courseId) === courseId) {
            btn.classList.add('active');
            btn.classList.remove('btn-outline-info');
            btn.classList.add('btn-info');
        } else {
            btn.classList.remove('active');
            btn.classList.remove('btn-info');
            btn.classList.add('btn-outline-info');
        }
    });
    
    loadMembersForPlanning();
}

function loadMembersForPlanning() {
    if (!selectedCourseForPlanning) {
        return;
    }
    
    const checkRequirements = document.getElementById('checkRequirements').checked;
    
    console.log('Lade Mitglieder für Lehrgangsplanung (course_id: ' + selectedCourseForPlanning + ', checkRequirements: ' + checkRequirements + ')');
    
    fetch('get-members-for-planning.php?course_id=' + selectedCourseForPlanning + '&check_requirements=' + (checkRequirements ? '1' : '0'))
        .then(response => {
            console.log('Response Status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Mitglieder-Daten erhalten:', data);
            if (data.success && data.members) {
                const container = document.getElementById('planningMembersList');
                if (data.members.length === 0) {
                    container.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Keine Mitglieder gefunden.</div>';
                    return;
                }
                
                let html = '<table class="table table-striped table-hover">';
                html += '<thead><tr><th>Mitglied</th><th>Voraussetzungen</th></tr></thead><tbody>';
                
                data.members.forEach(member => {
                    html += '<tr>';
                    html += '<td>' + member.name + '</td>';
                    html += '<td>';
                    
                    if (member.requirements_met) {
                        html += '<span class="text-success"><i class="fas fa-check-circle"></i> Erfüllt</span>';
                    } else {
                        html += '<span class="text-danger"><i class="fas fa-times-circle"></i> Nicht erfüllt</span>';
                        if (member.missing_courses && member.missing_courses.length > 0) {
                            html += '<div class="mt-1"><small class="text-muted">Fehlende Lehrgänge: ';
                            html += member.missing_courses.map(c => c.name).join(', ');
                            html += '</small></div>';
                        }
                    }
                    
                    html += '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
                container.innerHTML = html;
                console.log('Mitglieder-Tabelle erstellt:', data.members.length);
            } else {
                const container = document.getElementById('planningMembersList');
                container.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Keine Mitglieder gefunden.</div>';
            }
        })
        .catch(error => {
            console.error('Fehler beim Laden der Mitglieder:', error);
            const container = document.getElementById('planningMembersList');
            container.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Fehler beim Laden der Mitglieder.</div>';
        });
}
</script>

<style>
#planningMembersList {
    scrollbar-width: thin;
    scrollbar-color: #dee2e6 #f8f9fa;
}

#planningMembersList::-webkit-scrollbar {
    width: 8px;
}

#planningMembersList::-webkit-scrollbar-track {
    background: #f8f9fa;
    border-radius: 4px;
}

#planningMembersList::-webkit-scrollbar-thumb {
    background: #dee2e6;
    border-radius: 4px;
}

#planningMembersList::-webkit-scrollbar-thumb:hover {
    background: #adb5bd;
}
</style>
