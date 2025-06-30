<?php
session_start();

// Function to validate password security
function isPasswordSecure($password) {
    // Minimum 8 characters
    if (strlen($password) < 8) {
        return false;
    }
    
    // At least one lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }
    
    // At least one uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }
    
    // At least one number
    if (!preg_match('/\d/', $password)) {
        return false;
    }
    
    // At least one special character
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        return false;
    }
    
    return true;
}

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

// Handle login form submission
if ($_POST['action'] === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    include 'config/database.php';
    
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE habbo_username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: dashboard.php');
            exit();
        } else {
            $login_error = "Credenciales inválidas";
        }
    } else {
        $login_error = "Por favor completa todos los campos";
    }
}

// Handle register form submission
if (isset($_POST['action']) && $_POST['action'] === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    include 'config/database.php';
    
    $habbo_username = trim($_POST['habbo_username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $verification_code = trim($_POST['verification_code'] ?? '');
    $habbo_verified = $_POST['habbo_verified'] ?? '';
    
    // Debug information
    error_log("Registration attempt - Data received: " . json_encode([
        'habbo_username' => $habbo_username,
        'verification_code' => $verification_code,
        'habbo_verified' => $habbo_verified,
        'password_length' => strlen($password),
        'confirmPassword_length' => strlen($confirmPassword)
    ]));
    
    // Validate all required fields
    if (empty($habbo_username)) {
        $register_error = "El campo nombre de Habbo es requerido";
    } elseif (empty($password)) {
        $register_error = "El campo contraseña es requerido";
    } elseif (empty($confirmPassword)) {
        $register_error = "El campo confirmar contraseña es requerido";
    } elseif ($password !== $confirmPassword) {
        $register_error = "Las contraseñas no coinciden";
    } elseif (!isPasswordSecure($password)) {
        $register_error = "La contraseña debe tener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y caracteres especiales";
    } elseif ($habbo_verified !== 'true') {
        $register_error = "Debes verificar tu cuenta de Habbo antes de continuar";
    } elseif (empty($verification_code)) {
        $register_error = "Código de verificación no válido";
    } else {
        try {
            // Check if user already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE habbo_username = ?");
            $stmt->execute([$habbo_username]);
            
            if ($stmt->fetch()) {
                $register_error = "Este nombre de Habbo ya está registrado";
            } else {
                // Create new user
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, habbo_username, created_at) VALUES (?, ?, ?, NOW())");
                
                if ($stmt->execute([$habbo_username, $hashedPassword, $habbo_username])) {
                    $user_id = $pdo->lastInsertId();
                    
                    // Log successful registration
                    error_log("User registered successfully with ID: " . $user_id);
                    
                    // Auto login after successful registration
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $habbo_username;
                    
                    // Redirect to dashboard
                    header('Location: dashboard.php');
                    exit();
                } else {
                    $register_error = "Error al crear la cuenta en la base de datos";
                    error_log("Database error during user creation: " . json_encode($stmt->errorInfo()));
                }
            }
        } catch (Exception $e) {
            $register_error = "Error del sistema: " . $e->getMessage();
            error_log("Exception during registration: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Habbo Agency - Portal de Acceso</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <!-- Floating Logo Animation -->
    <div id="floatingLogo" class="floating-logo">
        <?php 
        // Get company logo from database
        try {
            include_once 'config/database.php';
            $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'company_logo'");
            $stmt->execute();
            $company_logo = $stmt->fetchColumn();
            
            if ($company_logo && file_exists($company_logo)) {
                echo '<img src="' . htmlspecialchars($company_logo) . '" alt="Company Logo" class="logo-image">';
            } else {
                echo '<div class="logo-placeholder"><i class="fas fa-hotel"></i></div>';
            }
        } catch (Exception $e) {
            echo '<div class="logo-placeholder"><i class="fas fa-hotel"></i></div>';
        }
        ?>
    </div>

    <div class="glass-modal" id="mainInterface">
        <h1 class="title">
            <?php 
            // Get site title from database
            try {
                $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'site_title'");
                $stmt->execute();
                $site_title = $stmt->fetchColumn() ?: 'Habbo Agency';
                echo '<i class="fas fa-hotel"></i>' . htmlspecialchars($site_title);
            } catch (Exception $e) {
                echo '<i class="fas fa-hotel"></i>Habbo Agency';
            }
            ?>
        </h1>
        <p class="subtitle">
            Tu portal de acceso a la experiencia
        </p>
        
        <div class="button-container">
            <button class="glass-button" onclick="openModal('loginModal')">
                <i class="fas fa-sign-in-alt"></i>
                Iniciar Sesión
            </button>
            
            <button class="glass-button" onclick="openModal('registerModal')">
                <i class="fas fa-user-plus"></i>
                Registrarse
            </button>
        </div>
    </div>

    <!-- Login Modal -->
    <div id="loginModal" class="modal-overlay" style="display: none;">
        <div class="form-modal">
            <button class="close-btn" onclick="closeModal('loginModal')">
                <i class="fas fa-times"></i>
            </button>
            
            <h2 class="modal-title">
                <i class="fas fa-sign-in-alt"></i>
                Iniciar Sesión
            </h2>
            
            <?php if (isset($login_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label>Nombre de Habbo</label>
                    <input type="text" name="username" placeholder="Tu nombre de Habbo" class="glass-input" required>
                </div>
                
                <div class="form-group">
                    <label>Contraseña</label>
                    <input type="password" name="password" placeholder="Ingresa tu contraseña" class="glass-input" required>
                </div>
                
                <div class="button-center">
                    <button type="submit" class="glass-button submit-btn">
                        <i class="fas fa-sign-in-alt"></i>
                        Entrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Habbo Verification Modal -->
    <div id="verificationModal" class="modal-overlay" style="display: none;">
        <div class="form-modal verification-modal">
            <button class="close-btn" onclick="closeModal('verificationModal')">
                <i class="fas fa-times"></i>
            </button>
            
            <h2 class="modal-title">
                <i class="fas fa-user-shield"></i>
                Verificación de Habbo
            </h2>
            
            <div class="verification-content">
                <div class="verification-info">
                    <p class="verification-description">
                        Para verificar tu cuenta de Habbo, necesitas colocar el siguiente código en tu misión y luego hacer clic en verificar.
                    </p>
                </div>
                
                <div class="form-group">
                    <label>Tu nombre de Habbo</label>
                    <input type="text" id="verification_habbo_username" placeholder="Nombre de Habbo" class="glass-input" readonly>
                </div>
                
                <div class="form-group">
                    <label>Código de verificación</label>
                    <div class="verification-code-container">
                        <div class="verification-code-display" id="display_code"></div>
                        <button type="button" class="refresh-code-btn" id="generate_new_code_btn" title="Generar nuevo código">
                            <i class="fas fa-redo"></i>
                        </button>
                    </div>
                    <p class="verification-instructions">
                        <i class="fas fa-info-circle"></i>
                        Copia este código y colócalo en tu misión de Habbo
                    </p>
                </div>
                
                <div class="verification-timer">
                    <i class="fas fa-clock"></i>
                    Código válido por: <span id="timer">10:00</span>
                </div>
                
                <div id="verification_messages" class="verification-messages"></div>
                
                <div class="button-center">
                    <button type="button" class="glass-button" id="verify_mission_btn">
                        <i class="fas fa-check-circle"></i>
                        Verificar misión
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div id="registerModal" class="modal-overlay" style="display: none;">
        <div class="form-modal">
            <button class="close-btn" onclick="closeModal('registerModal')">
                <i class="fas fa-times"></i>
            </button>
            
            <h2 class="modal-title">
                <i class="fas fa-user-plus"></i>
                Registrarse
            </h2>
            
            <?php if (isset($register_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($register_error); ?></div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form" id="registerForm">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="verification_code" id="verification_code">
                <input type="hidden" name="habbo_verified" id="habbo_verified" value="false">
                
                <div class="form-group">
                    <label>Nombre de usuario en Habbo</label>
                    <input type="text" name="habbo_username" id="habbo_username" placeholder="Tu nombre en Habbo" class="glass-input" required>
                </div>
                
                <div id="verification_status" class="verification-status-display" style="display: none;">
                    <div class="status-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <p class="status-text">Cuenta de Habbo verificada correctamente</p>
                </div>
                
                <div class="form-group">
                    <label>Contraseña</label>
                    <input type="password" name="password" placeholder="Crea una contraseña segura" class="glass-input" required>
                </div>
                
                <div class="form-group">
                    <label>Confirmar Contraseña</label>
                    <input type="password" name="confirmPassword" placeholder="Confirma tu contraseña" class="glass-input" required>
                </div>
                
                <div class="button-center">
                    <button type="button" class="glass-button" id="start_verification_btn">
                        <i class="fas fa-user-shield"></i>
                        Verificar Habbo
                    </button>
                    <button type="submit" class="glass-button submit-btn" id="final_register_btn" style="display: none;">
                        <i class="fas fa-user-plus"></i>
                        Crear Cuenta
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/scripts.js"></script>
    
    <style>
    /* Floating Logo Styles */
    .floating-logo {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 2000;
        opacity: 1;
        animation: floatIn 0.5s ease-in-out forwards;
    }

    .logo-image {
        width: 150px;
        height: 150px;
        object-fit: contain;
        filter: drop-shadow(0 15px 35px rgba(0, 0, 0, 0.5));
        animation: floating 2s ease-in-out infinite;
        margin: 0 auto;
        display: block;
    }

    .logo-placeholder {
        width: 150px;
        height: 150px;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(20px);
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 4rem;
        color: white;
        filter: drop-shadow(0 15px 35px rgba(0, 0, 0, 0.5));
        animation: floating 2s ease-in-out infinite;
        margin: 0 auto;
    }

    #mainInterface {
        opacity: 1;
        transform: translateY(0);
    }

    /* Animations */
    @keyframes floatIn {
        0% {
            opacity: 0;
            transform: translate(-50%, -50%) scale(0.8);
        }
        40% {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
        100% {
            opacity: 0;
            transform: translate(-50%, -50%) scale(1.1);
        }
    }

    @keyframes floating {
        0%, 100% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-10px);
        }
    }

    @keyframes fadeInUp {
        0% {
            opacity: 0;
            transform: translateY(30px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Logo positioned above main interface when visible */
    .main-logo {
        position: absolute;
        top: -100px;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 80px;
        object-fit: contain;
        filter: drop-shadow(0 8px 16px rgba(0, 0, 0, 0.3));
        animation: floating 3s ease-in-out infinite;
        opacity: 0;
        animation-delay: 3.5s;
        animation-fill-mode: forwards;
    }

    .main-logo.show {
        animation: fadeInFloat 1s ease-out forwards;
    }

    @keyframes fadeInFloat {
        0% {
            opacity: 0;
            transform: translateX(-50%) translateY(20px);
        }
        100% {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }

    /* Adjust main interface position */
    .glass-modal {
        position: relative;
        margin-top: 120px;
    }
    </style>

    <script>
    // Logo animation sequence
    window.addEventListener('load', function() {
        // Hide floating logo immediately
        document.getElementById('floatingLogo').style.display = 'none';
        
        // Add persistent logo above interface
        const mainInterface = document.getElementById('mainInterface');
        const logoHTML = document.getElementById('floatingLogo').innerHTML;
        
        const persistentLogo = document.createElement('div');
        persistentLogo.className = 'main-logo';
        persistentLogo.innerHTML = logoHTML;
        
        // Replace content for persistent logo
        if (persistentLogo.querySelector('.logo-image')) {
            const img = persistentLogo.querySelector('.logo-image');
            img.style.width = '80px';
            img.style.height = '80px';
        }
        
        mainInterface.appendChild(persistentLogo);
        persistentLogo.classList.add('show');
    });
    </script>
    
    <?php if (isset($login_error)): ?>
        <script>openModal('loginModal');</script>
    <?php endif; ?>
    
    <?php if (isset($register_error)): ?>
        <script>openModal('registerModal');</script>
    <?php endif; ?>
</body>
</html>