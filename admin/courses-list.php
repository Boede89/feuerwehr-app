<!-- Liste anzeigen -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-list"></i> Liste anzeigen</h5>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label">Liste anzeigen:</label>
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary" id="btnListByName" onclick="selectListType('name')">
                    <i class="fas fa-user"></i> Nach Namen anzeigen
                </button>
                <button type="button" class="btn btn-outline-primary" id="btnListByCourse" onclick="selectListType('course')">
                    <i class="fas fa-graduation-cap"></i> Nach Lehrgang anzeigen
                </button>
            </div>
        </div>
        
        <div id="courseListByName" style="display: none;">
            <h6>Mitglieder mit absolvierten Lehrgängen</h6>
            <div id="courseListByNameContent">
                <p class="text-muted">Lade Daten...</p>
            </div>
        </div>
        
        <div id="courseListByCourse" style="display: none;">
            <h6>Mitglieder nach Lehrgang</h6>
            <div class="mb-3">
                <label class="form-label">Lehrgang auswählen:</label>
                <div class="d-flex flex-wrap gap-2" id="courseButtonsForList" role="group" aria-label="Lehrgang auswählen">
                    <?php foreach ($courses as $course): ?>
                        <button type="button" class="btn btn-outline-success course-select-btn" data-course-id="<?php echo $course['id']; ?>" onclick="selectCourseForList(<?php echo $course['id']; ?>)" aria-label="Lehrgang <?php echo htmlspecialchars($course['name']); ?> auswählen">
                            <?php echo htmlspecialchars($course['name']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="courseListByCourseContent">
                <p class="text-muted">Bitte wählen Sie einen Lehrgang aus.</p>
            </div>
        </div>
    </div>
</div>

<script>
let selectedListType = '';
let selectedCourseForList = null;

function selectListType(type) {
    selectedListType = type;
    document.getElementById('courseListByName').style.display = (type === 'name') ? 'block' : 'none';
    document.getElementById('courseListByCourse').style.display = (type === 'course') ? 'block' : 'none';
    
    // Button-Stile aktualisieren
    document.getElementById('btnListByName').classList.toggle('active', type === 'name');
    document.getElementById('btnListByCourse').classList.toggle('active', type === 'course');
    
    if (type === 'name') {
        loadCourseListByName();
    } else if (type === 'course') {
        selectedCourseForList = null;
        document.getElementById('courseListByCourseContent').innerHTML = '<p class="text-muted">Bitte wählen Sie einen Lehrgang aus.</p>';
    }
}

function selectCourseForList(courseId) {
    selectedCourseForList = courseId;
    
    // Button-Stile aktualisieren
    document.querySelectorAll('.course-select-btn').forEach(btn => {
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
    
    loadCourseListByCourse();
}

function loadCourseListByName() {
    fetch('get-member-courses.php?type=by_name')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const container = document.getElementById('courseListByNameContent');
                if (data.members && data.members.length > 0) {
                    let html = '<table class="table table-striped"><thead><tr><th>Mitglied</th><th>Lehrgänge</th></tr></thead><tbody>';
                            data.members.forEach(member => {
                                html += '<tr><td>' + member.name + '</td><td>';
                                if (member.courses && member.courses.length > 0) {
                                    member.courses.forEach(course => {
                                        html += '<span class="badge bg-primary me-1">' + course.name + '</span>';
                                    });
                                } else {
                                    html += '<span class="text-muted">Keine Lehrgänge</span>';
                                }
                                html += '</td></tr>';
                            });
                    html += '</tbody></table>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p class="text-muted">Keine Mitglieder gefunden.</p>';
                }
            }
        })
        .catch(error => {
            console.error('Fehler beim Laden der Liste:', error);
        });
}

function loadCourseListByCourse() {
    if (!selectedCourseForList) {
        document.getElementById('courseListByCourseContent').innerHTML = '<p class="text-muted">Bitte wählen Sie einen Lehrgang aus.</p>';
        return;
    }
    
    fetch('get-member-courses.php?type=by_course&course_id=' + selectedCourseForList)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const container = document.getElementById('courseListByCourseContent');
                if (data.members && data.members.length > 0) {
                    let html = '<table class="table table-striped"><thead><tr><th>Mitglied</th><th>Abschlussjahr</th></tr></thead><tbody>';
                    data.members.forEach(member => {
                        const year = member.year || (member.completed_date ? member.completed_date.substring(0, 4) : '');
                        html += '<tr><td>' + member.name + '</td><td>' + (year || '-') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p class="text-muted">Keine Mitglieder mit diesem Lehrgang gefunden.</p>';
                }
            }
        })
        .catch(error => {
            console.error('Fehler beim Laden der Liste:', error);
        });
}
</script>

