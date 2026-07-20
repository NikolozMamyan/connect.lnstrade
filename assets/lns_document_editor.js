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
    const pageTemplate = editor.querySelector('[data-page-template]');
    const textBlockTemplate = editor.querySelector('[data-text-block-template]');
    const tableBlockTemplate = editor.querySelector('[data-table-block-template]');
    const imageBlockTemplate = editor.querySelector('[data-image-block-template]');
    const tableRowTemplate = editor.querySelector('[data-table-row-template]');
    const maxPages = Number(editor.dataset.maxPages || 50);
    const maxBlocks = Number(editor.dataset.maxBlocks || 30);
    const maxRows = Number(editor.dataset.maxRows || 100);

    const cloneElement = (template) => template.content.firstElementChild.cloneNode(true);

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

    const addPage = (data = {title: '', description: '', blocks: []}) => {
        if (pagesContainer.querySelectorAll(':scope > [data-page]').length >= maxPages) {
            window.alert(`Un document ne peut pas dépasser ${maxPages} pages.`);
            return null;
        }

        const page = cloneElement(pageTemplate);
        page.querySelector('[data-field="page-title"]').value = data.title || '';
        page.querySelector('[data-field="page-description"]').value = data.description || '';
        (Array.isArray(data.blocks) ? data.blocks : []).forEach((block) => addBlock(page, block));
        pagesContainer.appendChild(page);

        return page;
    };

    const serialize = () => Array.from(pagesContainer.querySelectorAll(':scope > [data-page]')).map((page) => ({
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
    }));

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
            pageNumber.textContent = String(index + 3);

            row.append(number, titleElement, leader, pageNumber);
            tocList.appendChild(row);
        });

        if (tocEnabled) {
            tocPage.querySelector('[data-page-number]').textContent = `Page 2 / ${totalPages}`;
        }

        pagesContainer.querySelectorAll(':scope > [data-page]').forEach((page, index) => {
            const physicalPage = index + 2 + (tocEnabled ? 1 : 0);
            page.querySelector('[data-page-number]').textContent = `Page ${physicalPage} / ${totalPages}`;
        });

        hiddenContent.value = JSON.stringify(pages);
    };

    let initialContent;

    try {
        initialContent = JSON.parse(editor.dataset.initialContent || '[]');
    } catch (error) {
        initialContent = [];
    }

    if (!Array.isArray(initialContent) || initialContent.length === 0) {
        initialContent = [{title: '', description: '', blocks: []}];
    }

    initialContent.forEach((page) => addPage(page));
    refresh();

    editor.addEventListener('click', (event) => {
        const actionElement = event.target.closest('[data-action]');

        if (!actionElement) {
            return;
        }

        const action = actionElement.dataset.action;
        const page = actionElement.closest('[data-page]');
        const block = actionElement.closest('[data-block]');

        if (action === 'add-page') {
            const newPage = addPage();
            refresh();
            newPage?.scrollIntoView({behavior: 'smooth', block: 'start'});
            newPage?.querySelector('[data-field="page-title"]')?.focus();
        } else if (action === 'remove-page') {
            if (pagesContainer.querySelectorAll(':scope > [data-page]').length <= 1) {
                window.alert('Le document doit conserver au moins une page.');
                return;
            }

            if (window.confirm('Supprimer cette page et tous ses blocs ?')) {
                page.remove();
                refresh();
            }
        } else if (action === 'add-text-block') {
            const newBlock = addBlock(page, {type: 'text', title: '', description: ''});
            refresh();
            newBlock?.querySelector('[data-field="block-title"]')?.focus();
        } else if (action === 'add-table-block') {
            const newBlock = addBlock(page, {
                type: 'table',
                title: '',
                headers: ['Élément', 'Détail', 'Statut'],
                rows: [['', '', '']],
            });
            refresh();
            newBlock?.querySelector('[data-field="block-title"]')?.focus();
        } else if (action === 'add-image-block') {
            const newBlock = addBlock(page, {
                type: 'image',
                title: '',
                data: '',
                caption: '',
            });
            refresh();
            newBlock?.querySelector('[data-field="block-title"]')?.focus();
        } else if (action === 'choose-image') {
            block.querySelector('[data-image-file]')?.click();
        } else if (action === 'remove-image') {
            setImageData(block, '');
            refresh();
        } else if (action === 'remove-block' && window.confirm('Supprimer ce bloc ?')) {
            block.remove();
            refresh();
        } else if (action === 'add-table-row') {
            addTableRow(block);
            refresh();
        } else if (action === 'remove-table-row') {
            actionElement.closest('[data-table-row]')?.remove();
            refresh();
        }
    });

    editor.addEventListener('change', async (event) => {
        const fileInput = event.target.closest('[data-image-file]');

        if (!fileInput || !fileInput.files?.[0]) {
            return;
        }

        const block = fileInput.closest('[data-block]');
        block.dataset.loading = 'true';

        try {
            const dataUrl = await compressImage(fileInput.files[0]);
            setImageData(block, dataUrl);
            refresh();
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Impossible de traiter cette image.');
        } finally {
            block.dataset.loading = 'false';
            fileInput.value = '';
        }
    });

    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.addEventListener('submit', refresh);
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
