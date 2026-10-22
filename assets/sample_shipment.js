import { BrowserMultiFormatReader } from '@zxing/browser';
import './css/sample_shipment.css';

const root = document.querySelector('[data-sample-app]');

if (root) {
    const authenticated = root.dataset.authenticated === 'true';
    const CART_KEY = 'lns-sample-shipment-cart-v1';
    const TOKEN_KEY = 'lns-sample-shipment-token-v1';
    const toast = root.querySelector('[data-toast]');
    let toastTimer;
    let installPrompt;

    const showToast = (message, type = 'success') => {
        if (!toast) return;
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.dataset.type = type;
        toast.hidden = false;
        toastTimer = window.setTimeout(() => {
            toast.hidden = true;
        }, 3200);
    };

    const readJson = async (response) => {
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(data.message || 'Une erreur inattendue est survenue.');
            error.status = response.status;
            error.data = data;
            throw error;
        }

        return data;
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt = event;
        const button = root.querySelector('[data-install-button]');
        if (button) button.hidden = false;
    });

    root.querySelector('[data-install-button]')?.addEventListener('click', async () => {
        if (!installPrompt) return;
        await installPrompt.prompt();
        installPrompt = null;
        root.querySelector('[data-install-button]').hidden = true;
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sample-shipment-service-worker.js', { scope: '/echantillons' }).catch(() => {});
        });
    }

    if (/iphone|ipad|ipod/i.test(navigator.userAgent) && !window.matchMedia('(display-mode: standalone)').matches) {
        const installHelp = root.querySelector('[data-install-help]');
        if (installHelp) installHelp.hidden = false;
    }

    if (!authenticated) {
        const pinForm = root.querySelector('[data-pin-form]');
        const pinError = root.querySelector('[data-pin-error]');

        pinForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = pinForm.querySelector('button[type="submit"]');
            const pin = pinForm.elements.pin.value.trim();
            pinError.textContent = '';

            if (!pin) {
                pinError.textContent = 'Saisissez le code d’accès.';
                return;
            }

            button.disabled = true;

            try {
                const response = await fetch(root.dataset.authUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ pin, csrfToken: root.dataset.authCsrf }),
                });
                await readJson(response);
                window.location.reload();
            } catch (error) {
                pinError.textContent = error.message;
                button.disabled = false;
            }
        });
    } else {
        let cart = loadCart();
        let currentStep = 0;
        let submitting = false;
        let activeRequestToken = localStorage.getItem(TOKEN_KEY);
        let draftGeneration = 0;
        let scannerControls = null;
        let scannerBusy = false;
        let lastScannedCode = '';
        let lastScannedAt = 0;
        const codeReader = new BrowserMultiFormatReader();

        const cartElement = root.querySelector('[data-cart]');
        const cartEmpty = root.querySelector('[data-cart-empty]');
        const cartCount = root.querySelector('[data-cart-count]');
        const cartSummary = root.querySelector('[data-cart-summary]');
        const nextProducts = root.querySelector('[data-next-products]');
        const searchForm = root.querySelector('[data-product-search]');
        const searchInput = root.querySelector('[data-search-input]');
        const searchResults = root.querySelector('[data-search-results]');
        const recipientForm = root.querySelector('[data-recipient-form]');
        const representativeForm = root.querySelector('[data-representative-form]');
        const representativeSelect = root.querySelector('[data-representative-select]');
        const cameraModal = root.querySelector('[data-camera-modal]');
        const cameraVideo = root.querySelector('[data-camera-video]');
        const cameraStatus = root.querySelector('[data-camera-status]');

        function loadCart() {
            try {
                const stored = JSON.parse(localStorage.getItem(CART_KEY) || '[]');
                return Array.isArray(stored)
                    ? stored.filter((item) => item && item.reference && Number(item.quantity) > 0)
                    : [];
            } catch {
                return [];
            }
        }

        function saveCart() {
            if (cart.length) localStorage.setItem(CART_KEY, JSON.stringify(cart));
            else localStorage.removeItem(CART_KEY);
        }

        function totalQuantity() {
            return cart.reduce((total, item) => total + item.quantity, 0);
        }

        function renderCart() {
            cartElement.replaceChildren();
            const quantity = totalQuantity();
            cartCount.textContent = String(quantity);
            cartSummary.textContent = quantity === 0
                ? 'Aucun produit pour le moment'
                : `${quantity} échantillon${quantity > 1 ? 's' : ''} · ${cart.length} référence${cart.length > 1 ? 's' : ''}`;
            cartEmpty.hidden = cart.length > 0;
            nextProducts.disabled = cart.length === 0;

            cart.forEach((item, index) => {
                const row = document.createElement('article');
                row.className = 'sample-cart-item';
                row.dataset.reference = item.reference;

                const identity = document.createElement('div');
                identity.className = 'sample-cart-item__identity';
                const icon = document.createElement('span');
                icon.textContent = item.reference.slice(0, 2).toUpperCase();
                const copy = document.createElement('div');
                const title = document.createElement('strong');
                title.textContent = item.designation || item.reference;
                const reference = document.createElement('small');
                reference.textContent = `${item.reference}${item.barcode ? ` · ${item.barcode}` : ''}`;
                copy.append(title, reference);
                identity.append(icon, copy);

                const quantityControl = document.createElement('div');
                quantityControl.className = 'sample-quantity';
                quantityControl.innerHTML = `
                    <button type="button" data-cart-action="decrease" aria-label="Diminuer">−</button>
                    <strong>${item.quantity}</strong>
                    <button type="button" data-cart-action="increase" aria-label="Augmenter">+</button>
                `;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'sample-cart-item__remove';
                remove.dataset.cartAction = 'remove';
                remove.setAttribute('aria-label', `Supprimer ${item.reference}`);
                remove.textContent = '×';

                const expiry = document.createElement('div');
                expiry.className = 'sample-field sample-cart-item__expiry';
                const expiryLabel = document.createElement('label');
                expiryLabel.htmlFor = `sample-expiry-${index}`;
                expiryLabel.textContent = 'Date de péremption *';
                const expiryInput = document.createElement('input');
                expiryInput.id = expiryLabel.htmlFor;
                expiryInput.type = 'date';
                expiryInput.required = true;
                expiryInput.dataset.expiryDate = '';
                expiryInput.value = item.expiryDate || '';
                const expiryError = document.createElement('p');
                expiryError.className = 'sample-field-error';
                expiryError.dataset.errorFor = `lines.${index}.expiryDate`;
                expiry.append(expiryLabel, expiryInput, expiryError);

                row.append(identity, quantityControl, remove, expiry);
                cartElement.append(row);
            });

            saveCart();
        }

        function addProduct(product) {
            if (submitting || currentStep !== 1) return;
            const existing = cart.find((item) => item.reference === product.reference);

            if (existing) {
                existing.quantity = Math.min(999, existing.quantity + 1);
            } else {
                cart.push({
                    reference: product.reference,
                    designation: product.designation || product.reference,
                    barcode: product.barcode || null,
                    quantity: 1,
                    expiryDate: '',
                });
            }

            renderCart();
            signalSuccess();
            showToast(`${product.reference} ajouté à l’envoi.`);
        }

        function signalSuccess() {
            if (navigator.vibrate) navigator.vibrate(70);

            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                const context = new AudioContext();
                const oscillator = context.createOscillator();
                const gain = context.createGain();
                oscillator.frequency.value = 880;
                gain.gain.setValueAtTime(0.06, context.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.12);
                oscillator.connect(gain).connect(context.destination);
                oscillator.start();
                oscillator.stop(context.currentTime + 0.12);
            } catch {
                // Le retour visuel et la vibration restent disponibles.
            }
        }

        async function lookupBarcode(code) {
            const url = new URL(root.dataset.productsUrl, window.location.origin);
            url.searchParams.set('query', code);
            url.searchParams.set('mode', 'barcode');
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            return readJson(response);
        }

        async function searchProducts(query) {
            const url = new URL(root.dataset.productsUrl, window.location.origin);
            url.searchParams.set('query', query);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            return readJson(response);
        }

        function renderSearchResults(products) {
            searchResults.replaceChildren();
            searchResults.hidden = false;

            if (!products.length) {
                const empty = document.createElement('p');
                empty.className = 'sample-search-results__empty';
                empty.textContent = 'Aucun produit trouvé.';
                searchResults.append(empty);
                return;
            }

            products.forEach((product) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'sample-search-result';
                button.dataset.product = JSON.stringify(product);
                const copy = document.createElement('span');
                const title = document.createElement('strong');
                title.textContent = product.designation || product.reference;
                const detail = document.createElement('small');
                detail.textContent = `${product.reference}${product.barcode ? ` · ${product.barcode}` : ' · sans code-barres'}`;
                copy.append(title, detail);
                const plus = document.createElement('b');
                plus.textContent = '+';
                button.append(copy, plus);
                searchResults.append(button);
            });
        }

        searchForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const query = searchInput.value.trim();
            if (!query) return;
            const generation = draftGeneration;

            searchForm.classList.add('is-loading');
            try {
                const data = await searchProducts(query);
                if (generation !== draftGeneration || currentStep !== 1) return;
                renderSearchResults(data.products || []);
            } catch (error) {
                showToast(error.message, 'error');
                if (error.status === 401) window.location.reload();
            } finally {
                searchForm.classList.remove('is-loading');
            }
        });

        searchResults?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-product]');
            if (!button) return;
            addProduct(JSON.parse(button.dataset.product));
            searchInput.value = '';
            searchResults.hidden = true;
            searchInput.focus();
        });

        cartElement?.addEventListener('click', (event) => {
            if (submitting) return;
            const button = event.target.closest('[data-cart-action]');
            const row = event.target.closest('[data-reference]');
            if (!button || !row) return;
            const item = cart.find((candidate) => candidate.reference === row.dataset.reference);
            if (!item) return;

            if (button.dataset.cartAction === 'increase') item.quantity = Math.min(999, item.quantity + 1);
            if (button.dataset.cartAction === 'decrease') item.quantity -= 1;
            if (button.dataset.cartAction === 'remove' || item.quantity < 1) {
                cart = cart.filter((candidate) => candidate.reference !== item.reference);
            }
            renderCart();
        });

        cartElement?.addEventListener('input', (event) => {
            if (!event.target.matches('[data-expiry-date]')) return;
            const row = event.target.closest('[data-reference]');
            const item = cart.find((candidate) => candidate.reference === row.dataset.reference);
            if (item) {
                item.expiryDate = event.target.value;
                row.querySelector('[data-error-for]').textContent = '';
                saveCart();
            }
        });

        function validateProducts() {
            if (!cart.length) return false;
            return Array.from(cartElement.querySelectorAll('[data-expiry-date]')).every((input) => input.reportValidity());
        }

        function goToStep(step) {
            currentStep = step;
            root.querySelector('[data-progress]').hidden = step === 0 || step === 4;
            const selectedRepresentative = root.querySelector('[data-selected-representative]');
            selectedRepresentative.hidden = step === 0 || step === 4;
            selectedRepresentative.textContent = representativeSelect.value ? `Représentant : ${representativeSelect.selectedOptions[0].textContent}` : '';
            if (step === 3) root.querySelector('[data-submit-shipment]').disabled = submitting || !cart.length;
            root.querySelectorAll('[data-step]').forEach((panel) => {
                panel.classList.toggle('is-active', Number(panel.dataset.step) === step);
            });
            root.querySelectorAll('[data-progress-step]').forEach((indicator) => {
                const indicatorStep = Number(indicator.dataset.progressStep);
                indicator.classList.toggle('is-active', indicatorStep === step);
                indicator.classList.toggle('is-done', indicatorStep < step);
            });
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function recipientData() {
            return Object.fromEntries(Array.from(new FormData(recipientForm).entries()).map(([key, value]) => [key, value.trim()]));
        }

        function clearFieldErrors() {
            root.querySelectorAll('[data-error-for]').forEach((element) => {
                element.textContent = '';
            });
        }

        function renderReview() {
            root.querySelector('[data-review-representative]').textContent = `Représentant : ${representativeSelect.selectedOptions[0].textContent}`;
            const recipient = recipientData();
            const recipientBox = root.querySelector('[data-review-recipient]');
            const productsBox = root.querySelector('[data-review-products]');
            recipientBox.replaceChildren();
            productsBox.replaceChildren();

            const company = document.createElement('strong');
            company.textContent = recipient.company;
            const address = document.createElement('p');
            address.textContent = [recipient.contactName, recipient.address, recipient.addressComplement, `${recipient.postalCode} ${recipient.city}`, recipient.country]
                .filter(Boolean)
                .join('\n');
            const contacts = document.createElement('small');
            contacts.textContent = [recipient.phone, recipient.email].filter(Boolean).join(' · ');
            recipientBox.append(company, address);
            if (contacts.textContent) recipientBox.append(contacts);

            cart.forEach((item) => {
                const row = document.createElement('div');
                const copy = document.createElement('span');
                const title = document.createElement('strong');
                title.textContent = item.designation || item.reference;
                const reference = document.createElement('small');
                reference.textContent = `${item.reference} · Péremption : ${item.expiryDate.split('-').reverse().join('/')}`;
                copy.append(title, reference);
                const quantity = document.createElement('b');
                quantity.textContent = `× ${item.quantity}`;
                row.append(copy, quantity);
                productsBox.append(row);
            });
        }

        representativeForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!representativeForm.reportValidity()) return;
            goToStep(1);
            searchInput.focus();
        });
        root.querySelector('[data-back-representative]')?.addEventListener('click', () => {
            if (!submitting) goToStep(0);
        });
        root.querySelector('[data-next-products]')?.addEventListener('click', () => {
            if (validateProducts()) goToStep(2);
        });
        root.querySelector('[data-back-products]')?.addEventListener('click', () => { if (!submitting) goToStep(1); });
        root.querySelector('[data-back-recipient]')?.addEventListener('click', () => { if (!submitting) goToStep(2); });
        root.querySelector('[data-next-recipient]')?.addEventListener('click', () => {
            clearFieldErrors();
            if (!recipientForm.reportValidity()) return;
            renderReview();
            goToStep(3);
        });

        function requestToken() {
            if (!activeRequestToken) {
                activeRequestToken = localStorage.getItem(TOKEN_KEY) || window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
                localStorage.setItem(TOKEN_KEY, activeRequestToken);
            }
            return activeRequestToken;
        }

        function resetDraft() {
            draftGeneration += 1;
            closeCamera();
            cart = [];
            activeRequestToken = null;
            localStorage.removeItem(TOKEN_KEY);
            recipientForm.reset();
            representativeForm.reset();
            searchForm.reset();
            searchResults.replaceChildren();
            searchResults.hidden = true;
            root.querySelector('[data-review-recipient]').replaceChildren();
            root.querySelector('[data-review-products]').replaceChildren();
            root.querySelector('[data-review-representative]').textContent = '';
            root.querySelector('[data-selected-representative]').textContent = '';
            root.querySelector('[data-submit-error]').hidden = true;
            root.querySelector('[data-submit-error]').textContent = '';
            root.querySelector('[data-success-reference]').textContent = '—';
            root.querySelector('[data-success-sage]').textContent = '';
            root.querySelector('[data-success-sage]').hidden = true;
            lastScannedCode = '';
            lastScannedAt = 0;
            clearFieldErrors();
            renderCart();
        }

        root.querySelector('[data-submit-shipment]')?.addEventListener('click', async () => {
            if (submitting || currentStep !== 3 || !cart.length) return;
            if (!representativeForm.reportValidity()) { goToStep(0); return; }
            if (!validateProducts()) { goToStep(1); return; }
            if (!recipientForm.reportValidity()) { goToStep(2); return; }
            submitting = true;
            const generation = draftGeneration;
            const button = root.querySelector('[data-submit-shipment]');
            const submitError = root.querySelector('[data-submit-error]');
            const label = root.querySelector('[data-submit-label]');
            clearFieldErrors();
            submitError.hidden = true;
            button.disabled = true;
            button.classList.add('is-loading');
            label.textContent = 'Création en cours…';

            try {
                const response = await fetch(root.dataset.submitUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        ...recipientData(),
                        requestToken: requestToken(),
                        csrfToken: root.dataset.submitCsrf,
                        representativeId: representativeSelect.value,
                        lines: cart.map((item) => ({ reference: item.reference, quantity: item.quantity, expiryDate: item.expiryDate })),
                    }),
                });
                const data = await readJson(response);
                if (generation !== draftGeneration) return;
                resetDraft();
                root.querySelector('[data-success-reference]').textContent = data.reference;
                const sageDocument = root.querySelector('[data-success-sage]');
                if (data.sageDocument) {
                    sageDocument.textContent = `Document Sage : ${data.sageDocument}`;
                    sageDocument.hidden = false;
                }
                goToStep(4);
            } catch (error) {
                if (generation !== draftGeneration) return;
                if (error.status === 401) {
                    window.location.reload();
                    return;
                }

                const errors = error.data?.errors || {};
                Object.entries(errors).forEach(([field, message]) => {
                    const target = root.querySelector(`[data-error-for="${CSS.escape(field)}"]`);
                    if (target) target.textContent = message;
                });
                submitError.textContent = error.message;
                submitError.hidden = false;
            } finally {
                submitting = false;
                button.disabled = currentStep !== 3 || !cart.length;
                button.classList.remove('is-loading');
                label.textContent = 'Créer le BC Sage';
            }
        });

        root.querySelector('[data-new-shipment]')?.addEventListener('click', () => {
            goToStep(0);
            representativeSelect.focus();
        });

        window.addEventListener('storage', (event) => {
            if (event.key === TOKEN_KEY && event.newValue === null && (!activeRequestToken || event.oldValue === activeRequestToken)) {
                resetDraft();
                goToStep(0);
            }
        });
        window.addEventListener('pageshow', (event) => {
            if (event.persisted && !localStorage.getItem(CART_KEY)) {
                resetDraft();
                goToStep(0);
            }
        });

        async function openCamera() {
            cameraModal.hidden = false;
            document.body.classList.add('sample-modal-open');
            cameraStatus.textContent = 'Autorisation de la caméra en cours…';
            cameraStatus.dataset.type = '';

            try {
                scannerControls = await codeReader.decodeFromVideoDevice(undefined, cameraVideo, async (result) => {
                    if (!result || scannerBusy) return;
                    const code = result.getText().trim();
                    const now = Date.now();
                    if (!code || (code === lastScannedCode && now - lastScannedAt < 1500)) return;
                    lastScannedCode = code;
                    lastScannedAt = now;
                    scannerBusy = true;
                    const generation = draftGeneration;
                    cameraStatus.textContent = `Code détecté : ${code}`;

                    try {
                        const data = await lookupBarcode(code);
                        if (generation !== draftGeneration || currentStep !== 1) return;
                        addProduct(data.product);
                        cameraStatus.textContent = `${data.product.reference} ajouté · continuez à scanner`;
                        cameraStatus.dataset.type = 'success';
                    } catch (error) {
                        cameraStatus.textContent = error.message;
                        cameraStatus.dataset.type = 'error';
                        if (error.status === 401) window.location.reload();
                    } finally {
                        window.setTimeout(() => {
                            scannerBusy = false;
                        }, 450);
                    }
                });
                cameraStatus.textContent = 'Caméra active · présentez un code-barres';
            } catch {
                cameraStatus.textContent = 'Impossible d ouvrir la caméra. Vérifiez son autorisation ou utilisez la saisie manuelle.';
                cameraStatus.dataset.type = 'error';
            }
        }

        function closeCamera() {
            scannerControls?.stop();
            scannerControls = null;
            cameraVideo.srcObject = null;
            cameraModal.hidden = true;
            document.body.classList.remove('sample-modal-open');
            searchInput.focus();
        }

        root.querySelector('[data-open-camera]')?.addEventListener('click', openCamera);
        root.querySelectorAll('[data-close-camera]').forEach((button) => button.addEventListener('click', closeCamera));

        const updateOnlineStatus = () => {
            const status = root.querySelector('[data-online-status]');
            if (!status) return;
            status.classList.toggle('is-offline', !navigator.onLine);
            status.querySelector('strong').textContent = navigator.onLine ? 'En ligne' : 'Hors ligne';
        };
        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
        updateOnlineStatus();
        renderCart();
        goToStep(currentStep);
    }
}
