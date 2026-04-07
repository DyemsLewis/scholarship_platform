(function () {
    const instances = new Map();
    const updateEventName = 'card-pagination:update';

    function getContainerKey(container) {
        return container.id || container.dataset.paginationKey || Math.random().toString(36).slice(2);
    }

    function getItems(instance) {
        return Array.from(instance.container.querySelectorAll(instance.itemSelector)).filter((item) => item.dataset.paginationIgnore !== 'true');
    }

    function getFilteredItems(items) {
        return items.filter((item) => item.dataset.filterVisible !== 'false');
    }

    function setItemVisibility(item, shouldShow) {
        item.style.display = shouldShow ? '' : 'none';
        item.dataset.paginationVisible = shouldShow ? 'true' : 'false';
        item.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
    }

    function buildButton(label, page, disabled, active, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'card-pagination-btn' + (active ? ' is-active' : '');
        button.textContent = label;
        button.disabled = disabled;
        if (!disabled) {
            button.addEventListener('click', function () {
                onClick(page);
            });
        }
        return button;
    }

    function ensureControls(instance) {
        let shell = instance.container.nextElementSibling;
        if (!shell || !shell.classList.contains('card-pagination')) {
            shell = document.createElement('div');
            shell.className = 'card-pagination';
            shell.dataset.paginationFor = instance.key;

            const summary = document.createElement('div');
            summary.className = 'card-pagination-summary';

            const nav = document.createElement('div');
            nav.className = 'card-pagination-nav';

            shell.appendChild(summary);
            shell.appendChild(nav);
            instance.container.insertAdjacentElement('afterend', shell);
        }

        instance.shell = shell;
        instance.summary = shell.querySelector('.card-pagination-summary');
        instance.nav = shell.querySelector('.card-pagination-nav');
    }

    function render(instance, options) {
        const opts = options || {};
        if (opts.resetPage) {
            instance.currentPage = 1;
        }

        const items = getItems(instance);
        items.forEach((item) => {
            if (!Object.prototype.hasOwnProperty.call(item.dataset, 'filterVisible')) {
                item.dataset.filterVisible = 'true';
            }
        });

        const filteredItems = getFilteredItems(items);
        const totalItems = filteredItems.length;
        const totalPages = totalItems > 0 ? Math.ceil(totalItems / instance.pageSize) : 0;

        if (totalPages === 0) {
            instance.currentPage = 1;
        } else if (instance.currentPage > totalPages) {
            instance.currentPage = totalPages;
        }

        const startIndex = totalItems === 0 ? 0 : (instance.currentPage - 1) * instance.pageSize;
        const endIndex = Math.min(startIndex + instance.pageSize, totalItems);

        items.forEach((item) => setItemVisibility(item, false));
        filteredItems.forEach((item, index) => {
            const shouldShow = index >= startIndex && index < endIndex;
            setItemVisibility(item, shouldShow);
        });

        ensureControls(instance);

        if (totalItems <= instance.pageSize) {
            instance.shell.classList.add('is-hidden');
            instance.summary.textContent = totalItems === 0 ? '' : 'Showing all ' + totalItems + ' ' + instance.label;
            instance.nav.innerHTML = '';
            return;
        }

        instance.shell.classList.remove('is-hidden');
        instance.summary.textContent = 'Showing ' + (startIndex + 1) + '-' + endIndex + ' of ' + totalItems + ' ' + instance.label;
        instance.nav.innerHTML = '';

        const goToPage = function (page) {
            if (page < 1 || page > totalPages || page === instance.currentPage) {
                return;
            }
            instance.currentPage = page;
            render(instance);
            instance.container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        instance.nav.appendChild(buildButton('Prev', instance.currentPage - 1, instance.currentPage === 1, false, goToPage));

        for (let page = 1; page <= totalPages; page += 1) {
            instance.nav.appendChild(buildButton(String(page), page, false, page === instance.currentPage, goToPage));
        }

        instance.nav.appendChild(buildButton('Next', instance.currentPage + 1, instance.currentPage === totalPages, false, goToPage));
    }

    function initContainer(container) {
        const key = getContainerKey(container);
        let instance = instances.get(key);
        if (!instance) {
            instance = {
                key: key,
                container: container,
                itemSelector: container.dataset.itemSelector || '.scholarship-card',
                pageSize: Math.max(parseInt(container.dataset.pageSize || '6', 10) || 6, 1),
                label: container.dataset.paginationLabel || 'items',
                currentPage: 1,
                shell: null,
                summary: null,
                nav: null
            };
            instances.set(key, instance);
        }
        render(instance);
        return instance;
    }

    function refreshByContainerId(containerId, resetPage) {
        if (!containerId) {
            instances.forEach((instance) => render(instance, { resetPage: !!resetPage }));
            return;
        }

        const container = document.getElementById(containerId);
        if (!container) {
            return;
        }

        const instance = initContainer(container);
        render(instance, { resetPage: !!resetPage });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-pagination="cards"]').forEach(initContainer);
    });

    document.addEventListener(updateEventName, function (event) {
        const detail = event.detail || {};
        refreshByContainerId(detail.containerId, detail.resetPage);
    });

    window.CardPagination = {
        refresh: refreshByContainerId
    };
})();
