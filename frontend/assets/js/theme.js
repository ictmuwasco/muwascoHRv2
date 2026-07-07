/**
 * Theme Management System
 * 
 * Centralized theme handling for the application.
 * Reads theme from session (via PHP) and localStorage.
 */

(function() {
    'use strict';

    // Get theme from PHP session (set as data attribute on HTML tag)
    const htmlElement = document.documentElement;
    const sessionTheme = htmlElement.getAttribute('data-theme');
    
    // Get theme from localStorage (for persistence across sessions)
    const localTheme = localStorage.getItem('theme');
    
    // Determine which theme to use (session takes precedence)
    const theme = sessionTheme || localTheme || 'light';
    
    // Apply theme
    if (theme === 'dark') {
        htmlElement.classList.add('dark');
    } else {
        htmlElement.classList.remove('dark');
    }

    // Listen for theme changes from other parts of the app
    window.addEventListener('themeChanged', function(event) {
        const newTheme = event.detail.theme;
        if (newTheme === 'dark') {
            htmlElement.classList.add('dark');
        } else {
            htmlElement.classList.remove('dark');
        }
        localStorage.setItem('theme', newTheme);
    });

    // Export function to change theme
    window.changeTheme = function(newTheme) {
        // Update localStorage
        localStorage.setItem('theme', newTheme);
        
        // Dispatch event for other components
        window.dispatchEvent(new CustomEvent('themeChanged', {
            detail: { theme: newTheme }
        }));
        
        // Update session via API
        fetch('/api/theme', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ theme: newTheme })
        }).catch(err => console.error('Failed to update theme:', err));
    };
})();