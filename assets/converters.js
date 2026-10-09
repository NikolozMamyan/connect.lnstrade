(() => {
    const page = document.querySelector('[data-converter]');

    if (!page) {
        return;
    }

    const form = page.querySelector('[data-converter-form]');
    const input = page.querySelector('[data-file-input]');
    const dropzone = page.querySelector('[data-dropzone]');
    const dropTitle = page.querySelector('[data-drop-title]');
    const dropDescription = page.querySelector('[data-drop-description]');
    const selectedFile = page.querySelector('[data-selected-file]');
    const fileName = page.querySelector('[data-file-name]');
    const fileMeta = page.querySelector('[data-file-meta]');
    const removeButton = page.querySelector('[data-remove-file]');
    const sheetSelect = page.querySelector('[data-sheet-select]');
    const separatorSelect = page.querySelector('[data-separator-select]');
    const convertButton = page.querySelector('[data-convert-button]');
    const convertLabel = page.querySelector('[data-convert-label]');
    const error = page.querySelector('[data-converter-error]');
    const success = page.querySelector('[data-converter-success]');
    const resultDescription = page.querySelector('[data-result-description]');
    const downloadLink = page.querySelector('[data-download-link]');
    const preview = page.querySelector('[data-preview]');
    const previewBody = page.querySelector('[data-preview-body]');
    const previewSummary = page.querySelector('[data-preview-summary]');
    let workbook = null;
    let currentFile = null;
    let downloadUrl = null;
    let busy = false;

    const formatSize = (bytes) => bytes < 1024 * 1024
        ? `${Math.max(1, Math.ceil(bytes / 1024))} Ko`
        : `${(bytes / (1024 * 1024)).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Mo`;

    const clearResult = () => {
        success.hidden = true;
        error.hidden = true;
        downloadLink.removeAttribute('href');

        if (downloadUrl) {
            URL.revokeObjectURL(downloadUrl);
            downloadUrl = null;
        }
    };

    const showError = (message) => {
        error.textContent = message;
        error.hidden = false;
    };

    const setBusy = (value) => {
        busy = value;
        input.disabled = value;
        removeButton.disabled = value;
        sheetSelect.disabled = value || !workbook;
        separatorSelect.disabled = value;
        convertButton.disabled = value || !workbook;
        dropzone.setAttribute('aria-disabled', String(value));
        form.setAttribute('aria-busy', String(value));
    };

    const resetFile = () => {
        clearResult();
        workbook = null;
        currentFile = null;
        input.value = '';
        selectedFile.hidden = true;
        preview.hidden = true;
        previewBody.replaceChildren();
        sheetSelect.replaceChildren(new Option('Choisissez un fichier Excel', ''));
        dropTitle.textContent = 'Déposez votre fichier Excel';
        dropDescription.textContent = 'Glissez-déposez votre fichier ici';
        convertLabel.textContent = 'Convertir et télécharger';
        setBusy(false);
    };

    const updatePreview = () => {
        const sheet = workbook?.Sheets[sheetSelect.value];
        const reference = sheet?.['!ref'];
        preview.hidden = !reference;
        previewBody.replaceChildren();

        if (!reference) {
            return;
        }

        const range = window.XLSX.utils.decode_range(reference);
        const rowCount = range.e.r - range.s.r + 1;
        const columnCount = range.e.c - range.s.c + 1;
        previewSummary.textContent = `${rowCount.toLocaleString('fr-FR')} ligne(s) · ${columnCount.toLocaleString('fr-FR')} colonne(s)`;
        range.e.r = Math.min(range.e.r, range.s.r + 4);
        range.e.c = Math.min(range.e.c, range.s.c + 4);

        const rows = window.XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false, defval: '', range });

        rows.forEach((values) => {
            const row = document.createElement('tr');

            values.forEach((value) => {
                const cell = document.createElement('td');
                cell.textContent = String(value);
                cell.title = String(value);
                row.append(cell);
            });

            previewBody.append(row);
        });
    };

    const loadFile = async (file) => {
        if (busy || !file) {
            return;
        }

        resetFile();

        if (!/\.xlsx$/i.test(file.name)) {
            showError('Sélectionnez un fichier Excel au format .xlsx.');
            return;
        }

        if (file.size === 0 || file.size > 20 * 1024 * 1024) {
            showError(file.size === 0 ? 'Ce fichier est vide. Sélectionnez un fichier Excel contenant des données.' : 'Ce fichier dépasse la limite de 20 Mo.');
            return;
        }

        setBusy(true);
        dropTitle.textContent = 'Lecture du fichier…';
        dropDescription.textContent = 'Veuillez patienter.';

        try {
            const data = await file.arrayBuffer();
            const signature = new Uint8Array(data, 0, Math.min(4, data.byteLength));

            if (signature[0] !== 0x50 || signature[1] !== 0x4b || signature[2] !== 0x03 || signature[3] !== 0x04) {
                throw new Error('invalid-xlsx');
            }

            workbook = window.XLSX.read(data, { type: 'array', cellDates: true, cellHTML: false });
            const firstSheet = workbook.SheetNames.find((name) => workbook.Sheets[name]?.['!ref']);

            if (!firstSheet) {
                throw new Error('empty-workbook');
            }

            currentFile = file;
            sheetSelect.replaceChildren(...workbook.SheetNames.map((name) => new Option(name, name)));
            sheetSelect.value = firstSheet;
            fileName.textContent = file.name;
            fileMeta.textContent = `${formatSize(file.size)} · ${workbook.SheetNames.length} feuille(s)`;
            selectedFile.hidden = false;
            dropTitle.textContent = 'Fichier prêt à convertir';
            dropDescription.textContent = 'Déposez un autre fichier pour le remplacer.';
            updatePreview();
        } catch (exception) {
            resetFile();
            showError(exception.message === 'empty-workbook'
                ? 'Ce classeur ne contient aucune donnée à convertir.'
                : 'Impossible de lire ce fichier. Vérifiez qu’il s’agit d’un fichier .xlsx sans protection par mot de passe.');
        } finally {
            setBusy(false);
        }
    };

    input.addEventListener('change', () => loadFile(input.files[0]));
    removeButton.addEventListener('click', resetFile);
    dropzone.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();

            if (!busy) {
                input.click();
            }
        }
    });
    dropzone.addEventListener('dragover', (event) => {
        event.preventDefault();
        if (!busy) {
            dropzone.classList.add('is-dragging');
        }
    });
    dropzone.addEventListener('dragleave', (event) => {
        if (!dropzone.contains(event.relatedTarget)) {
            dropzone.classList.remove('is-dragging');
        }
    });
    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');

        if (busy) {
            return;
        }

        if (event.dataTransfer.files.length !== 1) {
            clearResult();
            showError('Déposez un seul fichier Excel à la fois.');
            return;
        }

        loadFile(event.dataTransfer.files[0]);
    });
    sheetSelect.addEventListener('change', () => {
        clearResult();
        updatePreview();
    });
    separatorSelect.addEventListener('change', clearResult);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy || !workbook || !currentFile) {
            return;
        }

        clearResult();
        setBusy(true);
        convertLabel.textContent = 'Conversion en cours…';

        try {
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const sheet = workbook.Sheets[sheetSelect.value];

            if (!sheet?.['!ref']) {
                showError('Cette feuille est vide. Sélectionnez une autre feuille du classeur.');
                return;
            }

            const csv = window.XLSX.utils.sheet_to_csv(sheet, {
                FS: separatorSelect.value === 'tab' ? '\t' : separatorSelect.value,
                RS: '\r\n',
                blankrows: false,
            });
            const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' });
            const sheetSuffix = workbook.SheetNames.length > 1 ? `-${sheetSelect.value}` : '';
            const name = `${currentFile.name.replace(/\.xlsx$/i, '')}${sheetSuffix}.csv`.replace(/[<>:"/\\|?*\x00-\x1f]/g, '_');
            downloadUrl = URL.createObjectURL(blob);
            downloadLink.href = downloadUrl;
            downloadLink.download = name;
            resultDescription.textContent = `${name} · ${formatSize(blob.size)}`;
            success.hidden = false;
            downloadLink.click();
        } catch (exception) {
            showError('La conversion a échoué. Réessayez ou sélectionnez un autre fichier Excel.');
        } finally {
            setBusy(false);
            convertLabel.textContent = 'Convertir et télécharger';
        }
    });

    if (!window.XLSX) {
        showError('Le convertisseur n’a pas pu se charger. Actualisez la page pour réessayer.');
        input.disabled = true;
        dropzone.setAttribute('aria-disabled', 'true');
    }
})();
