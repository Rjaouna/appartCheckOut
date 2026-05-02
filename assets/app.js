import './stimulus_bootstrap.js';
import './styles/app.css';

document.documentElement.classList.remove('app-shell-pending');

let pendingConfirmationForm = null;
let activeDragButton = null;
let dragOffsetY = 0;
let dashboardPollTimer = null;
let dashboardPollInFlight = false;
let lastKnownDashboardCheckoutCount = null;
let dashboardPollingStarted = false;
let deferredInstallPrompt = null;
let plainModalBackdrop = null;

restorePendingToast();
restoreFloatingMenuPosition();
syncTopBarOnScroll();
registerServiceWorker();
setupInstallPrompt();
syncPanelTriggers(document);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        startDashboardPolling();
        syncTopBarOnScroll();
        syncPanelTriggers(document);
    });
} else {
    startDashboardPolling();
    syncTopBarOnScroll();
    syncPanelTriggers(document);
}

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-async-form')) {
        return;
    }

    if (form.hasAttribute('data-confirm-reset') && form.dataset.confirmed !== 'true') {
        event.preventDefault();
        openConfirmationModal(form);
        return;
    }

    if (form.dataset.confirmed === 'true') {
        delete form.dataset.confirmed;
    }

    event.preventDefault();

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
    }

    const formData = new FormData(form);

    try {
        const response = await fetch(form.action, {
            method: form.method || 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok || !payload?.success) {
            throw new Error(payload?.message || 'Une erreur est survenue.');
        }

        if (payload.redirect) {
            if (payload.message) {
                storePendingToast(payload.message, 'success');
            }
            window.location.href = payload.redirect;
            return;
        }

        if (form.hasAttribute('data-reload-on-success')) {
            window.location.reload();
            return;
        }

        const modalToClose = form.getAttribute('data-close-modal');
        if (modalToClose) {
            hideModal(modalToClose);
        }

        const targetSelector = form.getAttribute('data-update-target');
        if (targetSelector && payload.html) {
            const target = document.querySelector(targetSelector);
            if (target) {
                const uiState = captureUiState(target);
                collapseEditableForm(form);

                if (form.getAttribute('data-swap-mode') === 'outer') {
                    target.outerHTML = payload.html;
                } else {
                    target.innerHTML = payload.html;
                }

                restoreUiState(targetSelector, uiState);
            }
        }

        if (form.hasAttribute('data-reset-form')) {
            form.reset();
        }

        hideModal('workflowActionModal');
        hideModal('actionMenuModal');
        showToast(payload?.message || 'Opération terminée.');
    } catch (error) {
        showToast(error.message || 'Opération impossible.', 'error');
    } finally {
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
        }
    }
});

function openConfirmationModal(form) {
    const modal = ensureConfirmModal();
    const { modalElement, titleElement, bodyElement, confirmButton } = modal;

    if (!(modalElement instanceof HTMLElement) || !(titleElement instanceof HTMLElement) || !(bodyElement instanceof HTMLElement) || !(confirmButton instanceof HTMLButtonElement)) {
        form.dataset.confirmed = 'true';
        form.requestSubmit();
        return;
    }

    pendingConfirmationForm = form;
    titleElement.textContent = form.dataset.confirmTitle || "Confirmer l'action";
    bodyElement.textContent = form.dataset.confirmMessage || 'Veux-tu vraiment continuer ?';

    showModalElement(modalElement);
}

function openWorkflowModal(trigger) {
    const sourceSelector = trigger.getAttribute('data-workflow-source');
    const source = sourceSelector ? document.querySelector(sourceSelector) : null;
    const modal = ensureContentModal('workflowActionModal', 'workflowActionModalLabel', 'workflowActionModalContent', 'Suivi du dossier');
    const { modalElement, titleElement, contentElement } = modal;

    if (!(source instanceof HTMLElement) || !(modalElement instanceof HTMLElement) || !(titleElement instanceof HTMLElement) || !(contentElement instanceof HTMLElement)) {
        return;
    }

    titleElement.textContent = trigger.getAttribute('data-workflow-title') || 'Suivi du dossier';
    contentElement.innerHTML = source.innerHTML;
    showModalElement(modalElement);
}

function openActionMenuModal(trigger) {
    if (trigger instanceof HTMLElement && trigger.dataset.dragMoved === 'true') {
        return;
    }

    const sourceSelector = trigger.getAttribute('data-action-menu-source');
    const source = sourceSelector ? document.querySelector(sourceSelector) : null;
    const modal = ensureContentModal('actionMenuModal', 'actionMenuModalLabel', 'actionMenuModalContent', 'Actions');
    const { modalElement, titleElement, contentElement } = modal;

    if (!(source instanceof HTMLElement) || !(modalElement instanceof HTMLElement) || !(titleElement instanceof HTMLElement) || !(contentElement instanceof HTMLElement)) {
        return;
    }

    titleElement.textContent = trigger.getAttribute('data-action-menu-title') || 'Actions appartement';
    contentElement.innerHTML = source.innerHTML;
    showModalElement(modalElement);
}

function openPhotoLightbox(trigger) {
    const src = trigger.getAttribute('data-lightbox-src');
    const alt = trigger.getAttribute('data-lightbox-alt') || 'Photo';
    const modal = ensurePhotoModal();
    const { modalElement, imageElement, titleElement } = modal;

    if (!src || !(modalElement instanceof HTMLElement) || !(imageElement instanceof HTMLImageElement) || !(titleElement instanceof HTMLElement)) {
        return;
    }

    imageElement.src = src;
    imageElement.alt = alt;
    titleElement.textContent = alt;
    showModalElement(modalElement);
}

function openApartmentNameModal() {
    const modalElement = document.getElementById('apartmentNameModal');
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    showModalElement(modalElement);

    window.setTimeout(() => {
        const input = modalElement.querySelector('[data-apartment-name-modal-input]');
        if (input instanceof HTMLInputElement) {
            input.focus();
            input.select();
        }
    }, 120);
}

function addApartmentAccessStepDraft(trigger) {
    const targetSelector = trigger.getAttribute('data-target-list');
    const templateSelector = trigger.getAttribute('data-template');
    const targetList = targetSelector ? document.querySelector(targetSelector) : null;
    const template = templateSelector ? document.querySelector(templateSelector) : null;
    if (!(targetList instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
        return;
    }

    const fragment = template.content.cloneNode(true);
    targetList.appendChild(fragment);

    const latestInput = targetList.querySelector('.apartment-access-step-card-draft:last-child textarea');
    if (latestInput instanceof HTMLTextAreaElement) {
        latestInput.focus();
    }
}

function openPublicAccessStepsModal(trigger) {
    const sourceSelector = trigger.getAttribute('data-step-source');
    const source = sourceSelector ? document.querySelector(sourceSelector) : null;
    const modalElement = document.getElementById('publicAccessStepsModal');
    if (!(source instanceof HTMLElement) || !(modalElement instanceof HTMLElement)) {
        return;
    }

    const steps = Array.from(source.querySelectorAll('[data-public-access-step]'))
        .map((stepElement) => {
            if (!(stepElement instanceof HTMLElement)) {
                return null;
            }

            return {
                title: stepElement.dataset.stepTitle || '',
                text: stepElement.dataset.stepText || '',
                image: stepElement.dataset.stepImage || '',
            };
        })
        .filter((step) => step !== null);

    if (steps.length === 0) {
        return;
    }

    modalElement.dataset.publicAccessSteps = JSON.stringify(steps);
    modalElement.dataset.publicAccessIndex = '0';
    renderPublicAccessStep(modalElement);
    showModalElement(modalElement);
}

function renderPublicAccessStep(modalElement) {
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    const rawSteps = modalElement.dataset.publicAccessSteps;
    if (!rawSteps) {
        return;
    }

    let steps = [];
    try {
        steps = JSON.parse(rawSteps);
    } catch (error) {
        return;
    }

    if (!Array.isArray(steps) || steps.length === 0) {
        return;
    }

    const index = Math.min(Math.max(Number.parseInt(modalElement.dataset.publicAccessIndex || '0', 10), 0), steps.length - 1);
    const currentStep = steps[index];
    if (!currentStep || typeof currentStep !== 'object') {
        return;
    }

    const label = modalElement.querySelector('[data-public-access-step-label]');
    const count = modalElement.querySelector('[data-public-access-step-count]');
    const text = modalElement.querySelector('[data-public-access-text]');
    const image = modalElement.querySelector('[data-public-access-image]');
    const figure = modalElement.querySelector('[data-public-access-figure]');
    const prevButton = modalElement.querySelector('[data-public-access-prev]');
    const nextButton = modalElement.querySelector('[data-public-access-next]');

    if (label instanceof HTMLElement) {
        label.textContent = currentStep.title || `Étape ${index + 1}`;
    }
    if (count instanceof HTMLElement) {
        count.textContent = `${index + 1} / ${steps.length}`;
    }
    if (text instanceof HTMLElement) {
        text.textContent = typeof currentStep.text === 'string' ? currentStep.text : '';
    }
    if (image instanceof HTMLImageElement && figure instanceof HTMLElement) {
        if (typeof currentStep.image === 'string' && currentStep.image !== '') {
            image.src = currentStep.image;
            image.alt = currentStep.title || `Étape ${index + 1}`;
            figure.hidden = false;
        } else {
            image.src = '';
            image.alt = '';
            figure.hidden = true;
        }
    }
    if (prevButton instanceof HTMLButtonElement) {
        prevButton.disabled = index === 0;
    }
    if (nextButton instanceof HTMLButtonElement) {
        nextButton.textContent = index >= steps.length - 1 ? 'Fermer' : 'Suivant';
    }

    modalElement.dataset.publicAccessIndex = String(index);
}

function navigatePublicAccessSteps(direction) {
    const modalElement = document.getElementById('publicAccessStepsModal');
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    const rawSteps = modalElement.dataset.publicAccessSteps;
    if (!rawSteps) {
        return;
    }

    let steps = [];
    try {
        steps = JSON.parse(rawSteps);
    } catch (error) {
        return;
    }

    if (!Array.isArray(steps) || steps.length === 0) {
        return;
    }

    const currentIndex = Number.parseInt(modalElement.dataset.publicAccessIndex || '0', 10);
    if (direction === 'next') {
        if (currentIndex >= steps.length - 1) {
            hideModal('publicAccessStepsModal');
            return;
        }

        modalElement.dataset.publicAccessIndex = String(currentIndex + 1);
    } else if (direction === 'prev' && currentIndex > 0) {
        modalElement.dataset.publicAccessIndex = String(currentIndex - 1);
    }

    renderPublicAccessStep(modalElement);
}

function hideModal(id) {
    const modalElement = document.getElementById(id);
    if (modalElement instanceof HTMLElement) {
        hideModalElement(modalElement);
    }
}

function scrollToTop() {
    window.scrollTo({top: 0, behavior: 'smooth'});
}

document.addEventListener('click', (event) => {
    const dismissTrigger = event.target instanceof Element ? event.target.closest('[data-bs-dismiss="modal"], [data-modal-close]') : null;
    if (dismissTrigger instanceof HTMLElement) {
        const modalElement = dismissTrigger.closest('.modal');
        if (modalElement instanceof HTMLElement) {
            hideModalElement(modalElement);
        }
        return;
    }

    const scrollTopTrigger = event.target instanceof Element ? event.target.closest('#scroll-to-top-button') : null;
    if (scrollTopTrigger instanceof HTMLButtonElement) {
        scrollToTop();
        return;
    }

    const confirmButton = event.target instanceof Element ? event.target.closest('#confirmActionModalSubmit') : null;
    if (confirmButton instanceof HTMLButtonElement && pendingConfirmationForm instanceof HTMLFormElement) {
        confirmButton.blur();
        pendingConfirmationForm.dataset.confirmed = 'true';
        hideModal('confirmActionModal');

        const formToSubmit = pendingConfirmationForm;
        pendingConfirmationForm = null;
        formToSubmit.requestSubmit();
        return;
    }

    const toggleTrigger = event.target instanceof Element ? event.target.closest('[data-toggle-target]') : null;
    if (toggleTrigger instanceof HTMLButtonElement) {
        const targetSelector = toggleTrigger.getAttribute('data-toggle-target');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            const isCollapsed = target.classList.toggle('is-collapsed');
            toggleTrigger.textContent = isCollapsed
                ? (toggleTrigger.getAttribute('data-toggle-label-closed') || 'Afficher')
                : (toggleTrigger.getAttribute('data-toggle-label-open') || 'Masquer');
        }
        return;
    }

    const editToggleTrigger = event.target instanceof Element ? event.target.closest('[data-edit-target]') : null;
    if (editToggleTrigger instanceof HTMLButtonElement) {
        const targetSelector = editToggleTrigger.getAttribute('data-edit-target');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            const parentCard = target.closest('.apartment-detail-card');
            if (parentCard instanceof HTMLElement) {
                parentCard.querySelectorAll('.editable-field-form').forEach((formElement) => {
                    if (formElement instanceof HTMLElement && formElement !== target) {
                        formElement.classList.add('is-collapsed');
                        formElement.hidden = true;
                    }
                });
            }

            target.classList.toggle('is-collapsed');
            target.hidden = target.classList.contains('is-collapsed');
        }
        return;
    }

    const panelOpenTrigger = event.target instanceof Element ? event.target.closest('[data-panel-open]') : null;
    if (panelOpenTrigger instanceof HTMLButtonElement) {
        const groupName = panelOpenTrigger.getAttribute('data-panel-group');
        const targetSelector = panelOpenTrigger.getAttribute('data-panel-open');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            if (groupName) {
                document.querySelectorAll(`[data-panel-name][data-panel-group="${groupName}"]`).forEach((panel) => {
                    if (panel instanceof HTMLElement) {
                        panel.classList.add('is-collapsed');
                    }
                });
            }

            target.classList.remove('is-collapsed');
            setActivePanelTrigger(groupName, targetSelector);
            target.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
        return;
    }

    const panelCloseTrigger = event.target instanceof Element ? event.target.closest('[data-panel-close]') : null;
    if (panelCloseTrigger instanceof HTMLButtonElement) {
        const targetSelector = panelCloseTrigger.getAttribute('data-panel-close');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            target.classList.add('is-collapsed');
            syncPanelTriggers(document);
        }
        return;
    }

    const actionMenuTrigger = event.target instanceof Element ? event.target.closest('[data-action-menu-trigger]') : null;
    if (actionMenuTrigger instanceof HTMLElement) {
        openActionMenuModal(actionMenuTrigger);
        return;
    }

    const workflowTrigger = event.target instanceof Element ? event.target.closest('[data-workflow-trigger]') : null;
    if (workflowTrigger instanceof HTMLElement) {
        openWorkflowModal(workflowTrigger);
        return;
    }

    const photoTrigger = event.target instanceof Element ? event.target.closest('[data-lightbox-src]') : null;
    if (photoTrigger instanceof HTMLElement) {
        openPhotoLightbox(photoTrigger);
        return;
    }

    const apartmentNameModalTrigger = event.target instanceof Element ? event.target.closest('[data-apartment-name-modal-trigger]') : null;
    if (apartmentNameModalTrigger instanceof HTMLElement) {
        event.preventDefault();
        openApartmentNameModal();
        return;
    }

    const managerListToggle = event.target instanceof Element ? event.target.closest('[data-manager-list-toggle]') : null;
    if (managerListToggle instanceof HTMLElement) {
        event.preventDefault();
        const targetSelector = managerListToggle.getAttribute('data-target');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            const willExpand = target.hidden || target.classList.contains('is-collapsed');
            target.hidden = !willExpand;
            target.classList.toggle('is-collapsed', !willExpand);
            managerListToggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
        }
        return;
    }

    const addAccessStepTrigger = event.target instanceof Element ? event.target.closest('[data-add-access-step]') : null;
    if (addAccessStepTrigger instanceof HTMLElement) {
        event.preventDefault();
        addApartmentAccessStepDraft(addAccessStepTrigger);
        return;
    }

    const removeAccessStepDraftTrigger = event.target instanceof Element ? event.target.closest('[data-remove-access-step-draft]') : null;
    if (removeAccessStepDraftTrigger instanceof HTMLElement) {
        event.preventDefault();
        const draftCard = removeAccessStepDraftTrigger.closest('.apartment-access-step-card-draft');
        if (draftCard instanceof HTMLElement) {
            draftCard.remove();
        }
        return;
    }

    const publicAccessModalTrigger = event.target instanceof Element ? event.target.closest('[data-public-access-modal-trigger]') : null;
    if (publicAccessModalTrigger instanceof HTMLElement) {
        event.preventDefault();
        openPublicAccessStepsModal(publicAccessModalTrigger);
        return;
    }

    const publicAccessPrevTrigger = event.target instanceof Element ? event.target.closest('[data-public-access-prev]') : null;
    if (publicAccessPrevTrigger instanceof HTMLElement) {
        event.preventDefault();
        navigatePublicAccessSteps('prev');
        return;
    }

    const publicAccessNextTrigger = event.target instanceof Element ? event.target.closest('[data-public-access-next]') : null;
    if (publicAccessNextTrigger instanceof HTMLElement) {
        event.preventDefault();
        navigatePublicAccessSteps('next');
        return;
    }

    const employeeEntryTrigger = event.target instanceof Element ? event.target.closest('[data-employee-entry-trigger]') : null;
    if (employeeEntryTrigger instanceof HTMLElement) {
        event.preventDefault();
        openEmployeeEntryModal();
        return;
    }

    const employeeEntryDigit = event.target instanceof Element ? event.target.closest('[data-employee-entry-digit]') : null;
    if (employeeEntryDigit instanceof HTMLElement) {
        event.preventDefault();
        const digit = employeeEntryDigit.getAttribute('data-employee-entry-digit') || '';
        if (digit !== '') {
            appendEmployeeEntryDigit(digit);
        }
        return;
    }

    const employeeEntryClear = event.target instanceof Element ? event.target.closest('[data-employee-entry-clear]') : null;
    if (employeeEntryClear instanceof HTMLElement) {
        event.preventDefault();
        clearEmployeeEntry();
        return;
    }

    const employeeEntryBackspace = event.target instanceof Element ? event.target.closest('[data-employee-entry-backspace]') : null;
    if (employeeEntryBackspace instanceof HTMLElement) {
        event.preventDefault();
        backspaceEmployeeEntry();
        return;
    }

    const card = event.target instanceof Element ? event.target.closest('[data-click-url]') : null;
    if (!(card instanceof HTMLElement)) {
        return;
    }

    if (event.target instanceof Element && event.target.closest('a, button, form, input, select, textarea, label')) {
        return;
    }

    const url = card.dataset.clickUrl;
    if (url) {
        window.location.href = url;
    }
});

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-auto-submit-file')) {
        return;
    }

    if (!input.files || input.files.length === 0) {
        return;
    }

    const form = input.closest('form');
    if (form instanceof HTMLFormElement) {
        form.requestSubmit();
    }
});

document.addEventListener('pointerdown', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-draggable-y]') : null;
    if (!(button instanceof HTMLElement)) {
        return;
    }

    activeDragButton = button;
    const rect = button.getBoundingClientRect();
    dragOffsetY = event.clientY - rect.top;
    button.setPointerCapture?.(event.pointerId);
    button.dataset.dragMoved = 'false';
});

document.addEventListener('pointermove', (event) => {
    if (!(activeDragButton instanceof HTMLElement)) {
        return;
    }

    const height = activeDragButton.offsetHeight;
    const minTop = 12;
    const maxTop = window.innerHeight - height - 12;
    const nextTop = Math.min(Math.max(event.clientY - dragOffsetY, minTop), maxTop);

    activeDragButton.style.top = `${nextTop}px`;
    activeDragButton.style.bottom = 'auto';
    activeDragButton.style.transform = 'none';
    activeDragButton.dataset.dragMoved = 'true';
});

document.addEventListener('pointerup', (event) => {
    if (!(activeDragButton instanceof HTMLElement)) {
        return;
    }

    activeDragButton.releasePointerCapture?.(event.pointerId);
    storeFloatingMenuPosition(activeDragButton.style.top);
    window.setTimeout(() => {
        if (activeDragButton instanceof HTMLElement) {
            delete activeDragButton.dataset.dragMoved;
        }
    }, 0);
    activeDragButton = null;
});

document.addEventListener('pointercancel', () => {
    activeDragButton = null;
});

window.addEventListener('scroll', syncTopBarOnScroll, {passive: true});

document.addEventListener('hidden.bs.modal', (event) => {
    if (event.target instanceof HTMLElement && event.target.id === 'confirmActionModal') {
        pendingConfirmationForm = null;
    }
});

function showToast(message, type = 'success') {
    const stack = document.getElementById('toast-stack');
    if (!stack) {
        return;
    }

    const toast = document.createElement('div');
    toast.className = `app-toast ${type === 'error' ? 'app-toast-error' : ''}`;
    toast.textContent = message;
    stack.appendChild(toast);

    window.setTimeout(() => {
        toast.classList.add('app-toast-hide');
        window.setTimeout(() => toast.remove(), 300);
    }, 2600);
}

function storePendingToast(message, type = 'success') {
    try {
        sessionStorage.setItem('pending-toast', JSON.stringify({message, type}));
    } catch (error) {
        // ignore storage failures
    }
}

function restorePendingToast() {
    try {
        const raw = sessionStorage.getItem('pending-toast');
        if (!raw) {
            return;
        }

        sessionStorage.removeItem('pending-toast');
        const payload = JSON.parse(raw);
        if (payload?.message) {
            window.setTimeout(() => showToast(payload.message, payload.type || 'success'), 120);
        }
    } catch (error) {
        // ignore storage failures
    }
}

function storeFloatingMenuPosition(top) {
    try {
        if (top) {
            localStorage.setItem('floating-main-menu-top', top);
        }
    } catch (error) {
        // ignore storage failures
    }
}

function restoreFloatingMenuPosition() {
    const button = document.getElementById('floating-main-menu-button');
    if (!(button instanceof HTMLElement)) {
        return;
    }
    button.style.top = 'auto';
    button.style.bottom = 'auto';
    button.style.transform = 'none';
}

function syncTopBarOnScroll() {
    const shouldCompactNav = window.scrollY > 72;
    document.body.classList.toggle('nav-compact', shouldCompactNav);

    const scrollTopButton = document.getElementById('scroll-to-top-button');
    if (scrollTopButton instanceof HTMLElement) {
        scrollTopButton.classList.toggle('is-visible', window.scrollY > 280);
    }
}

function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }

    const hostname = window.location.hostname;
    const isLocalHost = hostname === '127.0.0.1' || hostname === 'localhost' || hostname === '::1';
    if (isLocalHost) {
        navigator.serviceWorker.getRegistrations().then((registrations) => {
            registrations.forEach((registration) => {
                registration.unregister().catch(() => {
                    // ignore unregister errors
                });
            });
        }).catch(() => {
            // ignore registration lookup failures
        });
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // ignore service worker registration errors
        });
    }, {once: true});
}

function setupInstallPrompt() {
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt = event;
        updateInstallAppButton();
    });

    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt = null;
        updateInstallAppButton();
        showToast('Application installée avec succès.');
    });

    document.addEventListener('click', async (event) => {
        const installButton = event.target instanceof Element ? event.target.closest('[data-install-app-trigger]') : null;
        if (!(installButton instanceof HTMLButtonElement)) {
            return;
        }

        if (deferredInstallPrompt) {
            deferredInstallPrompt.prompt();
            await deferredInstallPrompt.userChoice.catch(() => null);
            deferredInstallPrompt = null;
            updateInstallAppButton();
            return;
        }

        if (isIosStandaloneInstallAvailable()) {
            openInstallHelpModal();
            return;
        }

        showToast('Installation non disponible dans ce navigateur.', 'error');
    });

    updateInstallAppButton();
}

function updateInstallAppButton() {
    const shouldShow = deferredInstallPrompt !== null || isIosStandaloneInstallAvailable();
    document.querySelectorAll('[data-install-app-trigger]').forEach((button) => {
        if (button instanceof HTMLElement) {
            button.hidden = !shouldShow;
        }
    });
}

function isIosStandaloneInstallAvailable() {
    const ua = window.navigator.userAgent.toLowerCase();
    const isIos = /iphone|ipad|ipod/.test(ua);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

    return isIos && !isStandalone;
}

function openInstallHelpModal() {
    const modal = ensureInstallHelpModal();
    const { modalElement } = modal;

    if (!(modalElement instanceof HTMLElement)) {
        showToast('Sur iPhone : Partager puis Sur l’écran d’accueil.');
        return;
    }

    showModalElement(modalElement);
}

function openEmployeeEntryModal() {
    const modalElement = document.getElementById('employeeEntryModal');
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    resetEmployeeEntryModal();
    showModalElement(modalElement);
}

function resetEmployeeEntryModal() {
    const input = document.querySelector('[data-employee-entry-input]');
    if (input instanceof HTMLInputElement) {
        input.value = '';
    }

    syncEmployeeEntryDots();
}

function appendEmployeeEntryDigit(digit) {
    const input = document.querySelector('[data-employee-entry-input]');
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    input.value = `${input.value}${digit}`.replace(/\D+/g, '').slice(0, 6);
    syncEmployeeEntryDots();
}

function clearEmployeeEntry() {
    const input = document.querySelector('[data-employee-entry-input]');
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    input.value = '';
    syncEmployeeEntryDots();
}

function backspaceEmployeeEntry() {
    const input = document.querySelector('[data-employee-entry-input]');
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    input.value = input.value.slice(0, -1);
    syncEmployeeEntryDots();
}

function syncEmployeeEntryDots() {
    const input = document.querySelector('[data-employee-entry-input]');
    const dots = document.querySelectorAll('[data-employee-entry-dots] .employee-entry-dot');
    if (!(input instanceof HTMLInputElement) || dots.length === 0) {
        return;
    }

    const filledCount = input.value.length;
    dots.forEach((dot, index) => {
        if (dot instanceof HTMLElement) {
            dot.classList.toggle('is-filled', index < filledCount);
        }
    });
}

function startDashboardPolling() {
    if (dashboardPollingStarted) {
        return;
    }

    const pollSource = document.getElementById('employee-dashboard-poll');
    if (!(pollSource instanceof HTMLElement)) {
        return;
    }

    const pollUrl = pollSource.dataset.pollUrl;
    const pollTargetSelector = pollSource.dataset.pollTarget || '#employee-dashboard-content';
    const interval = Number.parseInt(pollSource.dataset.pollInterval || '10000', 10);
    if (!pollUrl) {
        return;
    }

    dashboardPollingStarted = true;

    const initialTarget = document.querySelector(pollTargetSelector);
    if (initialTarget instanceof HTMLElement) {
        const initialCount = Number.parseInt(initialTarget.dataset.checkoutCount || '', 10);
        lastKnownDashboardCheckoutCount = Number.isNaN(initialCount) ? null : initialCount;
    }

    const tick = async () => {
        if (dashboardPollInFlight || document.hidden) {
            return;
        }

        const target = document.querySelector(pollTargetSelector);
        if (!(target instanceof HTMLElement)) {
            return;
        }

        dashboardPollInFlight = true;
        try {
            const response = await fetch(pollUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                },
            });

            if (!response.ok) {
                return;
            }

            const html = await response.text();
            if (html.trim() !== '' && target.outerHTML.trim() !== html.trim()) {
                const nextCountMatch = html.match(/data-checkout-count="(\d+)"/);
                const nextCount = nextCountMatch ? Number.parseInt(nextCountMatch[1], 10) : null;
                target.outerHTML = html;

                if (
                    nextCount !== null
                    && lastKnownDashboardCheckoutCount !== null
                    && nextCount > lastKnownDashboardCheckoutCount
                ) {
                    showToast('Nouveau check-out assigne.');
                }

                if (nextCount !== null) {
                    lastKnownDashboardCheckoutCount = nextCount;
                }
            }
        } catch (error) {
            // ignore polling failures
        } finally {
            dashboardPollInFlight = false;
        }
    };

    tick();
    dashboardPollTimer = window.setInterval(tick, Number.isNaN(interval) ? 5000 : interval);
    window.addEventListener('focus', tick);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            tick();
        }
    });
}

function collapseEditableForm(form) {
    if (!(form instanceof HTMLElement) || !form.classList.contains('editable-field-form')) {
        return;
    }

    form.classList.add('is-collapsed');
    form.hidden = true;
}

function captureUiState(target) {
    if (!(target instanceof HTMLElement)) {
        return null;
    }

    return {
        scrollY: window.scrollY,
        openPanels: Array.from(target.querySelectorAll('[data-panel-name]:not(.is-collapsed)'))
            .map((panel) => panel instanceof HTMLElement ? `#${panel.id}` : null)
            .filter((selector) => typeof selector === 'string'),
    };
}

function restoreUiState(targetSelector, uiState) {
    if (!uiState) {
        return;
    }

    window.requestAnimationFrame(() => {
        const target = document.querySelector(targetSelector);
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (Array.isArray(uiState.openPanels) && uiState.openPanels.length > 0) {
            uiState.openPanels.forEach((panelSelector) => {
                const panel = target.querySelector(panelSelector);
                if (panel instanceof HTMLElement) {
                    panel.classList.remove('is-collapsed');
                }
            });
        }

        syncPanelTriggers(target);

        if (typeof uiState.scrollY === 'number') {
            window.scrollTo({top: uiState.scrollY});
        }
    });
}

function setActivePanelTrigger(groupName, targetSelector) {
    if (!groupName || !targetSelector) {
        return;
    }

    document.querySelectorAll(`[data-panel-open][data-panel-group="${groupName}"]`).forEach((trigger) => {
        if (!(trigger instanceof HTMLElement)) {
            return;
        }

        trigger.classList.toggle('is-active', trigger.getAttribute('data-panel-open') === targetSelector);
    });
}

function syncPanelTriggers(scope = document) {
    if (!scope || typeof scope.querySelectorAll !== 'function') {
        return;
    }

    const processedGroups = new Set();
    scope.querySelectorAll('[data-panel-name][data-panel-group]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
            return;
        }

        const groupName = panel.getAttribute('data-panel-group');
        if (!groupName || processedGroups.has(groupName)) {
            return;
        }

        processedGroups.add(groupName);
        const openPanel = document.querySelector(`[data-panel-name][data-panel-group="${groupName}"]:not(.is-collapsed)`);
        const targetSelector = openPanel instanceof HTMLElement ? `#${openPanel.id}` : null;
        setActivePanelTrigger(groupName, targetSelector);
    });
}

function ensureContentModal(modalId, titleId, contentId, defaultTitle) {
    let modalElement = document.getElementById(modalId);
    if (!(modalElement instanceof HTMLElement)) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${titleId}" aria-hidden="true">
                <div class="modal-dialog modal-fullscreen">
                    <div class="modal-content action-menu-modal-content">
                        <div class="modal-header action-menu-modal-header">
                            <h4 class="modal-title" id="${titleId}">${defaultTitle}</h4>
                            <button type="button" class="btn-close" aria-label="Fermer" data-modal-close></button>
                        </div>
                        <div class="modal-body action-menu-modal-body">
                            <div id="${contentId}"></div>
                        </div>
                    </div>
                </div>
            </div>
        `.trim();
        modalElement = wrapper.firstElementChild;
        if (modalElement instanceof HTMLElement) {
            document.body.appendChild(modalElement);
        }
    }

    return {
        modalElement,
        titleElement: document.getElementById(titleId),
        contentElement: document.getElementById(contentId),
    };
}

function ensureConfirmModal() {
    let modalElement = document.getElementById('confirmActionModal');
    if (!(modalElement instanceof HTMLElement)) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="confirmActionModal" tabindex="-1" aria-labelledby="confirmActionModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="confirmActionModalLabel">Confirmer l'action</h5>
                            <button type="button" class="btn-close" aria-label="Fermer" data-modal-close></button>
                        </div>
                        <div class="modal-body" id="confirmActionModalBody">Veux-tu vraiment continuer ?</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-modal-close>Annuler</button>
                            <button type="button" class="btn btn-danger" id="confirmActionModalSubmit">Confirmer</button>
                        </div>
                    </div>
                </div>
            </div>
        `.trim();
        modalElement = wrapper.firstElementChild;
        if (modalElement instanceof HTMLElement) {
            document.body.appendChild(modalElement);
        }
    }

    return {
        modalElement,
        titleElement: document.getElementById('confirmActionModalLabel'),
        bodyElement: document.getElementById('confirmActionModalBody'),
        confirmButton: document.getElementById('confirmActionModalSubmit'),
    };
}

function ensurePhotoModal() {
    let modalElement = document.getElementById('photoLightboxModal');
    if (!(modalElement instanceof HTMLElement)) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="photoLightboxModal" tabindex="-1" aria-labelledby="photoLightboxModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content lightbox-modal-content">
                        <div class="modal-header lightbox-modal-header">
                            <h5 class="modal-title" id="photoLightboxModalLabel">Photo</h5>
                            <button type="button" class="btn-close btn-close-white" aria-label="Fermer" data-modal-close></button>
                        </div>
                        <div class="modal-body lightbox-modal-body">
                            <img id="photoLightboxImage" class="lightbox-image" src="" alt="">
                        </div>
                    </div>
                </div>
            </div>
        `.trim();
        modalElement = wrapper.firstElementChild;
        if (modalElement instanceof HTMLElement) {
            document.body.appendChild(modalElement);
        }
    }

    return {
        modalElement,
        imageElement: document.getElementById('photoLightboxImage'),
        titleElement: document.getElementById('photoLightboxModalLabel'),
    };
}

function ensureInstallHelpModal() {
    let modalElement = document.getElementById('installHelpModal');
    if (!(modalElement instanceof HTMLElement)) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="installHelpModal" tabindex="-1" aria-labelledby="installHelpModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="installHelpModalLabel">Installer l’application</h5>
                            <button type="button" class="btn-close" aria-label="Fermer" data-modal-close></button>
                        </div>
                        <div class="modal-body">
                            <p>Sur iPhone avec Safari, l’installation se fait en quelques secondes :</p>
                            <ol class="install-help-list">
                                <li>Appuie sur le bouton <strong>Partager</strong> de Safari.</li>
                                <li>Choisis <strong>Sur l’écran d’accueil</strong>.</li>
                                <li>Valide avec <strong>Ajouter</strong>.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        `.trim();
        modalElement = wrapper.firstElementChild;
        if (modalElement instanceof HTMLElement) {
            document.body.appendChild(modalElement);
        }
    }

    return { modalElement };
}

function showModalElement(modalElement) {
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    if (typeof bootstrap !== 'undefined' && bootstrap?.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
        return;
    }

    modalElement.style.display = 'block';
    modalElement.removeAttribute('aria-hidden');
    modalElement.setAttribute('aria-modal', 'true');
    modalElement.classList.add('show');
    document.body.classList.add('modal-open');

    if (!(plainModalBackdrop instanceof HTMLElement)) {
        plainModalBackdrop = document.createElement('div');
        plainModalBackdrop.className = 'modal-backdrop fade show';
    }

    if (!document.body.contains(plainModalBackdrop)) {
        document.body.appendChild(plainModalBackdrop);
    }
}

function hideModalElement(modalElement) {
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    if (typeof bootstrap !== 'undefined' && bootstrap?.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalElement).hide();
        return;
    }

    modalElement.classList.remove('show');
    modalElement.style.display = 'none';
    modalElement.setAttribute('aria-hidden', 'true');
    modalElement.removeAttribute('aria-modal');
    document.body.classList.remove('modal-open');

    if (plainModalBackdrop instanceof HTMLElement && document.body.contains(plainModalBackdrop)) {
        plainModalBackdrop.remove();
    }

    if (modalElement.id === 'confirmActionModal') {
        pendingConfirmationForm = null;
    }

    if (modalElement.id === 'employeeEntryModal') {
        resetEmployeeEntryModal();
    }
}
