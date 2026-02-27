/**
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 * @version   1.0.1
 */

document.addEventListener('DOMContentLoaded', function() {
    // Căutăm butonul "Test Locații" indiferent de codul HTML din spate
    const buttons = document.querySelectorAll('button, a, input[type="button"]');
    let btnTestLocations = null;
    
    buttons.forEach(btn => {
        if (btn.innerText.includes('Test Locații') || (btn.value && btn.value.includes('Test Locații'))) {
            btnTestLocations = btn;
        }
    });

    const consoleOutput = document.querySelector('.api-tester-console-output') || document.querySelector('#api-tester-console-output'); 

    function logToConsole(message, type = 'info') {
        if (!consoleOutput) {
            alert('Cargus API Tester: ' + message); // Mesaj de siguranță dacă div-ul negru lipsește
            return;
        }
        
        const timestamp = new Date().toLocaleTimeString();
        let color = '#fff'; // Alb standard
        if (type === 'error') color = '#ff4c4c'; // Roșu la erori
        if (type === 'success') color = '#4caf50'; // Verde la succes
        
        const newLine = document.createElement('div');
        newLine.style.color = color;
        newLine.style.fontFamily = 'monospace';
        newLine.style.marginBottom = '5px';
        newLine.innerHTML = `[${timestamp}] ${message}`;
        
        consoleOutput.appendChild(newLine);
        consoleOutput.scrollTop = consoleOutput.scrollHeight;
    }

    if (btnTestLocations) {
        btnTestLocations.addEventListener('click', function(e) {
            e.preventDefault();
            logToConsole('Se testează conexiunea la locații...', 'info');
            
            // Apelăm noul fișier securizat
            const ajaxUrl = '../modules/cargus/ajax.php';

            fetch(ajaxUrl + '?action=TestLocations', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    logToConsole('OK: ' + data.message, 'success');
                } else {
                    logToConsole('EȘUAT: ' + data.message, 'error');
                }
            })
            .catch(error => {
                logToConsole('EROARE REȚEA: ' + error.message, 'error');
            });
        });
    }
});
