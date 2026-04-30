import './stimulus_bootstrap.js';
import './styles/app.css';

let pendingConfirmationForm = null;
let activeDragButton = null;
let dragOffsetY = 0;
let dashboardPollTimer = null;
let dashboardPollInFlight = false;
let lastKnownDashboardCheckoutCount = null;
let dashboardPollingStarted = false;

restorePendingToast();
restoreFloatingMenuPosition();
syncTopBarOnScroll();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        startDashboardPolling();
        syncTopBarOnScroll();
    });
} else {
    startDashboardPolling();
    syncTopBarOnScroll();
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

        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Une erreur est survenue.');
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

        const targetSelector = form.getAttribute('data-update-target');
        if (targetSelector && payload.html) {
            const target = document.querySelector(targetSelector);
            if (target) {
                if (form.getAttribute('data-swap-mode') === 'outer') {
                    target.outerHTML = payload.html;
                } else {
                    target.innerHTML = payload.html;
                }
            }
        }

        if (form.hasAttribute('data-reset-form')) {
            form.reset();
        }

        hideModal('workflowActionModal');
        hideModal('actionMenuModal');
        showToast(payload.message || 'Opération terminée.');
    } catch (error) {
        showToast(error.message || 'Opération impossible.', 'error');
    } finally {
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
        }
    }
});

function openConfirmationModal(form) {
    const modalElement = document.getElementById('confirmActionModal');
    const titleElement = document.getElementById('confirmActionModalLabel');
    const bodyElement = document.getElementById('confirmActionModalBody');
    const confirmButton = document.getElementById('confirmActionModalSubmit');

    if (!(modalElement instanceof HTMLElement) || !(titleElement instanceof HTMLElement) || !(bodyElement instanceof HTMLElement) || !(confirmButton instanceof HTMLButtonElement) || typeof bootstrap === 'undefined') {
        form.dataset.confirmed = 'true';
        form.requestSubmit();
        return;
    }

    pendingConfirmationForm = form;
    titleElement.textContent = form.dataset.confirmTitle || "Confirmer l'action";
    bodyElement.textContent = form.dataset.confirmMessage || 'Veux-tu vraiment continuer ?';

    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function openWorkflowModal(trigger) {
    const sourceSelector = trigger.getAttribute('data-workflow-source');
    const source = sourceSelector ? document.querySelector(sourceSelector) : null;
    const modalElement = document.getElementById('workflowActionModal');
    const titleElement = document.getElementById('workflowActionModalLabel');
    const contentElement = document.getElementById('workflowActionModalContent');

    if (!(source instanceof HTMLElement) || !(modalElement instanceof HTMLElement) || !(titleElement instanceof HTMLElement) || !(contentElement instanceof HTMLElement) || typeof bootstrap === 'undefined') {
        return;
    }

    titleElement.textContent = trigger.getAttribute('data-workflow-title') || 'Suivi du dossier';
    contentElement.innerHTML = source.innerHTML;
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function openActionMenuModal(trigger) {
    if (trigger instanceof HTMLElement && trigger.dataset.dragMoved === 'true') {
        return;
    }

    const sourceSelector = trigger.getAttribute('data-action-menu-source');
    const source = sourceSelector ? document.querySelector(sourceSelector) : null;
    const modalElement = document.getElementById('actionMenuModal');
    const titleElement = document.getElementById('actionMenuModalLabel');
    const contentElement = document.getElementById('actionMenuModalContent');

    if (!(source instanceof HTMLElement) || !(modalElement instanceof HTMLElement) || !(titleElement instanceof HTMLElement) || !(contentElement instanceof HTMLElement) || typeof bootstrap === 'undefined') {
        return;
    }

    titleElement.textContent = trigger.getAttribute('data-action-menu-title') || 'Actions appartement';
    contentElement.innerHTML = source.innerHTML;
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function openPhotoLightbox(trigger) {
    const src = trigger.getAttribute('data-lightbox-src');
    const alt = trigger.getAttribute('data-lightbox-alt') || 'Photo';
    const modalElement = document.getElementById('photoLightboxModal');
    const imageElement = document.getElementById('photoLightboxImage');
    const titleElement = document.getElementById('photoLightboxModalLabel');

    if (!src || !(modalElement instanceof HTMLElement) || !(imageElement instanceof HTMLImageElement) || !(titleElement instanceof HTMLElement) || typeof bootstrap === 'undefined') {
        return;
    }

    imageElement.src = src;
    imageElement.alt = alt;
    titleElement.textContent = alt;
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function hideModal(id) {
    const modalElement = document.getElementById(id);
    if (modalElement instanceof HTMLElement && typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(modalElement).hide();
    }
}

function scrollToTop() {
    window.scrollTo({top: 0, behavior: 'smooth'});
}

document.addEventListener('click', (event) => {
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
                    }
                });
            }

            target.classList.toggle('is-collapsed');
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
    toast.className = `toast ${type === 'error' ? 'toast-error' : ''}`;
    toast.textContent = message;
    stack.appendChild(toast);

    window.setTimeout(() => {
        toast.classList.add('toast-hide');
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
    const body = document.body;
    if (!(body instanceof HTMLBodyElement)) {
        return;
    }

    body.classList.toggle('nav-compact', window.scrollY > 24);

    const scrollTopButton = document.getElementById('scroll-to-top-button');
    if (scrollTopButton instanceof HTMLElement) {
        scrollTopButton.classList.toggle('is-visible', window.scrollY > 280);
    }
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
