import './stimulus_bootstrap.js';
import './styles/app.css';

document.documentElement.classList.remove('app-shell-pending');

let pendingConfirmationForm = null;
let pendingConfirmationLink = null;
let activeDragButton = null;
let dragOffsetY = 0;
let activeRoomSwipeCard = null;
let roomSwipeStartX = 0;
let roomSwipeStartY = 0;
let roomSwipeBaseX = 0;
let roomSwipeCurrentX = 0;
let roomSwipeDidMove = false;
let dashboardPollTimer = null;
let dashboardPollInFlight = false;
let lastKnownDashboardCheckoutCount = null;
let dashboardPollingStarted = false;
let deferredInstallPrompt = null;
let plainModalBackdrop = null;
let pendingActionReminderTimer = null;
let pendingActionReminderLoading = false;

restorePendingToast();
restoreFloatingMenuPosition();
syncTopBarOnScroll();
registerServiceWorker();
setupInstallPrompt();
initializeInteractiveWidgets(document);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        startDashboardPolling();
        syncTopBarOnScroll();
        initializeInteractiveWidgets(document);
    });
} else {
    startDashboardPolling();
    syncTopBarOnScroll();
    initializeInteractiveWidgets(document);
}

document.addEventListener('turbo:load', () => {
    syncTopBarOnScroll();
    initializeInteractiveWidgets(document);
});

document.addEventListener('turbo:render', () => {
    initializeInteractiveWidgets(document);
});

document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('[data-checkin-signature-pad]').forEach((pad) => {
        if (pad instanceof HTMLElement) {
            delete pad.dataset.signatureReady;
            delete pad.checkinSignatureResize;
            delete pad.checkinSignatureClear;
        }
    });
});

window.addEventListener('pageshow', () => {
    initializeInteractiveWidgets(document);
});

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
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

    if (!form.hasAttribute('data-async-form')) {
        return;
    }

    event.preventDefault();
    clearInlineFormError(form);
    syncRichTextEditors(form);

    if (!validateReservationCreationForm(form)) {
        return;
    }

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
    }

    const checkoutLineTransition = captureCheckoutLineTransition(form);
    const formData = new FormData(form);
    const csrfToken = getAppCsrfToken();

    try {
        const response = await fetch(form.action, {
            method: form.method || 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                ...(csrfToken ? {'X-App-Csrf': csrfToken} : {}),
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

                initializeInteractiveWidgets(document.querySelector(targetSelector) || document);
                syncApartmentTemplateSelectState();
                restoreUiState(targetSelector, uiState);
                animateCheckoutLineTransition(targetSelector, checkoutLineTransition);
            }
        }

        if (payload.redirect && Number.isFinite(Number(payload.redirectDelayMs)) && Number(payload.redirectDelayMs) > 0) {
            if (payload.message) {
                storePendingToast(payload.message, 'success');
            }

            window.setTimeout(() => {
                window.location.href = payload.redirect;
            }, Number(payload.redirectDelayMs));
            return;
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

        if (form.hasAttribute('data-reset-form')) {
            form.reset();
        }

        hideModal('workflowActionModal');
        hideModal('actionMenuModal');
        showToast(payload?.message || 'Opération terminée.');
    } catch (error) {
        setInlineFormError(form, error.message || 'Opération impossible.');
        showToast(error.message || 'Opération impossible.', 'error');
    } finally {
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
        }
    }
});

function initializeInteractiveWidgets(scope = document) {
    syncPanelTriggers(scope);
    initializeAppearancePalette(scope);
    initializeRichTextEditors(scope);
    initializePendingActionNotifications();
    syncApartmentTemplateSelectState();
    syncAllCheckinGuestRows(scope);
    initializeCheckinSignaturePads(scope);
}

function initializePendingActionNotifications() {
    const root = document.querySelector('[data-pending-notifications]');
    if (!(root instanceof HTMLElement)) {
        return;
    }

    if (root.dataset.pendingNotificationsReady !== 'true') {
        root.dataset.pendingNotificationsReady = 'true';
        root.addEventListener('click', handlePendingActionNotificationClick);

        ['click', 'input', 'change', 'keydown', 'submit'].forEach((eventName) => {
            window.addEventListener(eventName, () => schedulePendingActionReminder(root), { passive: true });
        });
    }

    schedulePendingActionReminder(root);
}

function schedulePendingActionReminder(root) {
    if (!(root instanceof HTMLElement)) {
        return;
    }

    if (pendingActionReminderTimer) {
        window.clearTimeout(pendingActionReminderTimer);
    }

    const delay = Number.parseInt(root.dataset.pendingNotificationsDelay || '10000', 10);
    pendingActionReminderTimer = window.setTimeout(() => {
        loadPendingActionNotifications(root);
    }, Number.isFinite(delay) && delay > 0 ? delay : 10000);
}

async function loadPendingActionNotifications(root) {
    if (!(root instanceof HTMLElement) || pendingActionReminderLoading) {
        return;
    }

    const endpoint = root.dataset.pendingNotificationsEndpoint || '';
    if (endpoint === '') {
        return;
    }

    pendingActionReminderLoading = true;

    try {
        const response = await fetch(endpoint, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (!response.ok) {
            hidePendingActionReminder(root);
            return;
        }

        const payload = await response.json();
        const actions = Array.isArray(payload?.actions)
            ? payload.actions.filter((action) => isPendingActionPayload(action) && !isPendingActionDismissed(action.id))
            : [];

        if (actions.length === 0) {
            hidePendingActionReminder(root);
            return;
        }

        renderPendingActionReminder(root, actions);
    } catch (error) {
        hidePendingActionReminder(root);
    } finally {
        pendingActionReminderLoading = false;
    }
}

function isPendingActionPayload(action) {
    return action
        && typeof action === 'object'
        && typeof action.id === 'string'
        && typeof action.title === 'string'
        && typeof action.description === 'string'
        && typeof action.url === 'string';
}

function renderPendingActionReminder(root, actions) {
    root.innerHTML = '';
    root.hidden = false;
    root.classList.add('pending-action-reminder-root');

    const panel = document.createElement('section');
    panel.className = 'pending-action-reminder';
    panel.setAttribute('aria-label', 'Actions en attente');

    const header = document.createElement('div');
    header.className = 'pending-action-reminder-head';

    const titleWrapper = document.createElement('div');
    const eyebrow = document.createElement('span');
    eyebrow.className = 'pending-action-reminder-eyebrow';
    eyebrow.textContent = 'Rappel';

    const title = document.createElement('strong');
    title.textContent = actions.length > 1 ? `${actions.length} actions en attente` : '1 action en attente';

    titleWrapper.append(eyebrow, title);

    const closeButton = document.createElement('button');
    closeButton.className = 'pending-action-reminder-close';
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', 'Fermer le rappel');
    closeButton.dataset.pendingNotificationClose = 'true';
    closeButton.textContent = '×';

    header.append(titleWrapper, closeButton);

    const intro = document.createElement('p');
    intro.className = 'pending-action-reminder-intro';
    intro.textContent = 'Quelques actions attendent une suite. Tu peux les ouvrir directement depuis ce rappel.';

    const list = document.createElement('div');
    list.className = 'pending-action-reminder-list';

    actions.forEach((action) => {
        const link = document.createElement('a');
        link.className = `pending-action-reminder-item is-${action.priority || 'soft'}`;
        link.href = action.url;
        link.dataset.pendingActionId = action.id;

        const itemCopy = document.createElement('span');
        itemCopy.className = 'pending-action-reminder-copy';

        const itemTitle = document.createElement('strong');
        itemTitle.textContent = action.title;

        const itemDescription = document.createElement('span');
        itemDescription.textContent = action.description;

        itemCopy.append(itemTitle, itemDescription);

        const itemMeta = document.createElement('em');
        itemMeta.textContent = action.meta || 'Ouvrir';

        link.append(itemCopy, itemMeta);
        list.append(link);
    });

    const actionsBar = document.createElement('div');
    actionsBar.className = 'pending-action-reminder-actions';

    const dismissButton = document.createElement('button');
    dismissButton.className = 'secondary-button pending-action-reminder-dismiss';
    dismissButton.type = 'button';
    dismissButton.dataset.pendingNotificationDismiss = 'true';
    dismissButton.textContent = 'Ne plus afficher';

    actionsBar.append(dismissButton);
    panel.append(header, intro, list, actionsBar);
    root.append(panel);
    root.dataset.visibleActionIds = JSON.stringify(actions.map((action) => action.id));
}

function handlePendingActionNotificationClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) {
        return;
    }

    const root = target.closest('[data-pending-notifications]');
    if (!(root instanceof HTMLElement)) {
        return;
    }

    const dismissButton = target.closest('[data-pending-notification-dismiss]');
    if (dismissButton instanceof HTMLElement) {
        dismissVisiblePendingActions(root);
        hidePendingActionReminder(root);
        return;
    }

    const closeButton = target.closest('[data-pending-notification-close]');
    if (closeButton instanceof HTMLElement) {
        hidePendingActionReminder(root);
    }
}

function dismissVisiblePendingActions(root) {
    let actionIds = [];
    try {
        actionIds = JSON.parse(root.dataset.visibleActionIds || '[]');
    } catch (error) {
        actionIds = [];
    }

    const dismissedActions = readDismissedPendingActions();
    actionIds.forEach((actionId) => {
        if (typeof actionId === 'string' && actionId !== '') {
            dismissedActions[actionId] = Date.now();
        }
    });
    writeDismissedPendingActions(dismissedActions);
}

function hidePendingActionReminder(root) {
    if (!(root instanceof HTMLElement)) {
        return;
    }

    root.innerHTML = '';
    root.hidden = true;
    root.classList.remove('pending-action-reminder-root');
    delete root.dataset.visibleActionIds;
}

function isPendingActionDismissed(actionId) {
    if (typeof actionId !== 'string' || actionId === '') {
        return true;
    }

    return Object.prototype.hasOwnProperty.call(readDismissedPendingActions(), actionId);
}

function readDismissedPendingActions() {
    try {
        const rawValue = window.localStorage.getItem('pending-action-notifications-dismissed') || '{}';
        const parsedValue = JSON.parse(rawValue);
        return parsedValue && typeof parsedValue === 'object' && !Array.isArray(parsedValue) ? parsedValue : {};
    } catch (error) {
        return {};
    }
}

function writeDismissedPendingActions(dismissedActions) {
    try {
        const entries = Object.entries(dismissedActions).slice(-120);
        window.localStorage.setItem('pending-action-notifications-dismissed', JSON.stringify(Object.fromEntries(entries)));
    } catch (error) {
        // localStorage may be disabled; in that case the close button still hides the reminder for the current view.
    }
}

function getAppCsrfToken() {
    const meta = document.querySelector('meta[name="app-csrf-token"]');

    return meta instanceof HTMLMetaElement ? meta.content : '';
}

function openConfirmationModal(form) {
    const modal = ensureConfirmModal();
    const { modalElement, titleElement, bodyElement, confirmButton } = modal;

    if (!(modalElement instanceof HTMLElement) || !(titleElement instanceof HTMLElement) || !(bodyElement instanceof HTMLElement) || !(confirmButton instanceof HTMLButtonElement)) {
        form.dataset.confirmed = 'true';
        form.requestSubmit();
        return;
    }

    pendingConfirmationForm = form;
    titleElement.textContent = form.dataset.confirmTitle || "Confirmer l’action";
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

function resetApartmentReservationWizard(modalElement) {
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    const form = modalElement.querySelector('form');
    const steps = Array.from(modalElement.querySelectorAll('[data-reservation-step]'));
    if (form instanceof HTMLFormElement) {
        form.reset();
        clearInlineFormError(form);
        delete form.dataset.reservationCalendarMonth;
        renderReservationRangePicker(form);
    }

    steps.forEach((stepElement, index) => {
        if (!(stepElement instanceof HTMLElement)) {
            return;
        }

        const isFirstStep = index === 0;
        stepElement.hidden = !isFirstStep;
        stepElement.classList.toggle('is-collapsed', !isFirstStep);
    });
}

function setInlineFormError(form, message) {
    const errorElement = form.querySelector('[data-form-error]');
    if (!(errorElement instanceof HTMLElement)) {
        return;
    }

    errorElement.textContent = message;
    errorElement.hidden = false;
}

function clearInlineFormError(form) {
    const errorElement = form.querySelector('[data-form-error]');
    if (!(errorElement instanceof HTMLElement)) {
        return;
    }

    errorElement.textContent = '';
    errorElement.hidden = true;
}

function validateReservationWhatsAppInput(input) {
    const rawValue = input.value.trim();
    const digits = rawValue.replace(/\D+/g, '');
    let normalized = '';

    if (rawValue.startsWith('+')) {
        normalized = `+${digits}`;
    } else if (rawValue.startsWith('00')) {
        normalized = `+${digits.slice(2)}`;
    } else {
        return false;
    }

    return /^\+[1-9]\d{7,14}$/.test(normalized);
}

function parseReservationLocalDate(value) {
    if (!value || typeof value !== 'string') {
        return null;
    }

    const [year, month, day] = value.split('-').map((part) => Number.parseInt(part, 10));
    if (!year || !month || !day) {
        return null;
    }

    return new Date(year, month - 1, day);
}

function formatReservationIso(date) {
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function formatReservationDisplay(value) {
    const date = parseReservationLocalDate(value);
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        return null;
    }

    return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}

function parseReservationRanges(form) {
    if (!(form instanceof HTMLFormElement)) {
        return [];
    }

    const rawRanges = form.getAttribute('data-reservation-ranges') || '[]';

    try {
        const payload = JSON.parse(rawRanges);
        if (!Array.isArray(payload)) {
            return [];
        }

        return payload.filter((range) => range && typeof range.arrivalDate === 'string' && typeof range.departureDate === 'string');
    } catch (error) {
        return [];
    }
}

function reservationRangeOverlaps(arrivalDate, departureDate, ranges) {
    return ranges.some((range) => arrivalDate <= range.departureDate && departureDate >= range.arrivalDate);
}

function isReservationDateBlocked(dateValue, ranges) {
    return ranges.some((range) => dateValue >= range.arrivalDate && dateValue <= range.departureDate);
}

function renderReservationRangePicker(form) {
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const grid = form.querySelector('[data-reservation-calendar-grid]');
    const label = form.querySelector('[data-reservation-calendar-label]');
    const arrivalDisplay = form.querySelector('[data-reservation-date-display="arrival"]');
    const departureDisplay = form.querySelector('[data-reservation-date-display="departure"]');
    const arrivalInput = form.querySelector('[data-reservation-date-input="arrival"]');
    const departureInput = form.querySelector('[data-reservation-date-input="departure"]');

    if (
        !(grid instanceof HTMLElement)
        || !(label instanceof HTMLElement)
        || !(arrivalDisplay instanceof HTMLElement)
        || !(departureDisplay instanceof HTMLElement)
        || !(arrivalInput instanceof HTMLInputElement)
        || !(departureInput instanceof HTMLInputElement)
    ) {
        return;
    }

    const reservationRanges = parseReservationRanges(form);
    const arrivalValue = arrivalInput.value;
    const departureValue = departureInput.value;
    const monthSource = form.dataset.reservationCalendarMonth || arrivalValue || formatReservationIso(new Date());
    const currentMonth = parseReservationLocalDate(monthSource) || new Date();
    currentMonth.setDate(1);
    form.dataset.reservationCalendarMonth = formatReservationIso(currentMonth);

    arrivalDisplay.textContent = formatReservationDisplay(arrivalValue) || 'Non définie';
    departureDisplay.textContent = formatReservationDisplay(departureValue) || 'Non défini';
    label.textContent = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(currentMonth);

    const firstDayIndex = (currentMonth.getDay() + 6) % 7;
    const daysInMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 0).getDate();

    grid.innerHTML = '';

    for (let blankIndex = 0; blankIndex < firstDayIndex; blankIndex += 1) {
        const blank = document.createElement('span');
        blank.className = 'reservation-calendar-empty';
        grid.appendChild(blank);
    }

    for (let day = 1; day <= daysInMonth; day += 1) {
        const date = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), day);
        const iso = formatReservationIso(date);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'reservation-calendar-day';
        button.textContent = `${day}`;
        button.dataset.reservationCalendarDay = iso;

        const isBlocked = isReservationDateBlocked(iso, reservationRanges);
        const isSelected = iso === arrivalValue || iso === departureValue;
        const isInRange = Boolean(arrivalValue && departureValue && iso > arrivalValue && iso < departureValue);
        const blocksDepartureChoice = Boolean(
            arrivalValue
            && !departureValue
            && iso > arrivalValue
            && reservationRangeOverlaps(arrivalValue, iso, reservationRanges)
        );

        if ((isBlocked && !isSelected) || blocksDepartureChoice) {
            button.disabled = true;
            button.classList.add('is-blocked');
        }

        if (isSelected) {
            button.classList.add('is-selected');
        } else if (isInRange) {
            button.classList.add('is-in-range');
        }

        grid.appendChild(button);
    }
}

function syncReservationDateAvailability(form) {
    if (!(form instanceof HTMLFormElement)) {
        return true;
    }

    const arrivalInput = form.querySelector('[data-reservation-date-input="arrival"]');
    const departureInput = form.querySelector('[data-reservation-date-input="departure"]');
    const submitButton = form.querySelector('button[type="submit"]');

    if (!(arrivalInput instanceof HTMLInputElement) || !(departureInput instanceof HTMLInputElement)) {
        return true;
    }

    arrivalInput.setCustomValidity('');
    departureInput.setCustomValidity('');

    if (!arrivalInput.value || !departureInput.value) {
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
        }

        return true;
    }

    const reservationRanges = parseReservationRanges(form);
    const blockedRange = reservationRanges.find((range) => arrivalInput.value <= range.departureDate && departureInput.value >= range.arrivalDate);
    if (!blockedRange) {
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
        }

        clearInlineFormError(form);
        return true;
    }

    const formattedStart = formatReservationDisplay(blockedRange.arrivalDate);
    const formattedEnd = formatReservationDisplay(blockedRange.departureDate);
    const message = `Cette période est déjà réservée du ${formattedStart} au ${formattedEnd}.`;

    arrivalInput.setCustomValidity(message);
    departureInput.setCustomValidity(message);
    setInlineFormError(form, message);

    if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
    }

    return false;
}

function validateReservationCreationForm(form) {
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-reservation-ranges')) {
        return true;
    }

    const whatsAppInput = form.querySelector('input[name="guestWhatsappNumber"]');
    if (whatsAppInput instanceof HTMLInputElement && whatsAppInput.value.trim() !== '' && !validateReservationWhatsAppInput(whatsAppInput)) {
        setInlineFormError(form, 'Saisissez le numéro au format international, par exemple +33 6 00 00 00 00.');
        whatsAppInput.focus();
        return false;
    }

    const arrivalInput = form.querySelector('[data-reservation-date-input="arrival"]');
    const departureInput = form.querySelector('[data-reservation-date-input="departure"]');
    if (arrivalInput instanceof HTMLInputElement && departureInput instanceof HTMLInputElement) {
        if (!arrivalInput.value || !departureInput.value) {
            setInlineFormError(form, 'Choisis une date d’arrivée puis une date de départ.');
            return false;
        }

        if (!syncReservationDateAvailability(form)) {
            return false;
        }
    }

    return true;
}

function applyReservationCalendarSelection(form, selectedDate) {
    if (!(form instanceof HTMLFormElement) || !selectedDate) {
        return;
    }

    const arrivalInput = form.querySelector('[data-reservation-date-input="arrival"]');
    const departureInput = form.querySelector('[data-reservation-date-input="departure"]');
    if (!(arrivalInput instanceof HTMLInputElement) || !(departureInput instanceof HTMLInputElement)) {
        return;
    }

    const reservationRanges = parseReservationRanges(form);
    const arrivalValue = arrivalInput.value;
    const departureValue = departureInput.value;

    if (!arrivalValue || departureValue) {
        arrivalInput.value = selectedDate;
        departureInput.value = '';
    } else if (selectedDate < arrivalValue) {
        arrivalInput.value = selectedDate;
        departureInput.value = '';
    } else if (!reservationRangeOverlaps(arrivalValue, selectedDate, reservationRanges)) {
        departureInput.value = selectedDate;
    }

    form.dataset.reservationCalendarMonth = selectedDate;
    syncReservationDateAvailability(form);
    renderReservationRangePicker(form);
}

function openApartmentReservationModal() {
    const modalElement = document.getElementById('apartmentReservationModal');
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    resetApartmentReservationWizard(modalElement);
    showModalElement(modalElement);

    const form = modalElement.querySelector('form');
    if (form instanceof HTMLFormElement) {
        renderReservationRangePicker(form);
        syncReservationDateAvailability(form);
    }

    window.setTimeout(() => {
        const input = modalElement.querySelector('[data-reservation-step-input]');
        if (input instanceof HTMLInputElement) {
            input.focus();
        }
    }, 120);
}

function moveApartmentReservationWizard(direction) {
    const modalElement = document.getElementById('apartmentReservationModal');
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    const steps = Array.from(modalElement.querySelectorAll('[data-reservation-step]')).filter((step) => step instanceof HTMLElement);
    const currentIndex = steps.findIndex((step) => step instanceof HTMLElement && !step.hidden);
    if (currentIndex === -1) {
        return;
    }

    const nextIndex = direction === 'next'
        ? Math.min(currentIndex + 1, steps.length - 1)
        : Math.max(currentIndex - 1, 0);

    if (direction === 'next') {
        const currentStep = steps[currentIndex];
        if (currentStep instanceof HTMLElement) {
            const requiredInputs = Array.from(currentStep.querySelectorAll('input[required], textarea[required], select[required]'));
            const invalidInput = requiredInputs.find((input) => {
                if (
                    input instanceof HTMLInputElement
                    || input instanceof HTMLTextAreaElement
                    || input instanceof HTMLSelectElement
                ) {
                    return !input.reportValidity();
                }

                return false;
            });

            if (invalidInput) {
                return;
            }

            if (currentIndex === 1) {
                const whatsAppInput = currentStep.querySelector('input[name="guestWhatsappNumber"]');
                if (whatsAppInput instanceof HTMLInputElement && !validateReservationWhatsAppInput(whatsAppInput)) {
                    setInlineFormError(whatsAppInput.form, 'Saisissez le numéro au format international, par exemple +33 6 00 00 00 00.');
                    whatsAppInput.focus();
                    return;
                }

                if (whatsAppInput instanceof HTMLInputElement && whatsAppInput.form instanceof HTMLFormElement) {
                    clearInlineFormError(whatsAppInput.form);
                }
            }

            if (currentIndex === 2) {
                const stepForm = currentStep.closest('form');
                if (stepForm instanceof HTMLFormElement && !syncReservationDateAvailability(stepForm)) {
                    const arrivalInput = stepForm.querySelector('[data-reservation-date-input="arrival"]');
                    if (arrivalInput instanceof HTMLInputElement) {
                        arrivalInput.reportValidity();
                    }
                    return;
                }
            }
        }
    }

    steps.forEach((step, index) => {
        if (!(step instanceof HTMLElement)) {
            return;
        }

        const isActive = index === nextIndex;
        step.hidden = !isActive;
        step.classList.toggle('is-collapsed', !isActive);
    });
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

async function loadAirbnbRoomFragment(trigger) {
    const targetSelector = trigger.getAttribute('data-update-target') || '#airbnb-room-content';
    const target = document.querySelector(targetSelector);
    if (!(target instanceof HTMLElement)) {
        window.location.href = trigger.href;
        return;
    }

    target.classList.add('is-loading-fragment');

    try {
        const response = await fetch(trigger.href, {
            method: 'GET',
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

        if (!response.ok || !payload?.success || !payload.html) {
            throw new Error(payload?.message || 'Impossible de charger ce filtre.');
        }

        target.innerHTML = payload.html;
        initializeInteractiveWidgets(target);
        window.history.replaceState({}, '', trigger.href);
    } catch (error) {
        showToast(error.message || 'Impossible de charger ce filtre.', 'error');
    } finally {
        target.classList.remove('is-loading-fragment');
    }
}

function scrollToTop() {
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function getCheckinGuestRows(trigger) {
    const section = trigger.closest('.checkin-form-section');
    const manager = section?.querySelector('[data-checkin-guest-manager]');
    const rows = manager?.querySelector('[data-checkin-guest-rows]');

    return rows instanceof HTMLTableSectionElement ? rows : null;
}

function getCheckinGuestRowInputs(row) {
    const nameInput = row.querySelector('input[name="guestNames[]"]');
    const identityInput = row.querySelector('input[name="guestIdentityNumbers[]"]');

    return {
        nameInput: nameInput instanceof HTMLInputElement ? nameInput : null,
        identityInput: identityInput instanceof HTMLInputElement ? identityInput : null,
    };
}

function isCheckinGuestRowComplete(row) {
    const {nameInput, identityInput} = getCheckinGuestRowInputs(row);

    return Boolean(nameInput?.value.trim()) && Boolean(identityInput?.value.trim());
}

function lockCheckinGuestRow(row) {
    const {nameInput, identityInput} = getCheckinGuestRowInputs(row);
    const editButton = row.querySelector('[data-checkin-edit-guest]');

    row.classList.add('is-locked');
    [nameInput, identityInput].forEach((input) => {
        if (input instanceof HTMLInputElement) {
            input.readOnly = true;
        }
    });

    if (editButton instanceof HTMLButtonElement) {
        editButton.hidden = false;
    }
}

function unlockCheckinGuestRow(row) {
    const {nameInput, identityInput} = getCheckinGuestRowInputs(row);
    const editButton = row.querySelector('[data-checkin-edit-guest]');

    row.classList.remove('is-locked');
    [nameInput, identityInput].forEach((input) => {
        if (input instanceof HTMLInputElement) {
            input.readOnly = false;
        }
    });

    if (editButton instanceof HTMLButtonElement) {
        editButton.hidden = true;
    }

    if (nameInput instanceof HTMLInputElement) {
        nameInput.focus();
    }
}

function syncCheckinGuestRows(rows) {
    if (!(rows instanceof HTMLTableSectionElement)) {
        return;
    }

    const rowList = Array.from(rows.querySelectorAll('tr'));
    const guestCountInput = document.querySelector('[data-checkin-guest-count]');
    if (guestCountInput instanceof HTMLInputElement) {
        guestCountInput.value = String(rowList.length);
    }

    rowList.forEach((row) => {
        const deleteButton = row.querySelector('[data-checkin-delete-guest]');
        if (deleteButton instanceof HTMLButtonElement) {
            deleteButton.hidden = rowList.length <= 1;
        }
    });
}

function syncAllCheckinGuestRows(scope = document) {
    if (!scope || typeof scope.querySelectorAll !== 'function') {
        return;
    }

    scope.querySelectorAll('[data-checkin-guest-rows]').forEach((rows) => {
        if (rows instanceof HTMLTableSectionElement) {
            syncCheckinGuestRows(rows);
        }
    });
}

function addCheckinGuestRow(trigger) {
    const rows = getCheckinGuestRows(trigger);
    const manager = rows?.closest('[data-checkin-guest-manager]');
    const template = manager?.querySelector('[data-checkin-guest-row-template]');

    if (!(rows instanceof HTMLTableSectionElement) || !(template instanceof HTMLTemplateElement)) {
        return;
    }

    if (rows.querySelectorAll('tr').length >= 20) {
        showToast('Le nombre de voyageurs est limite a 20.', 'error');
        return;
    }

    const existingRows = Array.from(rows.querySelectorAll('tr'));
    const incompleteRow = existingRows.find((existingRow) => !isCheckinGuestRowComplete(existingRow));
    if (incompleteRow instanceof HTMLTableRowElement) {
        unlockCheckinGuestRow(incompleteRow);
        showToast('Remplis le nom, le prénom et le passeport ou CIN avant d’ajouter une nouvelle entrée.', 'error');
        return;
    }

    existingRows.forEach(lockCheckinGuestRow);

    const row = template.content.firstElementChild?.cloneNode(true);
    if (!(row instanceof HTMLTableRowElement)) {
        return;
    }

    rows.appendChild(row);
    syncCheckinGuestRows(rows);

    const firstInput = row.querySelector('input');
    if (firstInput instanceof HTMLInputElement) {
        firstInput.focus();
    }
}

function deleteCheckinGuestRow(trigger) {
    const row = trigger.closest('tr');
    const rows = row?.parentElement;

    if (!(row instanceof HTMLTableRowElement) || !(rows instanceof HTMLTableSectionElement)) {
        return;
    }

    if (rows.querySelectorAll('tr').length <= 1) {
        showToast('Garde au moins une entrée voyageur.', 'error');
        return;
    }

    row.remove();
    syncCheckinGuestRows(rows);
}

function initializeCheckinSignaturePads(scope = document) {
    if (!scope || typeof scope.querySelectorAll !== 'function') {
        return;
    }

    scope.querySelectorAll('[data-checkin-signature-pad]').forEach((pad) => {
        if (!(pad instanceof HTMLElement)) {
            return;
        }

        if (pad.dataset.signatureReady === 'true') {
            const resizeSignature = pad.checkinSignatureResize;
            if (typeof resizeSignature === 'function') {
                window.requestAnimationFrame(resizeSignature);
                return;
            }

            delete pad.dataset.signatureReady;
        }

        const canvas = pad.querySelector('canvas');
        const input = pad.querySelector('[data-checkin-signature-input]');
        if (!(canvas instanceof HTMLCanvasElement) || !(input instanceof HTMLInputElement)) {
            return;
        }

        pad.dataset.signatureReady = 'true';
        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }

        let drawing = false;
        let hasSignature = false;

        const applyCanvasStyle = (ratio) => {
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.lineWidth = 2.4;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#222222';
        };

        const resizeCanvas = () => {
            const rectangle = canvas.getBoundingClientRect();
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const width = Math.max(rectangle.width || canvas.parentElement?.clientWidth || 320, 320);
            const height = Math.max(rectangle.height || 190, 150);
            const nextWidth = Math.round(width * ratio);
            const nextHeight = Math.round(height * ratio);

            if (canvas.width === nextWidth && canvas.height === nextHeight) {
                applyCanvasStyle(ratio);
                return;
            }

            const previousSignature = hasSignature && input.value ? input.value : '';

            canvas.width = nextWidth;
            canvas.height = nextHeight;
            applyCanvasStyle(ratio);

            if (previousSignature) {
                const image = new Image();
                image.onload = () => {
                    context.drawImage(image, 0, 0, width, height);
                };
                image.src = previousSignature;
            }
        };

        const positionFromEvent = (event) => {
            const rectangle = canvas.getBoundingClientRect();

            return {
                x: event.clientX - rectangle.left,
                y: event.clientY - rectangle.top,
            };
        };

        const updateSignatureInput = () => {
            input.value = hasSignature ? canvas.toDataURL('image/png') : '';
        };

        const startDrawing = (event) => {
            event.preventDefault();
            if (!hasSignature) {
                resizeCanvas();
            }
            drawing = true;
            hasSignature = true;
            const position = positionFromEvent(event);
            context.beginPath();
            context.moveTo(position.x, position.y);
            canvas.setPointerCapture?.(event.pointerId);
        };

        const draw = (event) => {
            if (!drawing) {
                return;
            }

            event.preventDefault();
            const position = positionFromEvent(event);
            context.lineTo(position.x, position.y);
            context.stroke();
            updateSignatureInput();
        };

        const stopDrawing = (event) => {
            if (!drawing) {
                return;
            }

            drawing = false;
            canvas.releasePointerCapture?.(event.pointerId);
            updateSignatureInput();
        };

        resizeCanvas();
        window.requestAnimationFrame(resizeCanvas);
        pad.checkinSignatureResize = resizeCanvas;
        pad.checkinSignatureClear = () => {
            hasSignature = false;
            context.clearRect(0, 0, canvas.width, canvas.height);
            input.value = '';
        };
        window.addEventListener('resize', resizeCanvas);
        canvas.addEventListener('pointerdown', startDrawing);
        canvas.addEventListener('pointermove', draw);
        canvas.addEventListener('pointerup', stopDrawing);
        canvas.addEventListener('pointercancel', stopDrawing);
    });
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

    const airbnbEquipmentModalTrigger = event.target instanceof Element ? event.target.closest('[data-airbnb-equipment-modal-target]') : null;
    if (airbnbEquipmentModalTrigger instanceof HTMLElement) {
        event.preventDefault();
        const modalSelector = airbnbEquipmentModalTrigger.getAttribute('data-airbnb-equipment-modal-target');
        const modalElement = modalSelector ? document.querySelector(modalSelector) : null;
        if (modalElement instanceof HTMLElement) {
            showModalElement(modalElement);
        }
        return;
    }

    const airbnbRoomFilterTrigger = event.target instanceof Element ? event.target.closest('[data-airbnb-room-filter]') : null;
    if (airbnbRoomFilterTrigger instanceof HTMLAnchorElement) {
        event.preventDefault();
        loadAirbnbRoomFragment(airbnbRoomFilterTrigger);
        return;
    }

    const addCheckinGuestTrigger = event.target instanceof Element ? event.target.closest('[data-checkin-add-guest]') : null;
    if (addCheckinGuestTrigger instanceof HTMLButtonElement) {
        addCheckinGuestRow(addCheckinGuestTrigger);
        return;
    }

    const editCheckinGuestTrigger = event.target instanceof Element ? event.target.closest('[data-checkin-edit-guest]') : null;
    if (editCheckinGuestTrigger instanceof HTMLButtonElement) {
        const row = editCheckinGuestTrigger.closest('tr');
        if (row instanceof HTMLTableRowElement) {
            unlockCheckinGuestRow(row);
        }
        return;
    }

    const deleteCheckinGuestTrigger = event.target instanceof Element ? event.target.closest('[data-checkin-delete-guest]') : null;
    if (deleteCheckinGuestTrigger instanceof HTMLButtonElement) {
        deleteCheckinGuestRow(deleteCheckinGuestTrigger);
        return;
    }

    const clearSignatureTrigger = event.target instanceof Element ? event.target.closest('[data-checkin-signature-clear]') : null;
    if (clearSignatureTrigger instanceof HTMLButtonElement) {
        const pad = clearSignatureTrigger.closest('[data-checkin-signature-pad]');
        const canvas = pad?.querySelector('canvas');
        const input = pad?.querySelector('[data-checkin-signature-input]');
        const context = canvas instanceof HTMLCanvasElement ? canvas.getContext('2d') : null;
        const clearSignature = pad?.checkinSignatureClear;

        if (typeof clearSignature === 'function') {
            clearSignature();
        } else if (canvas instanceof HTMLCanvasElement && input instanceof HTMLInputElement && context) {
            context.clearRect(0, 0, canvas.width, canvas.height);
            input.value = '';
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

    if (confirmButton instanceof HTMLButtonElement && pendingConfirmationLink) {
        confirmButton.blur();
        const linkToOpen = pendingConfirmationLink;
        pendingConfirmationLink = null;
        hideModal('confirmActionModal');
        window.open(linkToOpen.href, linkToOpen.target || '_blank', 'noopener,noreferrer');
        return;
    }

    const externalConfirmTrigger = event.target instanceof Element ? event.target.closest('[data-external-confirm]') : null;
    if (externalConfirmTrigger instanceof HTMLAnchorElement) {
        event.preventDefault();
        openExternalConfirmationModal(externalConfirmTrigger);
        return;
    }

    const toggleTrigger = event.target instanceof Element ? event.target.closest('[data-toggle-target]') : null;
    if (toggleTrigger instanceof HTMLButtonElement) {
        const targetSelector = toggleTrigger.getAttribute('data-toggle-target');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            const isCollapsed = target.classList.toggle('is-collapsed');
            target.hidden = isCollapsed;
            toggleTrigger.textContent = isCollapsed
                ? (toggleTrigger.getAttribute('data-toggle-label-closed') || 'Afficher')
                : (toggleTrigger.getAttribute('data-toggle-label-open') || 'Masquer');
            syncApartmentTemplateSelectState();
        }
        return;
    }

    const richTextTrigger = event.target instanceof Element ? event.target.closest('[data-rich-text-command]') : null;
    if (richTextTrigger instanceof HTMLButtonElement) {
        event.preventDefault();
        const editor = richTextTrigger.closest('[data-rich-text-editor]');
        const input = editor?.querySelector('[data-rich-text-input]');
        if (input instanceof HTMLElement) {
            input.focus();
            document.execCommand(richTextTrigger.dataset.richTextCommand || '', false);
            syncRichTextEditor(editor);
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
    if (panelOpenTrigger instanceof HTMLElement) {
        event.preventDefault();
        const groupName = panelOpenTrigger.getAttribute('data-panel-group');
        const targetSelector = panelOpenTrigger.getAttribute('data-panel-open');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            hideModal('actionMenuModal');

            if (groupName) {
                document.querySelectorAll(`[data-panel-name][data-panel-group="${groupName}"]`).forEach((panel) => {
                    if (panel instanceof HTMLElement) {
                        panel.classList.add('is-collapsed');
                        panel.hidden = true;
                    }
                });
            }

            target.hidden = false;
            target.classList.remove('is-collapsed');
            setActivePanelTrigger(groupName, targetSelector);
            syncPanelTriggers(document);
            if (!target.classList.contains('tenant-icon-panel')) {
                target.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        }
        return;
    }

    const panelCloseTrigger = event.target instanceof Element ? event.target.closest('[data-panel-close]') : null;
    if (panelCloseTrigger instanceof HTMLElement) {
        const targetSelector = panelCloseTrigger.getAttribute('data-panel-close');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            target.classList.add('is-collapsed');
            syncPanelTriggers(document);

            const returnSelector = panelCloseTrigger.getAttribute('data-panel-return');
            const returnTarget = returnSelector ? document.querySelector(returnSelector) : null;
            if (returnTarget instanceof HTMLElement) {
                returnTarget.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        }
        return;
    }

    const panelCloseGroupTrigger = event.target instanceof Element ? event.target.closest('[data-panel-close-group]') : null;
    if (panelCloseGroupTrigger instanceof HTMLElement) {
        closePanelGroup(panelCloseGroupTrigger.getAttribute('data-panel-close-group'));
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

    const apartmentReservationModalTrigger = event.target instanceof Element ? event.target.closest('[data-apartment-reservation-modal-trigger]') : null;
    if (apartmentReservationModalTrigger instanceof HTMLElement) {
        event.preventDefault();
        openApartmentReservationModal();
        return;
    }

    const apartmentNameModalTrigger = event.target instanceof Element ? event.target.closest('[data-apartment-name-modal-trigger]') : null;
    if (apartmentNameModalTrigger instanceof HTMLElement) {
        event.preventDefault();
        openApartmentNameModal();
        return;
    }

    const reservationNextTrigger = event.target instanceof Element ? event.target.closest('[data-reservation-next]') : null;
    if (reservationNextTrigger instanceof HTMLElement) {
        event.preventDefault();
        moveApartmentReservationWizard('next');
        return;
    }

    const reservationPrevTrigger = event.target instanceof Element ? event.target.closest('[data-reservation-prev]') : null;
    if (reservationPrevTrigger instanceof HTMLElement) {
        event.preventDefault();
        moveApartmentReservationWizard('prev');
        return;
    }

    const reservationCalendarShiftTrigger = event.target instanceof Element ? event.target.closest('[data-reservation-calendar-shift]') : null;
    if (reservationCalendarShiftTrigger instanceof HTMLButtonElement) {
        event.preventDefault();
        const form = reservationCalendarShiftTrigger.closest('form');
        if (form instanceof HTMLFormElement) {
            const currentMonth = parseReservationLocalDate(form.dataset.reservationCalendarMonth || formatReservationIso(new Date())) || new Date();
            currentMonth.setDate(1);
            currentMonth.setMonth(currentMonth.getMonth() + Number.parseInt(reservationCalendarShiftTrigger.dataset.reservationCalendarShift || '0', 10));
            form.dataset.reservationCalendarMonth = formatReservationIso(currentMonth);
            renderReservationRangePicker(form);
        }
        return;
    }

    const roomEquipmentTrigger = event.target instanceof Element ? event.target.closest('[data-room-equipment-open-modal]') : null;
    if (roomEquipmentTrigger instanceof HTMLButtonElement) {
        event.preventDefault();
        openRoomEquipmentQuantityModal(roomEquipmentTrigger);
        return;
    }

    const roomEquipmentConfirmTrigger = event.target instanceof Element ? event.target.closest('[data-room-equipment-confirm]') : null;
    if (roomEquipmentConfirmTrigger instanceof HTMLButtonElement) {
        event.preventDefault();
        confirmRoomEquipmentQuantity(roomEquipmentConfirmTrigger);
        return;
    }

    const reservationCalendarDayTrigger = event.target instanceof Element ? event.target.closest('[data-reservation-calendar-day]') : null;
    if (reservationCalendarDayTrigger instanceof HTMLButtonElement) {
        event.preventDefault();
        const form = reservationCalendarDayTrigger.closest('form');
        if (form instanceof HTMLFormElement) {
            clearInlineFormError(form);
            applyReservationCalendarSelection(form, reservationCalendarDayTrigger.dataset.reservationCalendarDay || '');
        }
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

    const historyToggle = event.target instanceof Element ? event.target.closest('[data-history-toggle]') : null;
    if (historyToggle instanceof HTMLElement) {
        event.preventDefault();
        const targetSelector = historyToggle.getAttribute('data-target');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        if (target instanceof HTMLElement) {
            const willExpand = target.hidden || target.classList.contains('is-collapsed');
            target.hidden = !willExpand;
            target.classList.toggle('is-collapsed', !willExpand);
            historyToggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
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

    const passwordRevealTrigger = event.target instanceof Element ? event.target.closest('[data-password-reveal-toggle]') : null;
    if (passwordRevealTrigger instanceof HTMLButtonElement) {
        event.preventDefault();
        const wrapper = passwordRevealTrigger.closest('[data-password-reveal-wrapper]');
        const valueElement = wrapper instanceof HTMLElement ? wrapper.querySelector('[data-password-reveal-value]') : null;
        if (!(wrapper instanceof HTMLElement) || !(valueElement instanceof HTMLElement)) {
            return;
        }

        const passwordValue = passwordRevealTrigger.getAttribute('data-password-value') || '';
        const passwordMask = passwordRevealTrigger.getAttribute('data-password-mask') || '••••••••';
        const previousTimeout = wrapper.dataset.hideTimeoutId ? Number(wrapper.dataset.hideTimeoutId) : 0;
        if (previousTimeout) {
            window.clearTimeout(previousTimeout);
        }

        valueElement.textContent = passwordValue;
        passwordRevealTrigger.disabled = true;

        const timeoutId = window.setTimeout(() => {
            valueElement.textContent = passwordMask;
            passwordRevealTrigger.disabled = false;
            delete wrapper.dataset.hideTimeoutId;
        }, 5000);

        wrapper.dataset.hideTimeoutId = String(timeoutId);
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

document.addEventListener('input', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLElement)) {
        return;
    }

    if (target instanceof HTMLElement && target.hasAttribute('data-rich-text-input')) {
        syncRichTextEditor(target.closest('[data-rich-text-editor]'));
        return;
    }

    if (target instanceof HTMLInputElement && target.hasAttribute('data-apartment-template-name')) {
        syncApartmentTemplateSelectState();
    }

    if (target instanceof HTMLInputElement && target.name === 'guestWhatsappNumber') {
        const form = target.form;
        if (form instanceof HTMLFormElement) {
            if (validateReservationWhatsAppInput(target)) {
                clearInlineFormError(form);
            }
        }
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.id !== 'admin-apartment-create-form') {
        return;
    }

    syncRichTextEditors(form);

    const templateFields = form.querySelector('#admin-apartment-template-fields');
    const templateSelect = form.querySelector('[data-apartment-template-select]');
    if (!(templateFields instanceof HTMLElement) || !(templateSelect instanceof HTMLSelectElement)) {
        return;
    }

    const templateModeOpen = !templateFields.classList.contains('is-collapsed') && !templateFields.hidden;
    if (templateModeOpen && !templateSelect.disabled && templateSelect.value === '') {
        event.preventDefault();
        showToast('Choisis un appartement modèle à dupliquer.', 'error');
    }
});

document.addEventListener('trix-file-accept', (event) => {
    event.preventDefault();
});

document.addEventListener('change', (event) => {
    const input = event.target;
    if (input instanceof HTMLSelectElement && input.hasAttribute('data-apartment-template-select')) {
        hydrateApartmentTemplateFields(input);
        return;
    }

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

document.addEventListener('pointerdown', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const swipeCard = target?.closest('[data-room-swipe-card]');
    if (!(swipeCard instanceof HTMLElement) || !swipeCard.classList.contains('has-pending-room-validation')) {
        return;
    }

    if (target?.closest('.room-swipe-action-form, button, input, textarea, select')) {
        return;
    }

    activeRoomSwipeCard = swipeCard;
    roomSwipeStartX = event.clientX;
    roomSwipeStartY = event.clientY;
    roomSwipeBaseX = swipeCard.classList.contains('is-open') ? -116 : 0;
    roomSwipeCurrentX = roomSwipeBaseX;
    roomSwipeDidMove = false;
    swipeCard.classList.add('is-swiping');
    swipeCard.setPointerCapture?.(event.pointerId);
});

document.addEventListener('pointermove', (event) => {
    if (!(activeRoomSwipeCard instanceof HTMLElement)) {
        return;
    }

    const deltaX = event.clientX - roomSwipeStartX;
    const deltaY = event.clientY - roomSwipeStartY;
    if (Math.abs(deltaX) < 7 && Math.abs(deltaY) < 7) {
        return;
    }

    if (Math.abs(deltaY) > Math.abs(deltaX) + 8) {
        return;
    }

    event.preventDefault();
    roomSwipeDidMove = true;
    const nextX = Math.max(-116, Math.min(0, roomSwipeBaseX + deltaX));
    roomSwipeCurrentX = nextX;
    activeRoomSwipeCard.style.setProperty('--room-swipe-x', `${nextX}px`);
}, {passive: false});

document.addEventListener('pointerup', (event) => {
    if (!(activeRoomSwipeCard instanceof HTMLElement)) {
        return;
    }

    const swipeCard = activeRoomSwipeCard;
    swipeCard.releasePointerCapture?.(event.pointerId);
    swipeCard.classList.remove('is-swiping');

    if (roomSwipeDidMove) {
        swipeCard.dataset.roomSwipeSuppressClick = 'true';
        window.setTimeout(() => {
            if (swipeCard.dataset.roomSwipeSuppressClick === 'true') {
                delete swipeCard.dataset.roomSwipeSuppressClick;
            }
        }, 350);
    }

    if (roomSwipeCurrentX <= -58) {
        closeRoomSwipeCards(swipeCard);
        openRoomSwipeCard(swipeCard);
    } else {
        closeRoomSwipeCard(swipeCard);
    }

    activeRoomSwipeCard = null;
});

document.addEventListener('pointercancel', () => {
    if (activeRoomSwipeCard instanceof HTMLElement) {
        activeRoomSwipeCard.classList.remove('is-swiping');
        closeRoomSwipeCard(activeRoomSwipeCard);
    }

    activeRoomSwipeCard = null;
});

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const foreground = target?.closest('[data-room-swipe-card] .room-swipe-foreground');
    if (foreground instanceof HTMLElement) {
        const swipeCard = foreground.closest('[data-room-swipe-card]');
        if (swipeCard instanceof HTMLElement && swipeCard.dataset.roomSwipeSuppressClick === 'true') {
            event.preventDefault();
            delete swipeCard.dataset.roomSwipeSuppressClick;
            return;
        }

        if (swipeCard instanceof HTMLElement && swipeCard.classList.contains('is-open')) {
            event.preventDefault();
            closeRoomSwipeCard(swipeCard);
            return;
        }
    }

    if (!target?.closest('[data-room-swipe-card]')) {
        closeRoomSwipeCards();
    }
}, true);

window.addEventListener('scroll', syncTopBarOnScroll, {passive: true});

document.addEventListener('hidden.bs.modal', (event) => {
    if (event.target instanceof HTMLElement && event.target.id === 'confirmActionModal') {
        pendingConfirmationForm = null;
        pendingConfirmationLink = null;
    }
});

function openRoomSwipeCard(card) {
    card.classList.add('is-open');
    card.style.setProperty('--room-swipe-x', '-116px');
}

function closeRoomSwipeCard(card) {
    card.classList.remove('is-open');
    card.style.removeProperty('--room-swipe-x');
}

function closeRoomSwipeCards(exceptCard = null) {
    document.querySelectorAll('[data-room-swipe-card].is-open').forEach((card) => {
        if (card instanceof HTMLElement && card !== exceptCard) {
            closeRoomSwipeCard(card);
        }
    });
}

function openExternalConfirmationModal(link) {
    const modal = ensureConfirmModal();
    const { modalElement, titleElement, bodyElement } = modal;

    if (!(modalElement instanceof HTMLElement) || !(titleElement instanceof HTMLElement) || !(bodyElement instanceof HTMLElement)) {
        window.open(link.href, link.target || '_blank', 'noopener,noreferrer');
        return;
    }

    pendingConfirmationForm = null;
    pendingConfirmationLink = {
        href: link.href,
        target: link.target || '_blank',
    };

    titleElement.textContent = link.dataset.confirmTitle || 'Ouvrir Waze';
    bodyElement.textContent = link.dataset.confirmMessage || 'Tu vas quitter cet espace pour ouvrir cette adresse dans Waze. Veux-tu continuer ?';

    showModalElement(modalElement);
}

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

function initializeRichTextEditors(scope = document) {
    if (!scope || typeof scope.querySelectorAll !== 'function') {
        return;
    }

    scope.querySelectorAll('[data-rich-text-editor]').forEach((editor) => {
        syncRichTextEditor(editor);
    });
}

function syncRichTextEditor(editor) {
    if (!(editor instanceof HTMLElement)) {
        return;
    }

    const input = editor.querySelector('[data-rich-text-input]');
    const source = editor.querySelector('[data-rich-text-source]');
    if (!(input instanceof HTMLElement) || !(source instanceof HTMLTextAreaElement)) {
        return;
    }

    source.value = input.innerHTML.trim();
    editor.classList.toggle('is-empty', input.textContent.trim() === '');
}

function syncRichTextEditors(scope = document) {
    if (!scope || typeof scope.querySelectorAll !== 'function') {
        return;
    }

    scope.querySelectorAll('[data-rich-text-editor]').forEach((editor) => {
        syncRichTextEditor(editor);
    });
}

function syncApartmentTemplateSelectState() {
    const form = document.getElementById('admin-apartment-create-form');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const nameInput = form.querySelector('[data-apartment-template-name]');
    const templateSelect = form.querySelector('[data-apartment-template-select]');
    const templateFields = form.querySelector('#admin-apartment-template-fields');
    if (!(nameInput instanceof HTMLInputElement) || !(templateSelect instanceof HTMLSelectElement) || !(templateFields instanceof HTMLElement)) {
        return;
    }

    const templateModeOpen = !templateFields.classList.contains('is-collapsed') && !templateFields.hidden;
    templateSelect.disabled = !templateModeOpen || nameInput.value.trim() === '';

    if (templateSelect.disabled) {
        templateSelect.value = '';
    }
}

function hydrateApartmentTemplateFields(templateSelect) {
    if (!(templateSelect instanceof HTMLSelectElement)) {
        return;
    }

    const form = templateSelect.closest('form');
    const selectedOption = templateSelect.selectedOptions[0];
    if (!(form instanceof HTMLFormElement) || !(selectedOption instanceof HTMLOptionElement)) {
        return;
    }

    const payload = parseApartmentTemplatePayload(selectedOption);
    if (!payload) {
        return;
    }

    [
        'addressLine1',
        'addressLine2',
        'city',
        'postalCode',
        'floor',
        'doorNumber',
        'mailboxNumber',
        'googleMapsLink',
        'buildingAccessCode',
        'keyBoxCode',
        'conditionStatus',
        'status',
        'bedroomCount',
        'ownerName',
        'ownerPhone',
        'ownerEmail',
        'inventoryDueAt',
    ].forEach((fieldName) => {
        setApartmentTemplateFieldValue(form, fieldName, payload[fieldName]);
    });

    setApartmentTemplateRichTextValue(form, 'entryInstructions', payload.entryInstructions);
    setApartmentTemplateRichTextValue(form, 'internalNotes', payload.internalNotes);

    const priorityInput = form.querySelector('input[name="isInventoryPriority"]');
    if (priorityInput instanceof HTMLInputElement) {
        priorityInput.checked = Boolean(payload.isInventoryPriority);
    }

    const assignedEmployeesSelect = form.querySelector('select[name="assignedEmployees[]"]');
    if (assignedEmployeesSelect instanceof HTMLSelectElement) {
        const selectedEmployeeIds = Array.isArray(payload.assignedEmployeeIds)
            ? payload.assignedEmployeeIds.map((id) => String(id))
            : [];

        Array.from(assignedEmployeesSelect.options).forEach((option) => {
            option.selected = selectedEmployeeIds.includes(option.value);
        });
    }
}

function parseApartmentTemplatePayload(option) {
    const rawPayload = option.dataset.apartmentTemplatePayload || '';
    if (rawPayload === '') {
        return null;
    }

    try {
        const payload = JSON.parse(rawPayload);
        return payload && typeof payload === 'object' ? payload : null;
    } catch (error) {
        return null;
    }
}

function setApartmentTemplateFieldValue(form, fieldName, value) {
    const field = form.elements.namedItem(fieldName);
    if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLSelectElement) && !(field instanceof HTMLTextAreaElement)) {
        return;
    }

    const normalizedValue = value === null || value === undefined ? '' : String(value);

    if (field instanceof HTMLSelectElement) {
        const hasMatchingOption = Array.from(field.options).some((option) => option.value === normalizedValue);
        if (hasMatchingOption) {
            field.value = normalizedValue;
        }
        return;
    }

    if (field.type === 'checkbox') {
        field.checked = Boolean(value);
        return;
    }

    field.value = normalizedValue;
}

function setApartmentTemplateRichTextValue(form, fieldName, value) {
    const input = form.querySelector(`input[type="hidden"][name="${fieldName}"]`);
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const htmlValue = value === null || value === undefined ? '' : String(value);
    input.value = htmlValue;

    const editor = input.id ? form.querySelector(`trix-editor[input="${input.id}"]`) : null;
    if (!(editor instanceof HTMLElement)) {
        return;
    }

    if (editor.editor && typeof editor.editor.loadHTML === 'function') {
        editor.editor.loadHTML(htmlValue);
    } else {
        editor.addEventListener('trix-initialize', () => {
            if (editor.editor && typeof editor.editor.loadHTML === 'function') {
                editor.editor.loadHTML(htmlValue);
            }
        }, { once: true });
    }
}

function openRoomEquipmentQuantityModal(trigger) {
    if (!(trigger instanceof HTMLButtonElement)) {
        return;
    }

    const modalSelector = trigger.getAttribute('data-modal-target');
    const formSelector = trigger.getAttribute('data-form-target');
    const modalElement = modalSelector ? document.querySelector(modalSelector) : null;
    const form = formSelector ? document.querySelector(formSelector) : null;
    if (!(modalElement instanceof HTMLElement) || !(form instanceof HTMLFormElement)) {
        return;
    }

    modalElement.dataset.roomEquipmentFormTarget = formSelector || '';
    modalElement.dataset.roomEquipmentId = trigger.dataset.equipmentId || '';

    const label = modalElement.querySelector('[data-room-equipment-modal-label]');
    if (label instanceof HTMLElement) {
        label.textContent = `Combien de ${trigger.dataset.equipmentLabel || 'cet équipement'} veux-tu ajouter ?`;
    }

    const quantityInput = modalElement.querySelector('[data-room-equipment-modal-input]');
    if (quantityInput instanceof HTMLInputElement) {
        quantityInput.value = '1';
    }

    showModalElement(modalElement);
}

function confirmRoomEquipmentQuantity(trigger) {
    if (!(trigger instanceof HTMLButtonElement)) {
        return;
    }

    const modalElement = trigger.closest('.modal');
    if (!(modalElement instanceof HTMLElement)) {
        return;
    }

    const formSelector = modalElement.dataset.roomEquipmentFormTarget || '';
    const equipmentId = modalElement.dataset.roomEquipmentId || '';
    const form = formSelector ? document.querySelector(formSelector) : null;
    if (!(form instanceof HTMLFormElement) || equipmentId === '') {
        return;
    }

    const quantityInput = modalElement.querySelector('[data-room-equipment-modal-input]');
    const hiddenQuantityInput = form.querySelector('[data-room-equipment-quantity-hidden]');
    const hiddenCatalogInput = form.querySelector('input[name="catalogEquipmentId"]');
    if (!(quantityInput instanceof HTMLInputElement) || !(hiddenQuantityInput instanceof HTMLInputElement) || !(hiddenCatalogInput instanceof HTMLInputElement)) {
        return;
    }

    hiddenCatalogInput.value = equipmentId;
    hiddenQuantityInput.value = `${Math.max(1, Number.parseInt(quantityInput.value || '1', 10) || 1)}`;
    hideModalElement(modalElement);
    form.requestSubmit();
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

function captureCheckoutLineTransition(form) {
    if (!(form instanceof HTMLFormElement)) {
        return null;
    }

    const line = form.closest('[data-check-line-id]');
    if (!(line instanceof HTMLElement)) {
        return null;
    }

    const targetSelector = form.getAttribute('data-update-target');
    const target = targetSelector ? document.querySelector(targetSelector) : null;
    const positions = {};
    if (target instanceof HTMLElement) {
        target.querySelectorAll('[data-check-line-id]').forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            const itemId = item.getAttribute('data-check-line-id');
            if (!itemId) {
                return;
            }

            positions[itemId] = item.getBoundingClientRect().top;
        });
    }

    const checkedStatusInput = form.querySelector('input[name="status"]:checked');
    const statusValue = checkedStatusInput instanceof HTMLInputElement ? checkedStatusInput.value : null;

    return {
        lineId: line.getAttribute('data-check-line-id'),
        positions,
        statusTone: statusValue === 'ok' ? 'success' : 'warning',
    };
}

function animateCheckoutLineTransition(targetSelector, transition) {
    if (!transition?.lineId) {
        return;
    }

    window.requestAnimationFrame(() => {
        const target = document.querySelector(targetSelector);
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const statusToneClass = transition.statusTone === 'success'
            ? 'is-just-validated-success'
            : 'is-just-validated-warning';
        const validationLine = target.querySelector(`[data-check-line-id="${transition.lineId}"]`);
        const validatedStartTop = typeof transition.positions?.[transition.lineId] === 'number'
            ? transition.positions[transition.lineId]
            : null;
        let promotedLine = null;
        let promotedDistance = Number.POSITIVE_INFINITY;

        target.querySelectorAll('[data-check-line-id]').forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            const itemId = item.getAttribute('data-check-line-id');
            if (!itemId || typeof transition.positions?.[itemId] !== 'number') {
                return;
            }

            const newTop = item.getBoundingClientRect().top;
            const deltaY = transition.positions[itemId] - newTop;

            if (Math.abs(deltaY) <= 3) {
                return;
            }

            if (
                item !== validationLine
                && validatedStartTop !== null
                && item.getBoundingClientRect().top <= validatedStartTop + 14
            ) {
                const distanceToValidatedSlot = Math.abs(item.getBoundingClientRect().top - validatedStartTop);
                if (distanceToValidatedSlot < promotedDistance) {
                    promotedDistance = distanceToValidatedSlot;
                    promotedLine = item;
                }
            }

            item.animate([
                { transform: `translateY(${deltaY}px)` },
                { transform: 'translateY(0)' },
            ], {
                duration: 920,
                easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                fill: 'both',
            });
        });

        if (validationLine instanceof HTMLElement) {
            validationLine.classList.add('is-just-validated', statusToneClass);
            validationLine.dataset.validationNotice = 'Validé';

            const validatedOriginTop = validatedStartTop ?? validationLine.getBoundingClientRect().top;
            const validatedDeltaY = validatedOriginTop - validationLine.getBoundingClientRect().top;

            validationLine.animate([
                {
                    transform: `translateY(${validatedDeltaY}px) scale(1)`,
                    opacity: 1,
                    filter: 'blur(0)',
                    boxShadow: '0 22px 38px rgba(15, 23, 42, 0.16)',
                },
                {
                    transform: `translateY(${validatedDeltaY * 0.54}px) scale(0.84)`,
                    opacity: 0.92,
                    filter: 'blur(0.4px)',
                    boxShadow: '0 18px 32px rgba(15, 23, 42, 0.14)',
                    offset: 0.22,
                },
                {
                    transform: `translateY(${validatedDeltaY * 0.78}px) scale(0.46)`,
                    opacity: 0.32,
                    filter: 'blur(1.8px)',
                    boxShadow: '0 10px 20px rgba(15, 23, 42, 0.08)',
                    offset: 0.48,
                },
                {
                    transform: 'translateY(0) scale(0.9)',
                    opacity: 0.1,
                    filter: 'blur(2.6px)',
                    boxShadow: '0 0 0 rgba(15, 23, 42, 0)',
                    offset: 0.68,
                },
                {
                    transform: 'translateY(0) scale(1)',
                    opacity: 1,
                    filter: 'blur(0)',
                    boxShadow: '0 20px 42px rgba(15, 23, 42, 0.14)',
                },
            ], {
                duration: 1500,
                easing: 'cubic-bezier(0.18, 1, 0.32, 1)',
                fill: 'both',
            });
        }

        if (promotedLine instanceof HTMLElement) {
            promotedLine.classList.add('is-next-up');
            promotedLine.animate([
                {
                    transform: 'translateY(40px) scale(0.97)',
                    opacity: 0.28,
                    filter: 'blur(1.6px)',
                },
                {
                    transform: 'translateY(-8px) scale(1.01)',
                    opacity: 1,
                    filter: 'blur(0)',
                    offset: 0.72,
                },
                {
                    transform: 'translateY(0) scale(1)',
                    opacity: 1,
                    filter: 'blur(0)',
                },
            ], {
                duration: 720,
                delay: 260,
                easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
                fill: 'both',
            });
        }

        window.setTimeout(() => {
            if (validationLine instanceof HTMLElement) {
                validationLine.classList.remove('is-just-validated', 'is-just-validated-success', 'is-just-validated-warning');
                delete validationLine.dataset.validationNotice;
            }
            if (promotedLine instanceof HTMLElement) {
                promotedLine.classList.remove('is-next-up');
            }
        }, 2300);
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

        const isActive = trigger.getAttribute('data-panel-open') === targetSelector;
        trigger.classList.toggle('is-active', isActive);
        trigger.setAttribute('aria-expanded', isActive ? 'true' : 'false');
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

    syncTenantPanelLayer();
}

function initializeAppearancePalette(scope = document) {
    if (!scope || typeof scope.querySelectorAll !== 'function') {
        return;
    }

    scope.querySelectorAll('[data-appearance-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.appearanceReady === 'true') {
            return;
        }

        form.dataset.appearanceReady = 'true';

        form.querySelectorAll('[data-appearance-color]').forEach((colorInput) => {
            if (!(colorInput instanceof HTMLInputElement)) {
                return;
            }

            const fieldName = colorInput.dataset.appearanceColor || '';
            const codeInput = form.querySelector(`[data-appearance-color-code="${fieldName}"]`);
            const syncCodeFromColor = () => {
                if (codeInput instanceof HTMLInputElement) {
                    codeInput.value = colorInput.value.toLowerCase();
                    codeInput.classList.remove('is-invalid');
                }

                if (fieldName === 'primaryColor') {
                    updateDerivedTertiaryColor(form);
                }
            };

            colorInput.addEventListener('input', syncCodeFromColor);
            syncCodeFromColor();

            if (codeInput instanceof HTMLInputElement) {
                codeInput.addEventListener('input', () => {
                    const normalizedColor = normalizeAppearanceColor(codeInput.value);
                    if (!normalizedColor) {
                        codeInput.classList.toggle('is-invalid', codeInput.value.trim() !== '');
                        return;
                    }

                    colorInput.value = normalizedColor;
                    codeInput.value = normalizedColor;
                    codeInput.classList.remove('is-invalid');

                    if (fieldName === 'primaryColor') {
                        updateDerivedTertiaryColor(form);
                    }
                });

                codeInput.addEventListener('blur', () => {
                    const normalizedColor = normalizeAppearanceColor(codeInput.value);
                    if (normalizedColor) {
                        colorInput.value = normalizedColor;
                        codeInput.value = normalizedColor;
                        codeInput.classList.remove('is-invalid');
                        if (fieldName === 'primaryColor') {
                            updateDerivedTertiaryColor(form);
                        }
                        return;
                    }

                    syncCodeFromColor();
                });
            }
        });

        form.querySelectorAll('[data-appearance-theme]').forEach((themeButton) => {
            if (!(themeButton instanceof HTMLButtonElement)) {
                return;
            }

            themeButton.addEventListener('click', () => {
                let colors = {};
                try {
                    colors = JSON.parse(themeButton.dataset.appearanceTheme || '{}');
                } catch (error) {
                    colors = {};
                }

                Object.entries(colors).forEach(([fieldName, value]) => {
                    const normalizedColor = normalizeAppearanceColor(String(value));
                    const colorInput = form.querySelector(`[data-appearance-color="${fieldName}"]`);
                    const codeInput = form.querySelector(`[data-appearance-color-code="${fieldName}"]`);
                    if (!normalizedColor || !(colorInput instanceof HTMLInputElement)) {
                        return;
                    }

                    colorInput.value = normalizedColor;
                    colorInput.dispatchEvent(new Event('input', { bubbles: true }));

                    if (codeInput instanceof HTMLInputElement) {
                        codeInput.value = normalizedColor;
                        codeInput.classList.remove('is-invalid');
                    }
                });

                updateDerivedTertiaryColor(form);

                form.querySelectorAll('[data-appearance-theme]').forEach((button) => {
                    if (button instanceof HTMLElement) {
                        button.classList.toggle('is-selected', button === themeButton);
                    }
                });
            });
        });
    });
}

function normalizeAppearanceColor(value) {
    const rawValue = value.trim().toLowerCase();
    const shortHexMatch = rawValue.match(/^#?([0-9a-f]{3})$/);
    if (shortHexMatch) {
        return `#${shortHexMatch[1].split('').map((char) => `${char}${char}`).join('')}`;
    }

    const hexMatch = rawValue.match(/^#?([0-9a-f]{6})$/);
    return hexMatch ? `#${hexMatch[1]}` : null;
}

function updateDerivedTertiaryColor(form) {
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const primaryInput = form.querySelector('[data-appearance-color="primaryColor"]');
    if (!(primaryInput instanceof HTMLInputElement)) {
        return;
    }

    const derivedColor = deriveAppearanceTertiaryColor(primaryInput.value);
    const colorPreview = form.querySelector('[data-appearance-derived-tertiary-color]');
    const codePreview = form.querySelector('[data-appearance-derived-tertiary-code]');
    const hiddenInput = form.querySelector('[data-appearance-derived-tertiary-input]');

    if (colorPreview instanceof HTMLInputElement) {
        colorPreview.value = derivedColor;
    }

    if (codePreview instanceof HTMLInputElement) {
        codePreview.value = derivedColor;
    }

    if (hiddenInput instanceof HTMLInputElement) {
        hiddenInput.value = derivedColor;
    }
}

function deriveAppearanceTertiaryColor(primaryColor) {
    const normalizedColor = normalizeAppearanceColor(primaryColor) || '#ff385c';
    const rgb = hexToRgb(normalizedColor);
    if (!rgb) {
        return '#f49fb4';
    }

    const [hue, saturation] = rgbToHsl(rgb.red, rgb.green, rgb.blue);
    return hslToHex(hue, Math.min(saturation, 0.8), 0.79);
}

function hexToRgb(hex) {
    const normalizedColor = normalizeAppearanceColor(hex);
    if (!normalizedColor) {
        return null;
    }

    return {
        red: Number.parseInt(normalizedColor.slice(1, 3), 16),
        green: Number.parseInt(normalizedColor.slice(3, 5), 16),
        blue: Number.parseInt(normalizedColor.slice(5, 7), 16),
    };
}

function rgbToHsl(red, green, blue) {
    red /= 255;
    green /= 255;
    blue /= 255;

    const max = Math.max(red, green, blue);
    const min = Math.min(red, green, blue);
    const lightness = (max + min) / 2;

    if (max === min) {
        return [0, 0, lightness];
    }

    const delta = max - min;
    const saturation = lightness > 0.5
        ? delta / (2 - max - min)
        : delta / (max + min);

    let hue = 0;
    if (max === red) {
        hue = ((green - blue) / delta) + (green < blue ? 6 : 0);
    } else if (max === green) {
        hue = ((blue - red) / delta) + 2;
    } else {
        hue = ((red - green) / delta) + 4;
    }

    return [hue * 60, saturation, lightness];
}

function hslToHex(hue, saturation, lightness) {
    const chroma = (1 - Math.abs((2 * lightness) - 1)) * saturation;
    const huePrime = hue / 60;
    const x = chroma * (1 - Math.abs((huePrime % 2) - 1));
    const m = lightness - (chroma / 2);
    let red = 0;
    let green = 0;
    let blue = 0;

    if (huePrime < 1) {
        [red, green, blue] = [chroma, x, 0];
    } else if (huePrime < 2) {
        [red, green, blue] = [x, chroma, 0];
    } else if (huePrime < 3) {
        [red, green, blue] = [0, chroma, x];
    } else if (huePrime < 4) {
        [red, green, blue] = [0, x, chroma];
    } else if (huePrime < 5) {
        [red, green, blue] = [x, 0, chroma];
    } else {
        [red, green, blue] = [chroma, 0, x];
    }

    return `#${[red, green, blue].map((value) => {
        const colorPart = Math.round((value + m) * 255).toString(16);
        return colorPart.padStart(2, '0');
    }).join('')}`;
}

function closePanelGroup(groupName) {
    if (!groupName) {
        return;
    }

    document.querySelectorAll(`[data-panel-name][data-panel-group="${groupName}"]`).forEach((panel) => {
        if (panel instanceof HTMLElement) {
            panel.classList.add('is-collapsed');
        }
    });

    syncPanelTriggers(document);
}

function syncTenantPanelLayer() {
    const hasOpenTenantPanel = document.querySelector('[data-panel-name][data-panel-group="tenant-public"]:not(.is-collapsed)') instanceof HTMLElement;
    const backdrop = document.getElementById('tenant-panel-backdrop');

    document.body.classList.toggle('tenant-panel-open', hasOpenTenantPanel);
    if (backdrop instanceof HTMLElement) {
        backdrop.classList.toggle('is-collapsed', !hasOpenTenantPanel);
    }
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
                            <h5 class="modal-title" id="confirmActionModalLabel">Confirmer l’action</h5>
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
