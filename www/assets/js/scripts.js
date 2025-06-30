// Modal management functions
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Close modal when clicking outside of it
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal-overlay')) {
        const modalId = event.target.id;
        closeModal(modalId);
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal-overlay[style*="flex"]');
        openModals.forEach(modal => {
            modal.style.display = 'none';
        });
        document.body.style.overflow = 'auto';
    }
});

// Form validation
function validatePasswords(form) {
    const password = form.querySelector('input[name="password"]').value;
    const confirmPassword = form.querySelector('input[name="confirmPassword"]').value;
    
    if (password !== confirmPassword) {
        alert('Las contraseñas no coinciden');
        return false;
    }
    return true;
}

// Add form validation on submit
document.addEventListener('DOMContentLoaded', function() {
    const registerForm = document.querySelector('form[action*="register"]');
    if (registerForm) {
        registerForm.addEventListener('submit', function(event) {
            if (!validatePasswords(this)) {
                event.preventDefault();
            }
        });
    }
    
    // Initialize Habbo verification
    initHabboVerification();
});

// Habbo Verification System
let verificationCode = '';
let verificationTimer = null;
let timeLeft = 600; // 10 minutes in seconds

function initHabboVerification() {
    const startVerificationBtn = document.getElementById('start_verification_btn');
    const verifyMissionBtn = document.getElementById('verify_mission_btn');
    const generateNewCodeBtn = document.getElementById('generate_new_code_btn');
    const habboUsernameInput = document.getElementById('habbo_username');
    
    if (startVerificationBtn) {
        startVerificationBtn.addEventListener('click', startVerification);
    }
    
    if (verifyMissionBtn) {
        verifyMissionBtn.addEventListener('click', verifyMission);
    }
    
    if (generateNewCodeBtn) {
        generateNewCodeBtn.addEventListener('click', generateNewCode);
    }
}

function generateVerificationCode() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let code = '';
    for (let i = 0; i < 6; i++) {
        code += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return code;
}

function startVerification() {
    const habboUsername = document.getElementById('habbo_username').value.trim();
    
    if (!habboUsername) {
        showVerificationMessage('Por favor ingresa tu nombre de usuario de Habbo', 'error');
        return;
    }
    
    // Generate new verification code
    verificationCode = generateVerificationCode();
    document.getElementById('verification_code').value = verificationCode;
    document.getElementById('display_code').textContent = verificationCode;
    
    // Show verification step
    document.getElementById('verification_step').style.display = 'block';
    document.getElementById('start_verification_btn').style.display = 'none';
    
    // Start timer
    startTimer();
    
    showVerificationMessage('Código generado. Colócalo en tu misión de Habbo y haz clic en "Verificar misión".', 'loading');
}

function generateNewCode() {
    verificationCode = generateVerificationCode();
    document.getElementById('verification_code').value = verificationCode;
    document.getElementById('display_code').textContent = verificationCode;
    
    // Reset timer
    timeLeft = 600;
    startTimer();
    
    showVerificationMessage('Nuevo código generado. Actualiza tu misión de Habbo.', 'loading');
}

function startTimer() {
    if (verificationTimer) {
        clearInterval(verificationTimer);
    }
    
    verificationTimer = setInterval(() => {
        timeLeft--;
        updateTimerDisplay();
        
        if (timeLeft <= 0) {
            clearInterval(verificationTimer);
            showVerificationMessage('El código ha expirado. Genera un nuevo código.', 'error');
            document.getElementById('verify_mission_btn').disabled = true;
        }
    }, 1000);
}

function updateTimerDisplay() {
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    const timerDisplay = document.getElementById('timer');
    if (timerDisplay) {
        timerDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
}

async function verifyMission() {
    const habboUsername = document.getElementById('habbo_username').value.trim();
    
    if (!habboUsername) {
        showVerificationMessage('Por favor ingresa tu nombre de usuario de Habbo', 'error');
        return;
    }
    
    if (timeLeft <= 0) {
        showVerificationMessage('El código ha expirado. Genera un nuevo código.', 'error');
        return;
    }
    
    showVerificationMessage('Verificando misión...', 'loading');
    
    try {
        const response = await fetch(`https://www.habbo.es/api/public/users?name=${encodeURIComponent(habboUsername)}`);
        
        if (!response.ok) {
            throw new Error('Usuario no encontrado');
        }
        
        const userData = await response.json();
        
        if (!userData || !userData.motto) {
            showVerificationMessage('No se pudo obtener la información del usuario o la misión está vacía.', 'error');
            return;
        }
        
        // Check if motto contains the exact verification code
        if (userData.motto.includes(verificationCode)) {
            // Verification successful
            clearInterval(verificationTimer);
            
            // Set verification fields properly
            document.getElementById('habbo_verified').value = 'true';
            document.getElementById('verification_code').value = verificationCode;
            
            // Hide verification step and show completion
            document.getElementById('verification_step').style.display = 'none';
            
            showVerificationMessage('¡Verificación exitosa! Registrando tu cuenta...', 'success');
            
            // Validate all form fields before submission
            const form = document.getElementById('registerForm');
            const formData = new FormData(form);
            
            // Check all required fields
            const requiredFields = ['password', 'confirmPassword', 'habbo_username'];
            let allFieldsValid = true;
            
            for (let field of requiredFields) {
                const value = formData.get(field);
                if (!value || value.trim() === '') {
                    showVerificationMessage(`Error: El campo ${field} está vacío`, 'error');
                    allFieldsValid = false;
                    break;
                }
            }
            
            if (allFieldsValid) {
                // Auto-submit the form after ensuring all fields are set
                setTimeout(() => {
                    console.log('Submitting form with all fields validated');
                    form.submit();
                }, 1500);
            } else {
                document.getElementById('habbo_verified').value = 'false';
            }
        } else {
            showVerificationMessage(`Código no encontrado en tu misión. Tu misión actual: "${userData.motto}". Asegúrate de colocar exactamente: ${verificationCode}`, 'error');
        }
        
    } catch (error) {
        console.error('Error verificando misión:', error);
        showVerificationMessage('Error al verificar la misión. Verifica que el nombre de usuario sea correcto.', 'error');
    }
}

function showVerificationMessage(message, type) {
    // Remove existing message
    const existingMessage = document.querySelector('.verification-status');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Create new message
    const messageDiv = document.createElement('div');
    messageDiv.className = `verification-status ${type}`;
    messageDiv.textContent = message;
    
    // Insert after verification step
    const verificationStep = document.getElementById('verification_step');
    if (verificationStep) {
        verificationStep.appendChild(messageDiv);
    }
}