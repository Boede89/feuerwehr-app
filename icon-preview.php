<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Icon-Vorschau - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .icon-preview {
            position: relative;
            display: inline-block;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #dc3545, #c82333);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 10px;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .icon-preview:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }
        
        .icon-preview i {
            font-size: 3rem;
            color: white;
        }
        
        .icon-name {
            text-align: center;
            margin-top: 5px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h1 class="text-center mb-5">🔥 Feuerwehr Icon-Vorschau</h1>
        
        <div class="row">
            <div class="col-12">
                <h3>Feuerwehr/Atemschutz Icons:</h3>
                <div class="d-flex flex-wrap justify-content-center">
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-hard-hat"></i>
                        </div>
                        <div class="icon-name">fa-hard-hat</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-fire-extinguisher"></i>
                        </div>
                        <div class="icon-name">fa-fire-extinguisher</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-head-side-mask"></i>
                        </div>
                        <div class="icon-name">fa-head-side-mask</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="icon-name">fa-truck</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="icon-name">fa-shield-alt</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-fire"></i>
                        </div>
                        <div class="icon-name">fa-fire</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-lungs"></i>
                        </div>
                        <div class="icon-name">fa-lungs</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="icon-name">fa-user-shield (aktuell)</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-5">
            <div class="col-12">
                <h3>Weitere Optionen:</h3>
                <div class="d-flex flex-wrap justify-content-center">
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-helmet-safety"></i>
                        </div>
                        <div class="icon-name">fa-helmet-safety</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-mask"></i>
                        </div>
                        <div class="icon-name">fa-mask (ursprünglich)</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-user-ninja"></i>
                        </div>
                        <div class="icon-name">fa-user-ninja</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="icon-preview">
                            <i class="fas fa-user-astronaut"></i>
                        </div>
                        <div class="icon-name">fa-user-astronaut</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-5">
            <div class="col-12 text-center">
                <h4>Wie gefällt Ihnen ein Icon?</h4>
                <p>Klicken Sie auf ein Icon, um es in der Anwendung zu verwenden!</p>
                <a href="index.php" class="btn btn-primary">Zurück zur Startseite</a>
            </div>
        </div>
    </div>
    
    <script>
        // Icon-Klick-Handler
        document.querySelectorAll('.icon-preview').forEach(icon => {
            icon.addEventListener('click', function() {
                const iconClass = this.querySelector('i').className;
                const iconName = this.nextElementSibling.textContent;
                
                if (confirm(`Möchten Sie "${iconName}" als neues Icon verwenden?`)) {
                    // Hier würde das Icon geändert werden
                    alert(`Icon "${iconName}" wurde ausgewählt!`);
                }
            });
        });
    </script>
</body>
</html>

