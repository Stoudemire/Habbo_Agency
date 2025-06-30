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

// Password validation function
function validatePassword(password) {
    const errors = [];
    
    if (password.length < 8) {
        errors.push('Mínimo 8 caracteres');
    }
    
    if (!/[a-z]/.test(password)) {
        errors.push('Al menos una letra minúscula');
    }
    
    if (!/[A-Z]/.test(password)) {
        errors.push('Al menos una letra mayúscula');
    }
    
    if (!/\d/.test(password)) {
        errors.push('Al menos un número');
    }
    
    if (!/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
        errors.push('Al menos un carácter especial (!@#$%^&*(),.?":{}|<>)');
    }
    
    return errors;
}

// Real-time password validation
function setupPasswordValidation() {
    // Use a more reliable delay and better selector within the modal
    setTimeout(() => {
        const modal = document.getElementById('registerModal');
        if (!modal) return;
        
        const passwordInput = modal.querySelector('input[name="password"]');
        const confirmPasswordInput = modal.querySelector('input[name="confirmPassword"]');
        
        if (!passwordInput || !confirmPasswordInput) {
            console.log('Password inputs not found, retrying...');
            // Retry if inputs not found
            setTimeout(setupPasswordValidation, 200);
            return;
        }
        
        // Remove existing requirements and match div if any
        const existingRequirements = modal.querySelector('.password-requirements');
        const existingMatch = modal.querySelector('.password-match');
        if (existingRequirements) existingRequirements.remove();
        if (existingMatch) existingMatch.remove();
        
        // Create password requirements display
        const requirementsDiv = document.createElement('div');
        requirementsDiv.className = 'password-requirements';
        requirementsDiv.style.display = 'none';
        requirementsDiv.innerHTML = `
            <div class="requirement" id="req-length">
                <i class="fas fa-times"></i>
                <span>Mínimo 8 caracteres</span>
            </div>
            <div class="requirement" id="req-lowercase">
                <i class="fas fa-times"></i>
                <span>Al menos una letra minúscula (a-z)</span>
            </div>
            <div class="requirement" id="req-uppercase">
                <i class="fas fa-times"></i>
                <span>Al menos una letra mayúscula (A-Z)</span>
            </div>
            <div class="requirement" id="req-number">
                <i class="fas fa-times"></i>
                <span>Al menos un número (0-9)</span>
            </div>
            <div class="requirement" id="req-special">
                <i class="fas fa-times"></i>
                <span>Al menos un carácter especial (!@#$%^&*)</span>
            </div>
            <div class="password-strength">
                <div class="strength-meter">
                    <div class="strength-fill" id="strength-fill"></div>
                </div>
                <span class="strength-text" id="strength-text">Muy débil</span>
            </div>
        `;
        
        // Insert after password input
        passwordInput.parentNode.insertBefore(requirementsDiv, passwordInput.nextSibling);
        
        // Create password match div
        const matchDiv = document.createElement('div');
        matchDiv.id = 'password-match';
        matchDiv.className = 'password-match';
        matchDiv.style.display = 'none';
        confirmPasswordInput.parentNode.insertBefore(matchDiv, confirmPasswordInput.nextSibling);
        
        // Clear any existing event listeners by cloning and replacing
        const newPasswordInput = passwordInput.cloneNode(true);
        const newConfirmPasswordInput = confirmPasswordInput.cloneNode(true);
        passwordInput.parentNode.replaceChild(newPasswordInput, passwordInput);
        confirmPasswordInput.parentNode.replaceChild(newConfirmPasswordInput, confirmPasswordInput);
        
        // Real-time validation for password
        newPasswordInput.addEventListener('input', function() {
            const password = this.value;
            
            if (password.length > 0) {
                requirementsDiv.style.display = 'block';
                updatePasswordRequirements(password);
                updatePasswordStrength(password);
            } else {
                requirementsDiv.style.display = 'none';
            }
            
            // Also check confirm password if it has content
            validatePasswordMatch(newPasswordInput, newConfirmPasswordInput, matchDiv);
        });
        
        // Real-time validation for confirm password
        newConfirmPasswordInput.addEventListener('input', function() {
            validatePasswordMatch(newPasswordInput, newConfirmPasswordInput, matchDiv);
        });
        
        // Hide requirements when field loses focus and is empty
        newPasswordInput.addEventListener('blur', function() {
            if (this.value.length === 0) {
                requirementsDiv.style.display = 'none';
            }
        });
        
        // Show requirements when field gains focus and has content
        newPasswordInput.addEventListener('focus', function() {
            if (this.value.length > 0) {
                requirementsDiv.style.display = 'block';
            }
        });
        
        console.log('Password validation setup completed');
    }, 300);
}

// Separate function for password match validation
function validatePasswordMatch(passwordInput, confirmPasswordInput, matchDiv) {
    const password = passwordInput.value;
    const confirmPassword = confirmPasswordInput.value;
    
    if (confirmPassword === '') {
        matchDiv.style.display = 'none';
    } else if (password === confirmPassword && password.length > 0) {
        matchDiv.innerHTML = '<i class="fas fa-check"></i> Las contraseñas coinciden';
        matchDiv.className = 'password-match valid';
        matchDiv.style.display = 'block';
    } else {
        matchDiv.innerHTML = '<i class="fas fa-times"></i> Las contraseñas no coinciden';
        matchDiv.className = 'password-match invalid';
        matchDiv.style.display = 'block';
    }
}

function updatePasswordRequirements(password) {
    const modal = document.getElementById('registerModal');
    if (!modal) return;
    
    const requirements = [
        { id: 'req-length', test: password.length >= 8 },
        { id: 'req-lowercase', test: /[a-z]/.test(password) },
        { id: 'req-uppercase', test: /[A-Z]/.test(password) },
        { id: 'req-number', test: /\d/.test(password) },
        { id: 'req-special', test: /[!@#$%^&*(),.?":{}|<>]/.test(password) }
    ];
    
    requirements.forEach(req => {
        const element = modal.querySelector(`#${req.id}`);
        if (element) {
            const icon = element.querySelector('i');
            element.classList.remove('valid', 'invalid');
            
            if (req.test) {
                element.classList.add('valid');
                icon.className = 'fas fa-check';
            } else {
                element.classList.add('invalid');
                icon.className = 'fas fa-times';
            }
        }
    });
}

function updatePasswordStrength(password) {
    const modal = document.getElementById('registerModal');
    if (!modal) return;
    
    const strengthFill = modal.querySelector('#strength-fill');
    const strengthText = modal.querySelector('#strength-text');
    
    if (!strengthFill || !strengthText) return;
    
    if (password.length === 0) {
        strengthFill.style.width = '0%';
        strengthFill.style.backgroundColor = '#ff4757';
        strengthText.textContent = 'Muy débil';
        strengthText.style.color = '#ff4757';
        return;
    }
    
    const errors = validatePassword(password);
    const strength = 5 - errors.length;
    
    const strengthLevels = [
        { min: 0, max: 1, text: 'Muy débil', color: '#ff4757', width: '20%' },
        { min: 2, max: 2, text: 'Débil', color: '#ff6b47', width: '40%' },
        { min: 3, max: 3, text: 'Regular', color: '#ffa502', width: '60%' },
        { min: 4, max: 4, text: 'Fuerte', color: '#7bed9f', width: '80%' },
        { min: 5, max: 5, text: 'Muy fuerte', color: '#2ed573', width: '100%' }
    ];
    
    const level = strengthLevels.find(l => strength >= l.min && strength <= l.max) || strengthLevels[0];
    
    strengthFill.style.width = level.width;
    strengthFill.style.backgroundColor = level.color;
    strengthFill.style.transition = 'all 0.3s ease';
    strengthText.textContent = level.text;
    strengthText.style.color = level.color;
}

// Form validation
function validatePasswords(form) {
    const modal = document.getElementById('registerModal');
    if (!modal) return false;
    
    const passwordInput = modal.querySelector('input[name="password"]');
    const confirmPasswordInput = modal.querySelector('input[name="confirmPassword"]');
    
    if (!passwordInput || !confirmPasswordInput) return false;
    
    const password = passwordInput.value;
    const confirmPassword = confirmPasswordInput.value;
    
    // Validate password strength
    const passwordErrors = validatePassword(password);
    if (passwordErrors.length > 0) {
        alert('La contraseña no cumple con los requisitos de seguridad:\n• ' + passwordErrors.join('\n• '));
        return false;
    }
    
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

// Setup password validation when register modal opens
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Setup password validation if it's the register modal
        if (modalId === 'registerModal') {
            setupPasswordValidation();
        }
    }
}

// Clean up when modal closes
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // Clean up password validation elements if it's the register modal
        if (modalId === 'registerModal') {
            const requirementsDiv = modal.querySelector('.password-requirements');
            const matchDiv = modal.querySelector('.password-match');
            if (requirementsDiv) requirementsDiv.remove();
            if (matchDiv) matchDiv.remove();
        }
    }
}

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

async function startVerification() {
    const habboUsername = document.getElementById('habbo_username').value.trim();
    
    if (!habboUsername) {
        alert('Por favor ingresa tu nombre de usuario de Habbo');
        return;
    }
    
    // First check if username already exists
    try {
        const response = await fetch('check_username.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'habbo_username=' + encodeURIComponent(habboUsername)
        });
        
        const result = await response.json();
        
        if (!result.available) {
            // Show error message in register modal
            const registerForm = document.getElementById('registerForm');
            let errorDiv = registerForm.querySelector('.username-error');
            
            if (!errorDiv) {
                errorDiv = document.createElement('div');
                errorDiv.className = 'username-error error-message';
                // Insert after the habbo_username input
                const usernameInput = document.getElementById('habbo_username');
                usernameInput.parentNode.insertBefore(errorDiv, usernameInput.nextSibling);
            }
            
            errorDiv.textContent = 'Este nombre de Habbo ya está registrado';
            errorDiv.style.display = 'block';
            
            // Focus back to username input
            document.getElementById('habbo_username').focus();
            return;
        }
        
        // Username is available, proceed with verification
        // Remove any existing error message
        const existingError = document.querySelector('.username-error');
        if (existingError) {
            existingError.style.display = 'none';
        }
        
    } catch (error) {
        console.error('Error checking username:', error);
        alert('Error al verificar el nombre de usuario. Por favor intenta de nuevo.');
        return;
    }
    
    // Close register modal first
    closeModal('registerModal');
    
    // Set username in verification modal
    document.getElementById('verification_habbo_username').value = habboUsername;
    
    // Generate new verification code
    verificationCode = generateVerificationCode();
    document.getElementById('verification_code').value = verificationCode;
    document.getElementById('display_code').textContent = verificationCode;
    
    // Clear previous messages
    document.getElementById('verification_messages').innerHTML = '';
    
    // Start timer
    startTimer();
    
    // Open verification modal
    openModal('verificationModal');
    
    showVerificationMessage('Código generado. Sigue los pasos para verificar tu cuenta.', 'loading');
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
            
            // Show success message in verification modal
            showVerificationMessage('¡Verificación exitosa!', 'success');
            
            // Close verification modal after 2 seconds
            setTimeout(() => {
                closeModal('verificationModal');
                
                // Show verification status in register form
                document.getElementById('verification_status').style.display = 'block';
                document.getElementById('start_verification_btn').style.display = 'none';
                document.getElementById('final_register_btn').style.display = 'inline-flex';
            }, 2000);
            
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
    const messagesContainer = document.getElementById('verification_messages');
    if (!messagesContainer) return;
    
    // Remove existing message
    const existingMessage = messagesContainer.querySelector('.verification-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Create new message
    const messageDiv = document.createElement('div');
    messageDiv.className = `verification-message ${type}`;
    messageDiv.innerHTML = `
        <div class="message-content">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-spinner fa-spin'}"></i>
            <span>${message}</span>
        </div>
    `;
    
    messagesContainer.appendChild(messageDiv);
}