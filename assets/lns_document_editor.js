const readBooleanChoice = (form) => {
    const checked = form.querySelector('input[name$="[autoGenerateToc]"]:checked');

    return checked ? ['1', 'true'].includes(checked.value) : true;
};

const MAX_SOURCE_IMAGE_BYTES = 10_000_000;
const MAX_STORED_IMAGE_BYTES = 1_200_000;
const MAX_SOURCE_IMAGE_PIXELS = 60_000_000;

const estimateDataUrlBytes = (dataUrl) => {
    const base64 = dataUrl.slice(dataUrl.indexOf(',') + 1);

    return Math.floor((base64.length * 3) / 4);
};

const loadBrowserImage = (file) => new Promise((resolve, reject) => {
    const objectUrl = URL.createObjectURL(file);
    const image = new Image();

    image.onload = () => {
        URL.revokeObjectURL(objectUrl);
        resolve(image);
    };
    image.onerror = () => {
        URL.revokeObjectURL(objectUrl);
        reject(new Error('Le fichier sélectionné ne peut pas être lu comme une image.'));
    };
    image.src = objectUrl;
});

const compressImage = async (file) => {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        throw new Error('Choisissez une image JPEG, PNG ou WebP.');
    }

    if (file.size > MAX_SOURCE_IMAGE_BYTES) {
        throw new Error('L’image source ne peut pas dépasser 10 Mo.');
    }

    const image = await loadBrowserImage(file);

    if ((image.naturalWidth * image.naturalHeight) > MAX_SOURCE_IMAGE_PIXELS) {
        throw new Error('Les dimensions de cette image sont trop importantes.');
    }

    for (const maxDimension of [1600, 1400, 1200, 1000]) {
        const scale = Math.min(1, maxDimension / Math.max(image.naturalWidth, image.naturalHeight));
        const width = Math.max(1, Math.round(image.naturalWidth * scale));
        const height = Math.max(1, Math.round(image.naturalHeight * scale));
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        canvas.width = width;
        canvas.height = height;
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(image, 0, 0, width, height);

        for (const quality of [.84, .72, .60, .48]) {
            const dataUrl = canvas.toDataURL('image/jpeg', quality);

            if (estimateDataUrlBytes(dataUrl) <= MAX_STORED_IMAGE_BYTES) {
                return dataUrl;
            }
        }
    }

    throw new Error('Cette image reste trop volumineuse après optimisation.');
};

const initializeSaveMenu = () => {
    const menu = document.querySelector('[data-save-menu]');

    if (!menu || menu.dataset.initialized === 'true') {
        return;
    }

    menu.dataset.initialized = 'true';
    const toggle = menu.querySelector('[data-action="toggle-save-menu"]');
    const panel = menu.querySelector('[data-save-menu-panel]');

    toggle?.addEventListener('click', () => {
        const willOpen = panel.hidden;
        panel.hidden = !willOpen;
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) {
            panel.hidden = true;
            toggle?.setAttribute('aria-expanded', 'false');
        }
    });
};

const initializeEditor = (editor) => {
    if (editor.dataset.initialized === 'true') {
        return;
    }

    editor.dataset.initialized = 'true';
    const form = editor.closest('form');
    const pagesContainer = editor.querySelector('[data-pages-container]');
    const hiddenContent = form.querySelector('input[name$="[contentJson]"]');
    const revisionField = form.querySelector('input[name$="[revision]"]');
    const titleField = form.querySelector('[data-document-title]');
    const descriptionField = form.querySelector('textarea[name$="[description]"]');
    const navigatorList = form.querySelector('[data-page-navigation-list]');
    const pageCount = form.querySelector('[data-page-count]');
    const autosaveStatus = form.querySelector('[data-autosave-status]');
    const autosaveStatusText = autosaveStatus?.querySelector('span');
    const retryAutosaveButton = autosaveStatus?.querySelector('[data-action="retry-autosave"]');
    const orderFeedback = form.querySelector('[data-order-feedback]');
    const orderFeedbackText = orderFeedback?.querySelector('span');
    const pageTemplate = editor.querySelector('[data-page-template]');
    const textBlockTemplate = editor.querySelector('[data-text-block-template]');
    const tableBlockTemplate = editor.querySelector('[data-table-block-template]');
    const imageBlockTemplate = editor.querySelector('[data-image-block-template]');
    const tableRowTemplate = editor.querySelector('[data-table-row-template]');
    const maxPages = Number(editor.dataset.maxPages || 50);
    const maxBlocks = Number(editor.dataset.maxBlocks || 30);
    const maxRows = Number(editor.dataset.maxRows || 100);
    let autosaveUrl = editor.dataset.autosaveUrl;
    let autosaveMethod = editor.dataset.autosaveMethod || 'PATCH';
    let documentId = editor.dataset.documentId || '';
    let revision = Number(editor.dataset.revision || revisionField?.value || 1);
    let autosaveTimer = null;
    let localBackupTimer = null;
    let orderFeedbackTimer = null;
    let activeSave = null;
    let dirty = false;
    let autosavePaused = false;
    let manualSubmitting = false;
    let draggedPage = null;
    let draggedNavigatorItem = null;
    let pointerDragHandle = null;
    let pointerDragTarget = null;

    const cloneElement = (template) => template.content.firstElementChild.cloneNode(true);
    const createPageKey = () => globalThis.crypto?.randomUUID?.()
        || `page-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const localStorageKey = () => `lns-document-draft:${documentId || 'new'}`;

    const updateAutosaveStatus = (state, message, retry = false) => {
        if (!autosaveStatus || !autosaveStatusText) {
            return;
        }

        autosaveStatus.dataset.autosaveState = state;
        autosaveStatusText.textContent = message;
        retryAutosaveButton.hidden = !retry;

        const icon = autosaveStatus.querySelector('i');
        if (icon) {
            icon.className = state === 'saving'
                ? 'fas fa-circle-notch fa-spin'
                : state === 'saved'
                    ? 'fas fa-cloud-upload-alt'
                    : 'fas fa-exclamation-triangle';
        }
    };

    const readLocalSnapshot = () => {
        try {
            const stored = localStorage.getItem(localStorageKey());
            const snapshot = stored ? JSON.parse(stored) : null;

            return snapshot && snapshot.dirty === true && typeof snapshot.payload?.contentJson === 'string'
                ? snapshot
                : null;
        } catch (error) {
            return null;
        }
    };

    const setImageData = (block, dataUrl) => {
        const hasImage = typeof dataUrl === 'string' && dataUrl !== '';
        const preview = block.querySelector('[data-image-preview]');
        const dataField = block.querySelector('[data-field="image-data"]');
        dataField.value = hasImage ? dataUrl : '';
        block.dataset.hasImage = hasImage ? 'true' : 'false';
        preview.hidden = !hasImage;

        if (hasImage) {
            preview.src = dataUrl;
        } else {
            preview.removeAttribute('src');
        }
    };

    const addTableRow = (tableBlock, values = ['', '', '']) => {
        const rowsContainer = tableBlock.querySelector('[data-table-rows]');

        if (rowsContainer.querySelectorAll('[data-table-row]').length >= maxRows) {
            window.alert(`Un tableau ne peut pas dépasser ${maxRows} lignes.`);
            return null;
        }

        const row = cloneElement(tableRowTemplate);
        row.querySelectorAll('[data-field="table-cell"]').forEach((cell, index) => {
            cell.value = typeof values[index] === 'string' ? values[index] : '';
        });
        rowsContainer.appendChild(row);

        return row;
    };

    const addBlock = (page, data) => {
        const blockList = page.querySelector('[data-block-list]');

        if (blockList.querySelectorAll(':scope > [data-block]').length >= maxBlocks) {
            window.alert(`Une page ne peut pas dépasser ${maxBlocks} blocs.`);
            return null;
        }

        if (data.type === 'image') {
            const block = cloneElement(imageBlockTemplate);
            block.querySelector('[data-field="block-title"]').value = data.title || '';
            block.querySelector('[data-field="image-caption"]').value = data.caption || '';
            setImageData(block, data.data || '');
            blockList.appendChild(block);

            return block;
        }

        if (data.type === 'table') {
            const block = cloneElement(tableBlockTemplate);
            block.querySelector('[data-field="block-title"]').value = data.title || '';
            const headers = Array.isArray(data.headers) && data.headers.length === 3
                ? data.headers
                : ['Élément', 'Détail', 'Statut'];
            block.querySelectorAll('[data-field="table-header"]').forEach((header, index) => {
                header.value = typeof headers[index] === 'string' ? headers[index] : '';
            });
            const rows = Array.isArray(data.rows) ? data.rows : [['', '', '']];
            rows.forEach((row) => addTableRow(block, row));
            blockList.appendChild(block);

            return block;
        }

        const block = cloneElement(textBlockTemplate);
        block.querySelector('[data-field="block-title"]').value = data.title || '';
        block.querySelector('[data-field="block-description"]').value = data.description || '';
        blockList.appendChild(block);

        return block;
    };

    const addPage = (data = {title: '', description: '', blocks: []}, beforePage = null) => {
        if (pagesContainer.querySelectorAll(':scope > [data-page]').length >= maxPages) {
            window.alert(`Un document ne peut pas dépasser ${maxPages} pages.`);
            return null;
        }

        const page = cloneElement(pageTemplate);
        page.dataset.pageKey = createPageKey();
        page.querySelector('[data-field="page-title"]').value = data.title || '';
        page.querySelector('[data-field="page-description"]').value = data.description || '';
        (Array.isArray(data.blocks) ? data.blocks : []).forEach((block) => addBlock(page, block));

        if (beforePage) {
            pagesContainer.insertBefore(page, beforePage);
        } else {
            pagesContainer.appendChild(page);
        }

        return page;
    };

    const serializePage = (page) => ({
        title: page.querySelector('[data-field="page-title"]').value,
        description: page.querySelector('[data-field="page-description"]').value,
        blocks: Array.from(page.querySelectorAll('[data-block-list] > [data-block]')).map((block) => {
            if (block.dataset.blockType === 'image') {
                return {
                    type: 'image',
                    title: block.querySelector('[data-field="block-title"]').value,
                    data: block.querySelector('[data-field="image-data"]').value,
                    caption: block.querySelector('[data-field="image-caption"]').value,
                };
            }

            if (block.dataset.blockType === 'table') {
                return {
                    type: 'table',
                    title: block.querySelector('[data-field="block-title"]').value,
                    headers: Array.from(block.querySelectorAll('[data-field="table-header"]')).map((header) => header.value),
                    rows: Array.from(block.querySelectorAll('[data-table-row]')).map((row) =>
                        Array.from(row.querySelectorAll('[data-field="table-cell"]')).map((cell) => cell.value)
                    ),
                };
            }

            return {
                type: 'text',
                title: block.querySelector('[data-field="block-title"]').value,
                description: block.querySelector('[data-field="block-description"]').value,
            };
        }),
    });

    const serialize = () => Array.from(pagesContainer.querySelectorAll(':scope > [data-page]')).map(serializePage);

    const findPageByKey = (key) => Array.from(pagesContainer.querySelectorAll(':scope > [data-page]'))
        .find((page) => page.dataset.pageKey === key) || null;

    const refreshInsertionControls = () => {
        pagesContainer.querySelectorAll(':scope > [data-page-insert]').forEach((control) => control.remove());

        pagesContainer.querySelectorAll(':scope > [data-page]').forEach((page, index) => {
            if (index === 0) {
                return;
            }

            const control = document.createElement('div');
            control.className = 'lns-insert-page-control';
            control.dataset.pageInsert = '';

            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.action = 'insert-page-before';
            button.innerHTML = '<i class="fas fa-plus"></i> Insérer une page ici';
            control.appendChild(button);
            pagesContainer.insertBefore(control, page);
        });
    };

    const refreshNavigator = (pages) => {
        if (!navigatorList) {
            return;
        }

        navigatorList.replaceChildren();
        if (pageCount) {
            pageCount.textContent = String(pages.length);
        }

        pagesContainer.querySelectorAll(':scope > [data-page]').forEach((page, index) => {
            const item = document.createElement('div');
            item.className = 'lns-page-nav-item';
            item.dataset.pageKey = page.dataset.pageKey;

            const handle = document.createElement('span');
            handle.className = 'lns-page-nav-handle';
            handle.dataset.pageDragHandle = '';
            handle.dataset.pageKey = page.dataset.pageKey;
            handle.setAttribute('aria-hidden', 'true');
            handle.innerHTML = '<i class="fas fa-grip-vertical"></i>';

            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.action = 'go-to-page';
            button.dataset.pageKey = page.dataset.pageKey;

            const number = document.createElement('span');
            number.className = 'lns-page-nav-number';
            number.textContent = String(index + 1).padStart(2, '0');

            const title = document.createElement('span');
            title.className = 'lns-page-nav-title';
            title.textContent = pages[index].title || `Page ${index + 1}`;

            button.append(number, title);
            item.append(handle, button);
            navigatorList.appendChild(item);
        });
    };

    const refresh = () => {
        const pages = serialize();
        const tocEnabled = readBooleanChoice(form);
        const title = form.querySelector('[data-document-title]')?.value || '';
        const tocPage = editor.querySelector('[data-toc-page]');
        const totalPages = pages.length + 2 + (tocEnabled ? 1 : 0);

        editor.querySelectorAll('[data-document-title-echo]').forEach((element) => {
            element.textContent = title;
        });

        tocPage.hidden = !tocEnabled;
        const tocList = tocPage.querySelector('[data-toc-list]');
        tocList.replaceChildren();

        pages.forEach((page, index) => {
            const row = document.createElement('div');
            row.className = 'lns-toc-row';

            const number = document.createElement('span');
            number.className = 'lns-toc-index';
            number.textContent = String(index + 1).padStart(2, '0');

            const titleElement = document.createElement('span');
            titleElement.className = 'lns-toc-title';
            titleElement.textContent = page.title || `Page ${index + 1}`;

            const leader = document.createElement('span');
            leader.className = 'lns-toc-leader';

            const pageNumber = document.createElement('span');
            pageNumber.className = 'lns-toc-page';
            pageNumber.textContent = String(index + 2 + (tocEnabled ? 1 : 0));

            row.append(number, titleElement, leader, pageNumber);
            tocList.appendChild(row);
        });

        if (tocEnabled) {
            tocPage.querySelector('[data-page-number]').textContent = `Page 2 / ${totalPages}`;
        }

        pagesContainer.querySelectorAll(':scope > [data-page]').forEach((page, index) => {
            const physicalPage = index + 2 + (tocEnabled ? 1 : 0);
            page.querySelector('[data-page-number]').textContent = `Page ${physicalPage} / ${totalPages}`;
            const moveUpButton = page.querySelector('[data-action="move-page-up"]');
            const moveDownButton = page.querySelector('[data-action="move-page-down"]');
            moveUpButton.disabled = index === 0;
            moveDownButton.disabled = index === pages.length - 1;
        });

        hiddenContent.value = JSON.stringify(pages);
        refreshInsertionControls();
        refreshNavigator(pages);
    };

    const showOrderFeedback = (pageKey) => {
        const pages = Array.from(pagesContainer.querySelectorAll(':scope > [data-page]'));
        const page = findPageByKey(pageKey);
        const position = pages.indexOf(page) + 1;

        if (!page || position < 1) {
            return;
        }

        if (orderFeedback && orderFeedbackText) {
            clearTimeout(orderFeedbackTimer);
            orderFeedbackText.textContent = `Page déplacée en position ${position} sur ${pages.length}`;
            orderFeedback.hidden = false;
            orderFeedbackTimer = setTimeout(() => {
                orderFeedback.hidden = true;
            }, 2200);
        }

        page.classList.remove('is-reordered');
        const navigatorItem = Array.from(navigatorList?.querySelectorAll('[data-page-key]') || [])
            .find((item) => item.dataset.pageKey === pageKey);
        navigatorItem?.classList.remove('is-reordered');

        requestAnimationFrame(() => {
            page.classList.add('is-reordered');
            navigatorItem?.classList.add('is-reordered');
            setTimeout(() => {
                page.classList.remove('is-reordered');
                navigatorItem?.classList.remove('is-reordered');
            }, 1300);
        });
    };

    const buildAutosavePayload = () => ({
        title: titleField?.value || '',
        description: descriptionField?.value || '',
        autoGenerateToc: readBooleanChoice(form),
        contentJson: hiddenContent.value,
        revision,
    });

    const persistLocalSnapshot = () => {
        try {
            localStorage.setItem(localStorageKey(), JSON.stringify({
                dirty: true,
                savedAt: new Date().toISOString(),
                payload: buildAutosavePayload(),
            }));

            return true;
        } catch (error) {
            return false;
        }
    };

    const scheduleAutosave = (delay = 900) => {
        if (autosavePaused || manualSubmitting) {
            return;
        }

        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => autosaveNow(), delay);
    };

    const markDirty = (immediate = false) => {
        dirty = true;
        clearTimeout(localBackupTimer);

        if (immediate) {
            persistLocalSnapshot();
        } else {
            localBackupTimer = setTimeout(persistLocalSnapshot, 250);
        }

        updateAutosaveStatus('dirty', 'Modifications en cours…');
        scheduleAutosave(immediate ? 0 : 900);
    };

    const applyServerIdentity = (payload) => {
        if (!documentId && payload.documentId) {
            const previousStorageKey = localStorageKey();
            documentId = String(payload.documentId);
            editor.dataset.documentId = documentId;
            autosaveUrl = payload.autosaveUrl;
            autosaveMethod = 'PATCH';
            form.action = payload.editUrl;

            const previewLink = form.closest('.page-content')?.querySelector('[data-preview-link]')
                || document.querySelector('[data-preview-link]');
            if (previewLink && payload.showUrl) {
                previewLink.href = payload.showUrl;
                previewLink.hidden = false;
            }

            history.replaceState(history.state, '', payload.editUrl);
            localStorage.removeItem(previousStorageKey);
        }

        revision = Number(payload.revision || revision);
        editor.dataset.revision = String(revision);
        revisionField.value = String(revision);
    };

    const performAutosave = async () => {
        if (autosavePaused || !dirty) {
            return !autosavePaused;
        }

        clearTimeout(localBackupTimer);
        const payload = buildAutosavePayload();
        dirty = false;
        updateAutosaveStatus('saving', 'Enregistrement…');

        try {
            const response = await fetch(autosaveUrl, {
                method: autosaveMethod,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': editor.dataset.autosaveToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
            });
            const result = await response.json().catch(() => ({}));

            if (response.status === 409) {
                autosavePaused = true;
                dirty = true;
                persistLocalSnapshot();
                updateAutosaveStatus('conflict', result.message || 'Conflit détecté. Rechargez la page.', false);
                return false;
            }

            if (!response.ok) {
                dirty = true;
                persistLocalSnapshot();
                updateAutosaveStatus('error', result.message || 'Le brouillon n’a pas pu être enregistré.', true);
                return false;
            }

            applyServerIdentity(result);

            if (dirty) {
                persistLocalSnapshot();
                scheduleAutosave(250);
            } else {
                localStorage.removeItem(localStorageKey());
                const time = new Date(result.updatedAt || Date.now()).toLocaleTimeString('fr-FR', {
                    hour: '2-digit',
                    minute: '2-digit',
                });
                updateAutosaveStatus('saved', `Tout est enregistré à ${time}`);
            }

            return true;
        } catch (error) {
            dirty = true;
            const locallyBackedUp = persistLocalSnapshot();
            updateAutosaveStatus(
                'offline',
                locallyBackedUp
                    ? 'Hors ligne — copie conservée sur cet appareil'
                    : 'Hors ligne — gardez cette page ouverte pour ne rien perdre',
                true,
            );
            return false;
        }
    };

    const autosaveNow = () => {
        clearTimeout(autosaveTimer);

        if (activeSave) {
            return activeSave.then((success) => success && dirty ? autosaveNow() : success);
        }

        activeSave = performAutosave().finally(() => {
            activeSave = null;
        });

        return activeSave;
    };

    const flushPendingAutosave = async () => {
        while (activeSave || dirty) {
            const success = await autosaveNow();

            if (!success) {
                return false;
            }
        }

        return true;
    };

    let initialContent;
    const localSnapshot = readLocalSnapshot();

    try {
        initialContent = JSON.parse(localSnapshot?.payload.contentJson || editor.dataset.initialContent || '[]');
    } catch (error) {
        initialContent = [];
    }

    if (localSnapshot) {
        titleField.value = localSnapshot.payload.title || '';
        descriptionField.value = localSnapshot.payload.description || '';
        form.querySelectorAll('input[name$="[autoGenerateToc]"]').forEach((choice) => {
            choice.checked = ['1', 'true'].includes(choice.value) === Boolean(localSnapshot.payload.autoGenerateToc);
        });
        revision = Number(localSnapshot.payload.revision || revision);
        revisionField.value = String(revision);
    }

    if (!Array.isArray(initialContent) || initialContent.length === 0) {
        initialContent = [{title: '', description: '', blocks: []}];
    }

    initialContent.forEach((page) => addPage(page));
    refresh();

    if (localSnapshot) {
        dirty = true;
        updateAutosaveStatus('dirty', 'Brouillon local restauré — synchronisation…');
        scheduleAutosave(250);
    }

    form.addEventListener('click', (event) => {
        const actionElement = event.target.closest('[data-action]');

        if (!actionElement) {
            return;
        }

        const action = actionElement.dataset.action;
        const page = actionElement.closest('[data-page]');
        const block = actionElement.closest('[data-block]');

        if (action === 'retry-autosave') {
            autosavePaused = false;
            scheduleAutosave(0);
        } else if (action === 'go-to-page') {
            findPageByKey(actionElement.dataset.pageKey)?.scrollIntoView({behavior: 'smooth', block: 'start'});
        } else if (action === 'add-page') {
            const newPage = addPage();
            refresh();
            markDirty(true);
            newPage?.scrollIntoView({behavior: 'smooth', block: 'start'});
            newPage?.querySelector('[data-field="page-title"]')?.focus();
        } else if (action === 'insert-page-before') {
            const beforePage = actionElement.closest('[data-page-insert]')?.nextElementSibling;
            const newPage = addPage(undefined, beforePage?.matches('[data-page]') ? beforePage : null);
            refresh();
            markDirty(true);
            newPage?.scrollIntoView({behavior: 'smooth', block: 'start'});
            newPage?.querySelector('[data-field="page-title"]')?.focus();
        } else if (action === 'move-page-up' || action === 'move-page-down') {
            const pages = Array.from(pagesContainer.querySelectorAll(':scope > [data-page]'));
            const index = pages.indexOf(page);
            const targetIndex = action === 'move-page-up' ? index - 1 : index + 1;

            if (targetIndex >= 0 && targetIndex < pages.length) {
                pagesContainer.querySelectorAll(':scope > [data-page-insert]').forEach((control) => control.remove());

                if (action === 'move-page-up') {
                    pagesContainer.insertBefore(page, pages[targetIndex]);
                } else {
                    pagesContainer.insertBefore(pages[targetIndex], page);
                }

                refresh();
                markDirty(true);
                page.scrollIntoView({behavior: 'smooth', block: 'start'});
                showOrderFeedback(page.dataset.pageKey);
            }
        } else if (action === 'duplicate-page') {
            const pages = Array.from(pagesContainer.querySelectorAll(':scope > [data-page]'));
            const nextPage = pages[pages.indexOf(page) + 1] || null;
            const newPage = addPage(serializePage(page), nextPage);
            refresh();
            markDirty(true);
            newPage?.scrollIntoView({behavior: 'smooth', block: 'start'});
        } else if (action === 'remove-page') {
            if (pagesContainer.querySelectorAll(':scope > [data-page]').length <= 1) {
                window.alert('Le document doit conserver au moins une page.');
                return;
            }

            if (window.confirm('Supprimer cette page et tous ses blocs ?')) {
                page.remove();
                refresh();
                markDirty(true);
            }
        } else if (action === 'add-text-block') {
            const newBlock = addBlock(page, {type: 'text', title: '', description: ''});
            refresh();
            markDirty(true);
            newBlock?.querySelector('[data-field="block-title"]')?.focus();
        } else if (action === 'add-table-block') {
            const newBlock = addBlock(page, {
                type: 'table',
                title: '',
                headers: ['Élément', 'Détail', 'Statut'],
                rows: [['', '', '']],
            });
            refresh();
            markDirty(true);
            newBlock?.querySelector('[data-field="block-title"]')?.focus();
        } else if (action === 'add-image-block') {
            const newBlock = addBlock(page, {
                type: 'image',
                title: '',
                data: '',
                caption: '',
            });
            refresh();
            markDirty(true);
            newBlock?.querySelector('[data-field="block-title"]')?.focus();
        } else if (action === 'choose-image') {
            block.querySelector('[data-image-file]')?.click();
        } else if (action === 'remove-image') {
            setImageData(block, '');
            refresh();
            markDirty(true);
        } else if (action === 'remove-block' && window.confirm('Supprimer ce bloc ?')) {
            block.remove();
            refresh();
            markDirty(true);
        } else if (action === 'add-table-row') {
            addTableRow(block);
            refresh();
            markDirty(true);
        } else if (action === 'remove-table-row') {
            actionElement.closest('[data-table-row]')?.remove();
            refresh();
            markDirty(true);
        }
    });

    const importImage = async (block, file) => {
        if (!block || block.dataset.loading === 'true') {
            return;
        }

        block.dataset.loading = 'true';

        try {
            const dataUrl = await compressImage(file);
            setImageData(block, dataUrl);
            refresh();
            markDirty(true);
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Impossible de traiter cette image.');
        } finally {
            block.dataset.loading = 'false';
        }
    };

    editor.addEventListener('change', async (event) => {
        const fileInput = event.target.closest('[data-image-file]');

        if (!fileInput || !fileInput.files?.[0]) {
            return;
        }

        try {
            await importImage(fileInput.closest('[data-block]'), fileInput.files[0]);
        } finally {
            fileInput.value = '';
        }
    });

    editor.addEventListener('dragover', (event) => {
        const imageDrop = event.target.closest('[data-image-drop]');
        const isFileDrag = Array.from(event.dataTransfer?.types || []).includes('Files');

        if (!imageDrop || !isFileDrag) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        imageDrop.classList.add('is-drag-over');
    });

    editor.addEventListener('dragleave', (event) => {
        const imageDrop = event.target.closest('[data-image-drop]');

        if (!imageDrop || imageDrop.contains(event.relatedTarget)) {
            return;
        }

        imageDrop.classList.remove('is-drag-over');
    });

    editor.addEventListener('drop', async (event) => {
        const imageDrop = event.target.closest('[data-image-drop]');
        const file = event.dataTransfer?.files?.[0];

        if (!imageDrop || !file) {
            return;
        }

        event.preventDefault();
        imageDrop.classList.remove('is-drag-over');
        await importImage(imageDrop.closest('[data-block]'), file);
    });

    let dragInitialOrder = '';

    form.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-page-drag-handle]');

        if (!handle || !event.isPrimary) {
            return;
        }

        const navigatorItem = handle.closest('[data-page-nav-item]');
        draggedPage = findPageByKey(handle.dataset.pageKey || navigatorItem?.dataset.pageKey);

        if (!draggedPage || !navigatorItem) {
            return;
        }

        event.preventDefault();
        dragInitialOrder = Array.from(pagesContainer.querySelectorAll(':scope > [data-page]'))
            .map((page) => page.dataset.pageKey)
            .join(',');
        pagesContainer.querySelectorAll(':scope > [data-page-insert]').forEach((control) => control.remove());
        draggedPage.classList.add('is-dragging');
        draggedNavigatorItem = navigatorItem;
        draggedNavigatorItem.classList.add('is-dragging');
        pointerDragHandle = handle;
        pointerDragHandle.classList.add('is-dragging');
        pointerDragHandle.setPointerCapture(event.pointerId);
        document.body.classList.add('lns-page-reordering');
    });

    form.addEventListener('pointermove', (event) => {
        if (!draggedPage) {
            return;
        }

        event.preventDefault();
        const pointedElement = document.elementFromPoint(event.clientX, event.clientY);
        const navigatorItem = pointedElement?.closest('[data-page-nav-item]');

        if (draggedNavigatorItem && navigatorList) {
            const horizontalNavigator = getComputedStyle(navigatorList).flexDirection === 'row';
            const navigatorRectangle = navigatorList.getBoundingClientRect();

            if (horizontalNavigator) {
                if (event.clientX < navigatorRectangle.left + 35) {
                    navigatorList.scrollLeft -= 18;
                } else if (event.clientX > navigatorRectangle.right - 35) {
                    navigatorList.scrollLeft += 18;
                }
            } else {
                const navigatorPanel = navigatorList.closest('[data-page-navigator]');
                if (event.clientY < navigatorRectangle.top + 35) {
                    navigatorPanel.scrollTop -= 18;
                } else if (event.clientY > navigatorRectangle.bottom - 35) {
                    navigatorPanel.scrollTop += 18;
                }
            }
        }

        if (!navigatorItem || navigatorItem === draggedNavigatorItem) {
            return;
        }

        const rectangle = navigatorItem.getBoundingClientRect();
        const horizontalNavigator = getComputedStyle(navigatorList).flexDirection === 'row';
        const placeAfter = horizontalNavigator
            ? event.clientX > rectangle.left + (rectangle.width / 2)
            : event.clientY > rectangle.top + (rectangle.height / 2);

        pointerDragTarget?.classList.remove('is-drop-target');
        pointerDragTarget = navigatorItem;
        pointerDragTarget.classList.add('is-drop-target');

        if (placeAfter) {
            const nextItem = navigatorItem.nextElementSibling;
            if (nextItem !== draggedNavigatorItem) {
                navigatorList.insertBefore(draggedNavigatorItem, nextItem);
            }
        } else {
            navigatorList.insertBefore(draggedNavigatorItem, navigatorItem);
        }
    });

    const finishPageDrag = () => {
        if (!draggedPage) {
            return;
        }

        pointerDragTarget?.classList.remove('is-drop-target');
        pointerDragTarget = null;
        pointerDragHandle?.classList.remove('is-dragging');
        draggedNavigatorItem?.classList.remove('is-dragging');
        pointerDragHandle = null;
        document.body.classList.remove('lns-page-reordering');
        draggedPage.classList.remove('is-dragging');

        const orderedPageKeys = Array.from(navigatorList.querySelectorAll(':scope > [data-page-nav-item]'))
            .map((item) => item.dataset.pageKey);
        orderedPageKeys.forEach((pageKey) => {
            const page = findPageByKey(pageKey);
            if (page) {
                pagesContainer.appendChild(page);
            }
        });

        const newOrder = Array.from(pagesContainer.querySelectorAll(':scope > [data-page]'))
            .map((page) => page.dataset.pageKey)
            .join(',');
        const movedPageKey = draggedPage.dataset.pageKey;
        draggedPage = null;
        draggedNavigatorItem = null;
        refresh();

        if (newOrder !== dragInitialOrder) {
            markDirty(true);
            showOrderFeedback(movedPageKey);
        }
    };

    const endPointerPageDrag = (event) => {
        if (!draggedPage || !pointerDragHandle) {
            return;
        }

        if (pointerDragHandle.hasPointerCapture(event.pointerId)) {
            pointerDragHandle.releasePointerCapture(event.pointerId);
        }

        finishPageDrag();
    };

    form.addEventListener('pointerup', endPointerPageDrag);
    form.addEventListener('pointercancel', endPointerPageDrag);

    form.addEventListener('contextmenu', (event) => {
        if (event.target.closest('[data-page-drag-handle]')) {
            event.preventDefault();
        }
    });

    form.addEventListener('input', (event) => {
        if (!event.target.matches('input, textarea')) {
            return;
        }

        refresh();
        markDirty();
    });

    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-image-file]')) {
            return;
        }

        refresh();
        markDirty(true);
    });

    form.addEventListener('focusout', (event) => {
        if (event.target.matches('input:not([type="hidden"]), textarea')) {
            refresh();
            markDirty(true);
        }
    });

    form.addEventListener('submit', async (event) => {
        refresh();

        if (manualSubmitting || (!dirty && !activeSave)) {
            return;
        }

        event.preventDefault();
        const submitter = event.submitter;
        const ready = await flushPendingAutosave();

        if (ready) {
            manualSubmitting = true;
            localStorage.removeItem(localStorageKey());

            if (submitter) {
                form.requestSubmit(submitter);
            } else {
                form.requestSubmit();
            }
        }
    });

    window.addEventListener('online', () => {
        if (dirty && !autosavePaused) {
            scheduleAutosave(0);
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (!dirty && !activeSave) {
            return;
        }

        persistLocalSnapshot();
        event.preventDefault();
        event.returnValue = '';
    });
};

const initializeLnsDocuments = () => {
    initializeSaveMenu();
    document.querySelectorAll('[data-lns-document-editor]').forEach(initializeEditor);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLnsDocuments);
} else {
    initializeLnsDocuments();
}

document.addEventListener('turbo:load', initializeLnsDocuments);
