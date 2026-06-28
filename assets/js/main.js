(() => {
    'use strict';

    const storageKey = 'ntn_erp_sidebar_collapsed';
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const notificationBell = document.getElementById('notificationBell');
    const notificationList = document.getElementById('notificationList');
    const notificationCount = document.getElementById('notificationCount');
    const refreshNotifications = document.getElementById('refreshNotifications');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const applySidebarState = () => {
        if (!sidebar) {
            return;
        }

        const collapsed = localStorage.getItem(storageKey) === '1';
        if (window.innerWidth > 768) {
            sidebar.classList.toggle('collapsed', collapsed);
            sidebar.classList.remove('mobile-open');
        } else {
            sidebar.classList.remove('collapsed');
        }
    };

    const toggleSidebar = () => {
        if (!sidebar) {
            return;
        }

        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            return;
        }

        const collapsed = !sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed', collapsed);
        localStorage.setItem(storageKey, collapsed ? '1' : '0');
    };

    const dismissFlashMessages = () => {
        document.querySelectorAll('.flash-message').forEach((element) => {
            window.setTimeout(() => {
                if (window.bootstrap?.Alert) {
                    const instance = bootstrap.Alert.getOrCreateInstance(element);
                    instance.close();
                } else {
                    element.remove();
                }
            }, 5000);
        });
    };

    const attachDeleteConfirm = () => {
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-confirm-delete]');
            if (!trigger) {
                return;
            }

            const message = trigger.getAttribute('data-confirm-message') || 'Bạn có chắc chắn muốn xóa dữ liệu này?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    };

    const formatMoneyValue = (value) => {
        const numeric = String(value || '').replace(/[^\d-]/g, '');
        if (numeric === '' || numeric === '-') {
            return numeric;
        }
        return Number(numeric).toLocaleString('vi-VN');
    };

    const initMoneyInputs = () => {
        const moneyInputs = document.querySelectorAll('.money-input');
        moneyInputs.forEach((input) => {
            if (input.value) {
                input.value = formatMoneyValue(input.value);
            }

            input.addEventListener('input', () => {
                const selectionStart = input.selectionStart || input.value.length;
                input.value = formatMoneyValue(input.value);
                input.setSelectionRange(input.value.length, input.value.length || selectionStart);
            });
        });

        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                moneyInputs.forEach((input) => {
                    input.value = input.value.replace(/\./g, '').replace(/,/g, '');
                });
            });
        });
    };

    const initDynamicRows = () => {
        document.addEventListener('click', (event) => {
            const addButton = event.target.closest('[data-add-row]');
            if (addButton) {
                const containerSelector = addButton.getAttribute('data-add-row');
                const templateSelector = addButton.getAttribute('data-row-template');
                const container = document.querySelector(containerSelector);
                if (!container) {
                    return;
                }

                let rowHtml = '';
                const template = templateSelector ? document.querySelector(templateSelector) : null;
                if (template && 'content' in template) {
                    const clone = template.content.cloneNode(true);
                    container.appendChild(clone);
                } else if (template) {
                    rowHtml = template.innerHTML;
                    container.insertAdjacentHTML('beforeend', rowHtml);
                } else {
                    const lastRow = container.lastElementChild;
                    if (lastRow) {
                        const clone = lastRow.cloneNode(true);
                        clone.querySelectorAll('input, select, textarea').forEach((field) => {
                            if (field.type === 'checkbox' || field.type === 'radio') {
                                field.checked = false;
                            } else {
                                field.value = '';
                            }
                        });
                        container.appendChild(clone);
                    }
                }
                return;
            }

            const removeButton = event.target.closest('[data-remove-row]');
            if (removeButton) {
                const row = removeButton.closest(removeButton.getAttribute('data-remove-row') || '.dynamic-row');
                if (row) {
                    row.remove();
                }
            }
        });
    };

    const initTooltips = () => {
        if (!window.bootstrap?.Tooltip) {
            return;
        }
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
            bootstrap.Tooltip.getOrCreateInstance(element);
        });
    };

    const patchAjaxCsrf = () => {
        if (!csrfToken) {
            return;
        }

        const originalFetch = window.fetch;
        if (originalFetch) {
            window.fetch = (resource, options = {}) => {
                const headers = new Headers(options.headers || {});
                headers.set('X-CSRF-TOKEN', csrfToken);
                if (!(options.body instanceof FormData) && !headers.has('Content-Type') && options.method && options.method.toUpperCase() !== 'GET') {
                    headers.set('Content-Type', 'application/json');
                }
                return originalFetch(resource, {
                    ...options,
                    headers,
                    credentials: options.credentials || 'same-origin',
                });
            };
        }

        const originalSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function send(body) {
            if (csrfToken) {
                try {
                    this.setRequestHeader('X-CSRF-TOKEN', csrfToken);
                } catch (error) {
                }
            }
            return originalSend.call(this, body);
        };
    };

    const renderNotifications = (payload) => {
        if (!notificationList || !notificationCount) {
            return;
        }

        const items = Array.isArray(payload) ? payload : (payload.items || []);
        const count = Number(Array.isArray(payload) ? payload.length : (payload.count ?? items.length));
        notificationCount.textContent = String(count);
        notificationCount.classList.toggle('d-none', count <= 0);

        if (items.length === 0) {
            notificationList.innerHTML = '<div class="notification-empty">Chưa có thông báo mới.</div>';
            return;
        }

        notificationList.innerHTML = items.map((item) => {
            const title = item.title || item.message || 'Thông báo hệ thống';
            const time = item.time || item.created_at || '';
            const url = item.url || '#';
            return `
                <a class="dropdown-item notification-item" href="${url}">
                    <div class="notification-item-title">${title}</div>
                    <span class="notification-item-time">${time}</span>
                </a>
            `;
        }).join('');
    };

    const loadNotifications = async () => {
        if (!notificationBell || !notificationList) {
            return;
        }

        try {
            notificationList.innerHTML = '<div class="notification-empty"><span class="loading-spinner"></span></div>';
            const response = await fetch('/ntn_erp/api/get_notifications.php', {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            renderNotifications(payload);
        } catch (error) {
            notificationList.innerHTML = '<div class="notification-empty">Không thể tải thông báo.</div>';
            notificationCount.classList.add('d-none');
        }
    };

    const initNotifications = () => {
        if (!notificationBell) {
            return;
        }

        notificationBell.addEventListener('shown.bs.dropdown', loadNotifications);
        refreshNotifications?.addEventListener('click', (event) => {
            event.preventDefault();
            loadNotifications();
        });
        window.setInterval(loadNotifications, 60000);
    };

    const initDateHelpers = () => {
        document.querySelectorAll('[data-date-from][data-date-to]').forEach((source) => {
            source.addEventListener('change', () => {
                const target = document.getElementById(source.getAttribute('data-date-to'));
                if (target && source.value) {
                    target.min = source.value;
                    if (target.value && target.value < source.value) {
                        target.value = source.value;
                    }
                }
            });
        });
    };

    window.printPage = () => window.print();
    window.setDateRange = (fromId, toId, days = 0) => {
        const fromInput = document.getElementById(fromId);
        const toInput = document.getElementById(toId);
        if (!fromInput || !toInput || !fromInput.value) {
            return;
        }

        const startDate = new Date(fromInput.value);
        if (Number.isNaN(startDate.getTime())) {
            return;
        }

        startDate.setDate(startDate.getDate() + Number(days || 0));
        const month = String(startDate.getMonth() + 1).padStart(2, '0');
        const day = String(startDate.getDate()).padStart(2, '0');
        toInput.value = `${startDate.getFullYear()}-${month}-${day}`;
    };

    document.addEventListener('DOMContentLoaded', () => {
        applySidebarState();
        dismissFlashMessages();
        attachDeleteConfirm();
        initMoneyInputs();
        initDynamicRows();
        initTooltips();
        patchAjaxCsrf();
        initNotifications();
        initDateHelpers();

        sidebarToggle?.addEventListener('click', toggleSidebar);
        window.addEventListener('resize', applySidebarState);
        document.addEventListener('click', (event) => {
            if (window.innerWidth <= 768 && sidebar?.classList.contains('mobile-open')) {
                if (!event.target.closest('#sidebar') && !event.target.closest('#sidebarToggle')) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });
    });
})();
