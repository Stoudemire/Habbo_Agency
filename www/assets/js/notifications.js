/**
 * Global Notification System
 * Creates beautiful glass-morphism notifications that work across all pages
 */
class NotificationSystem {
    constructor() {
        this.notifications = [];
        this.maxNotifications = 4;
        this.defaultDuration = 4000; // 4 seconds - much longer than before
    }

    /**
     * Show a notification
     * @param {string} message - The message to display
     * @param {string} type - success, error, info, warning
     * @param {number} duration - How long to show (milliseconds)
     */
    show(message, type = 'info', duration = null) {
        // Remove oldest notification if at max
        if (this.notifications.length >= this.maxNotifications) {
            this.removeOldest();
        }

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;
        
        // Add click to dismiss
        notification.addEventListener('click', () => {
            this.remove(notification);
        });

        // Add to DOM and tracking array
        document.body.appendChild(notification);
        this.notifications.push(notification);

        // Auto-remove after duration
        const finalDuration = duration || this.defaultDuration;
        setTimeout(() => {
            this.remove(notification);
        }, finalDuration);

        return notification;
    }

    /**
     * Remove a specific notification
     */
    remove(notification) {
        if (!notification || !notification.parentNode) return;

        // Add closing animation
        notification.classList.add('notification-closing');
        
        // Remove from DOM after animation
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
            
            // Remove from tracking array
            const index = this.notifications.indexOf(notification);
            if (index > -1) {
                this.notifications.splice(index, 1);
            }
        }, 400);
    }

    /**
     * Remove the oldest notification
     */
    removeOldest() {
        if (this.notifications.length > 0) {
            this.remove(this.notifications[0]);
        }
    }

    /**
     * Clear all notifications
     */
    clearAll() {
        [...this.notifications].forEach(notification => {
            this.remove(notification);
        });
    }

    // Convenience methods
    success(message, duration) {
        return this.show(message, 'success', duration);
    }

    error(message, duration) {
        return this.show(message, 'error', duration);
    }

    info(message, duration) {
        return this.show(message, 'info', duration);
    }

    warning(message, duration) {
        return this.show(message, 'warning', duration);
    }
}

// Create global instance
window.notifications = new NotificationSystem();

// Legacy compatibility for existing code
window.showNotification = function(message, type = 'info', duration = null) {
    return window.notifications.show(message, type, duration);
};

// Auto-convert PHP messages to notifications when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Look for alert elements and convert them to notifications
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        let type = 'info';
        if (alert.classList.contains('alert-success')) type = 'success';
        else if (alert.classList.contains('alert-error')) type = 'error';
        else if (alert.classList.contains('alert-warning')) type = 'warning';
        
        const message = alert.textContent.trim();
        if (message) {
            // Show notification
            window.notifications.show(message, type);
            
            // Hide the original alert
            alert.style.display = 'none';
        }
    });
});