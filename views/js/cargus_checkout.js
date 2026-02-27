/**
 * views/js/cargus_checkout.js
 * Handles UI interactions, Supercheckout compatibility, and PUDO selection.
 */

document.addEventListener('DOMContentLoaded', function () {
    const initCargusCheckout = () => {
        const pudoContainer = document.getElementById('cargus-pudo-container');
        if (!pudoContainer) return; // Not on the checkout page or carrier not available

        // 1. Detect Carrier Selection
        // Listen to standard PrestaShop events and custom Supercheckout changes
        const checkCarrierSelection = () => {
            // Find the checked radio button for delivery options
            const selectedCarrierInput = document.querySelector('input[name^="delivery_option["]:checked');
            
            if (selectedCarrierInput && selectedCarrierInput.value.includes(cargusCarrierId)) {
                pudoContainer.style.display = 'block';
            } else {
                pudoContainer.style.display = 'none';
            }
        };

        // Initial check
        checkCarrierSelection();

        // 2. Setup Mutation Observer for Supercheckout compatibility
        // Supercheckout constantly rewrites the DOM, so standard event listeners drop.
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.addedNodes.length > 0 || mutation.type === 'attributes') {
                    checkCarrierSelection();
                }
            });
        });

        // Observe the main delivery/shipping container (adapt selector if needed for Supercheckout)
        const deliveryContainer = document.querySelector('.checkout-delivery-step, #supercheckout-fieldset') || document.body;
        observer.observe(deliveryContainer, { childList: true, subtree: true, attributes: true });

        // Standard PS event for carrier update
        if (typeof prestashop !== 'undefined') {
            prestashop.on('updatedDeliveryForm', checkCarrierSelection);
        }

        // 3. Handle City Search & PUDO Selection (AJAX)
        const cityInput = document.getElementById('cargus_pudo_city');
        const pudoSelect = document.getElementById('cargus_pudo_select');
        const pudoListContainer = document.getElementById('cargus_pudo_list_container');
        const successMessage = document.getElementById('cargus_pudo_selection_success');

        let typingTimer;

        if (cityInput) {
            cityInput.addEventListener('keyup', () => {
                clearTimeout(typingTimer);
                typingTimer = setTimeout(() => {
                    const query = cityInput.value.trim();
                    if (query.length >= 3) {
                        fetchPudosByCity(query);
                    }
                }, 500); // 500ms debounce
            });
        }

        const fetchPudosByCity = async (city) => {
            try {
                const response = await fetch(`${cargusAjaxUrl}&action=getPudos&city=${encodeURIComponent(city)}&token=${cargusCsrfToken}`, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                
                const data = await response.json();
                
                if (data.success && data.pudos.length > 0) {
                    pudoSelect.innerHTML = '<option value="">-- Select a location --</option>';
                    data.pudos.forEach(pudo => {
                        const option = document.createElement('option');
                        option.value = pudo.pudo_id;
                        option.textContent = `${pudo.name} - ${pudo.address}`;
                        pudoSelect.appendChild(option);
                    });
                    pudoListContainer.style.display = 'block';
                }
            } catch (error) {
                console.error('Cargus Error fetching PUDOs:', error);
            }
        };

        // 4. Save Selected PUDO via AJAX
        if (pudoSelect) {
            pudoSelect.addEventListener('change', async (e) => {
                const pudoId = e.target.value;
                document.getElementById('cargus_selected_pudo_id').value = pudoId;

                if (pudoId) {
                    try {
                        const formData = new FormData();
                        formData.append('action', 'saveSelectedPudo');
                        formData.append('pudo_id', pudoId);
                        formData.append('token', cargusCsrfToken);

                        const response = await fetch(cargusAjaxUrl, {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });

                        const data = await response.json();
                        if (data.success) {
                            successMessage.style.display = 'block';
                            setTimeout(() => { successMessage.style.display = 'none'; }, 3000);
                        }
                    } catch (error) {
                        console.error('Cargus Error saving PUDO:', error);
                    }
                }
            });
        }
    };

    initCargusCheckout();
});