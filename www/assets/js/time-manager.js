class TimeManager {
    constructor() {
        this.intervals = new Map();
        this.creditsPerMinute = 1; // Default: 1 credit per minute
    }

    init() {
        this.bindEvents();
        this.loadActiveSessions();
        this.startAutoRefresh();
    }

    bindEvents() {
        // Start timer form
        const startForm = document.getElementById('startTimerForm');
        if (startForm) {
            startForm.addEventListener('submit', this.handleStartTimer.bind(this));
        }
    }

    startExistingTimers() {
        // Start timers for sessions already rendered in PHP
        const sessions = window.activeSessionsData || [];
        this.startTimers(sessions);
    }

    async handleStartTimer(e) {
        e.preventDefault();
        
        // Get selected user ID from Select2
        const userId = document.getElementById('user_id').value;
        if (!userId) {
            this.showNotification('Por favor selecciona un usuario', 'error');
            return;
        }
        
        // Find username from selected user ID
        const selectedUser = window.usersData.find(user => user.id == userId);
        const username = selectedUser ? selectedUser.username : '';
        
        const formData = new FormData(e.target);
        formData.append('action', 'start_timer');
        formData.set('username', username); // Use username for backend compatibility
        
        const submitButton = e.target.querySelector('button[type="submit"]');
        this.setButtonLoading(submitButton, true);
        
        try {
            const response = await fetch('time-manager.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showNotification('Tiempo iniciado correctamente', 'success');
                // Reset form and Select2
                e.target.reset();
                $('#user_id').val(null).trigger('change');
                // Immediate update for instant response
                await this.loadActiveSessions();
            } else {
                this.showNotification(result.message || 'Error al iniciar el tiempo', 'error');
            }
        } catch (error) {
            console.error('Error starting timer:', error);
            this.showNotification('Error de conexión', 'error');
        } finally {
            this.setButtonLoading(submitButton, false);
        }
    }

    async loadActiveSessions() {
        try {
            const response = await fetch('time-manager.php?action=get_sessions');
            const data = await response.json();
            
            if (data.success) {
                let sessions = data.sessions || [];
                
                // If user cannot manage timers, filter to show only their own sessions
                if (!window.canManageTimers && window.currentUserId) {
                    sessions = sessions.filter(session => session.user_id == window.currentUserId);
                }
                
                // Update credits per minute from server response
                if (data.credits_per_minute) {
                    this.creditsPerMinute = parseFloat(data.credits_per_minute);
                }
                this.renderSessions(sessions);
                this.startTimers(sessions);
            } else {
                console.error('Server error:', data.error);
            }
        } catch (error) {
            console.error('Error loading sessions:', error);
        }
    }

    startAutoRefresh() {
        // Refresh sessions every 2 seconds for more responsive updates
        setInterval(() => {
            this.loadActiveSessions();
        }, 2000);
    }

    renderSessions(sessions) {
        const tableBody = document.getElementById('activeSessions');
        const noSessionsDiv = document.getElementById('noSessions');
        
        if (!tableBody) return;
        
        // Store current scroll position
        const container = document.querySelector('.table-container');
        const scrollTop = container ? container.scrollTop : 0;
        
        if (sessions.length === 0) {
            tableBody.innerHTML = '';
            if (noSessionsDiv) {
                noSessionsDiv.style.display = 'block';
                // Update message based on user permissions
                const message = window.canManageTimers ? 
                    'No hay tiempos activos en este momento' : 
                    'No tienes tiempos activos en este momento';
                const messageP = noSessionsDiv.querySelector('p');
                if (messageP) messageP.textContent = message;
            }
            return;
        }
        
        if (noSessionsDiv) noSessionsDiv.style.display = 'none';
        
        // Sort sessions by username to maintain consistent ordering
        sessions.sort((a, b) => a.username.localeCompare(b.username));
        
        const canManage = window.canManageTimers || false;
        
        const html = sessions.map(session => {
            let rowHtml = `
            <tr>
                <td>${this.escapeHtml(session.username)}</td>`;
                
            // Only show description column for users with management permissions
            if (canManage) {
                rowHtml += `
                <td>${this.escapeHtml(session.description || 'Sin descripción')}</td>`;
            }
            
            rowHtml += `
                <td>
                    <span id="timer-${session.id}" class="timer-display">
                        ${this.formatTime(parseInt(session.current_total || session.total_time) || 0)}
                    </span>
                </td>
                <td>
                    <span id="credits-${session.id}" class="credits-display">
                        ${Math.round(session.credits_earned || 0)}
                    </span>
                </td>
                <td>
                    <span class="status-badge status-${session.status}">${session.status.toUpperCase()}</span>
                </td>`;
                

                
            // Only show actions column for users with management permissions
            if (canManage) {
                rowHtml += `
                <td>
                    ${this.renderActionButtons(session)}
                </td>`;
            }
            
            rowHtml += `
            </tr>`;
            
            return rowHtml;
        }).join('');

        tableBody.innerHTML = html;
        
        // Restore scroll position
        if (container) {
            container.scrollTop = scrollTop;
        }
    }

    renderActionButtons(session) {
        const buttons = [];
        
        if (session.status === 'active') {
            buttons.push(`<button class="action-btn pause-btn" onclick="window.timeManager.pauseTimer(${session.id})">⏸ Pausar</button>`);
            buttons.push(`<button class="action-btn stop-btn" onclick="window.timeManager.stopTimer(${session.id})">⏹ Detener</button>`);
            buttons.push(`<button class="action-btn cancel-btn" onclick="window.timeManager.cancelTimer(${session.id})">✕ Cancelar</button>`);
        } else if (session.status === 'paused') {
            buttons.push(`<button class="action-btn resume-btn" onclick="window.timeManager.resumeTimer(${session.id})">▶ Reanudar</button>`);
            buttons.push(`<button class="action-btn stop-btn" onclick="window.timeManager.stopTimer(${session.id})">⏹ Detener</button>`);
            buttons.push(`<button class="action-btn cancel-btn" onclick="window.timeManager.cancelTimer(${session.id})">✕ Cancelar</button>`);
        }
        
        return `<div class="session-actions">${buttons.join('')}</div>`;
    }

    startTimers(sessions) {
        // Clear existing intervals
        this.intervals.forEach(interval => clearInterval(interval));
        this.intervals.clear();
        
        sessions.forEach(session => {
            if (session.status === 'active') {
                // Convert MySQL timestamp to proper UTC timestamp
                const startTime = new Date(session.start_time.replace(' ', 'T') + 'Z').getTime();
                const baseSeconds = parseInt(session.total_time) || 0;
                
                // Start interval for this timer
                const interval = setInterval(() => {
                    const now = Date.now();
                    const elapsed = Math.floor((now - startTime) / 1000);
                    const currentTotal = baseSeconds + elapsed;
                    
                    const timerElement = document.getElementById(`timer-${session.id}`);
                    if (timerElement) {
                        timerElement.textContent = this.formatTime(currentTotal);
                    }
                    
                    // Update credits in real time
                    const creditsElement = document.getElementById(`credits-${session.id}`);
                    if (creditsElement) {
                        const totalMinutes = currentTotal / 60;
                        const creditsEarned = Math.round(totalMinutes * this.creditsPerMinute);
                        creditsElement.textContent = creditsEarned;
                    }
                }, 1000);
                
                this.intervals.set(session.id, interval);
                
                // Also update the timer immediately
                const timerElement = document.getElementById(`timer-${session.id}`);
                if (timerElement) {
                    const now = Date.now();
                    const elapsed = Math.floor((now - startTime) / 1000);
                    const currentTotal = baseSeconds + elapsed;
                    timerElement.textContent = this.formatTime(currentTotal);
                }
            } else if (session.status === 'paused') {
                // For paused sessions, just show the stored total time
                const timerElement = document.getElementById(`timer-${session.id}`);
                if (timerElement) {
                    const totalSeconds = parseInt(session.current_total || session.total_seconds) || 0;
                    timerElement.textContent = this.formatTime(totalSeconds);
                }
            }
        });
    }

    async pauseTimer(sessionId) {
        await this.performTimerAction(sessionId, 'pause_timer', 'Pausando...');
    }

    async resumeTimer(sessionId) {
        await this.performTimerAction(sessionId, 'resume_timer', 'Reanudando...');
    }

    async stopTimer(sessionId) {
        await this.performTimerAction(sessionId, 'stop_timer', 'Deteniendo...');
    }

    async cancelTimer(sessionId) {
        await this.performTimerAction(sessionId, 'cancel_timer', 'Cancelando...');
    }

    async performTimerAction(sessionId, action, loadingText) {
        
        try {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('session_id', sessionId);
            
            const response = await fetch('time-manager.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showNotification('Acción completada correctamente', 'success');
                // Update table in real-time instead of reloading
                await this.loadActiveSessions();
            } else {
                this.showNotification(result.message || 'Error al realizar la acción', 'error');
            }
        } catch (error) {
            console.error('Error performing action:', error);
            this.showNotification('Error de conexión', 'error');
        }
    }

    formatTime(totalSeconds) {
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        
        return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    setButtonLoading(button, loading, text = null) {
        if (!button) return;
        
        if (loading) {
            button.disabled = true;
            button.style.opacity = '0.6';
            if (text) button.textContent = text;
        } else {
            button.disabled = false;
            button.style.opacity = '1';
            if (text) button.textContent = text;
        }
    }

    showNotification(message, type = 'info') {
        // Use global notification system
        if (window.notifications) {
            window.notifications.show(message, type);
        } else {
            // Fallback for compatibility
            console.log(`${type.toUpperCase()}: ${message}`);
        }
    }
}

function refreshSessions() {
    if (window.timeManager) {
        window.timeManager.loadActiveSessions();
    }
}

// Initialize timeManager globally
let timeManager;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    timeManager = new TimeManager();
    window.timeManager = timeManager;
    timeManager.init();
    
    // Also verify elements exist
    const startForm = document.getElementById('startTimerForm');
    const tableBody = document.getElementById('activeSessions');
});