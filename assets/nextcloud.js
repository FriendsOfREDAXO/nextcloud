let currentPath = '/';
let selectedFiles = new Set();

function loadFiles(path = '/') {
    currentPath = path;
    selectedFiles.clear();
    updateToolbar();
    
    const fileList = document.getElementById('fileList');
    fileList.innerHTML = '<tr><td colspan="6" class="text-center"><i class="rex-icon fa-spinner fa-spin"></i></td></tr>';
    
    const params = {
        page: 'nextcloud/main',
        'rex-api-call': 'nextcloud',
        action: 'list',
        path: path
    };
    
    const url = 'index.php?' + $.param(params);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateBreadcrumb(path);
                renderFiles(data.data);
            } else {
                throw new Error(data.error || 'Unknown error occurred');
            }
        })
        .catch(error => {
            const errorMsg = document.createElement('tr');
            errorMsg.innerHTML = `<td colspan="6" class="alert alert-danger">${error.message}</td>`;
            fileList.innerHTML = '';
            fileList.appendChild(errorMsg);
            alert('Fehler: ' + error.message);
        });
}

function renderFiles(files) {
    const fileList = document.getElementById('fileList');
    fileList.innerHTML = '';
    
    if (currentPath !== '/') {
        const parentPath = currentPath.split('/').slice(0, -1).join('/') || '/';
        fileList.innerHTML += `
            <tr class="folder-row" style="cursor: pointer;" data-path="${parentPath}">
                <td style="width: 30px;"></td>
                <td><i class="rex-icon fa-level-up"></i></td>
                <td colspan="3">..</td>
                <td></td>
            </tr>`;
    }
    
    files.sort((a, b) => {
        if (a.type === 'folder' && b.type !== 'folder') return -1;
        if (a.type !== 'folder' && b.type === 'folder') return 1;
        return decodeURIComponent(a.name).localeCompare(decodeURIComponent(b.name));
    });
    
    files.forEach(file => {
        const icon = getFileIcon(file.type);
        const rowClass = file.type === 'folder' ? 'folder-row' : '';
        const decodedName = decodeURIComponent(file.name);
        
        // Checkbox nur für Dateien, nicht für Ordner
        const checkbox = file.type !== 'folder' 
            ? `<input type="checkbox" class="file-select" data-path="${file.path}" style="transform: scale(1.2);"${selectedFiles.has(file.path) ? ' checked' : ''}>`
            : '';
        
        // Name mit oder ohne Link für Bildvorschau/PDF-Vorschau und Word-Break
        const nameContent = file.type === 'image' 
            ? `<a href="#" onclick="event.stopPropagation(); previewImage('${file.path}', '${decodedName}'); return false;" style="word-break: break-word;">${decodedName}</a>`
            : file.type === 'pdf'
            ? `<a href="#" onclick="event.stopPropagation(); previewPdf('${file.path}', '${decodedName}'); return false;" style="word-break: break-word;">${decodedName}</a>`
            : `<span style="word-break: break-word;">${decodedName}</span>`;
            
        fileList.innerHTML += `
            <tr class="${rowClass}" ${file.type === 'folder' ? 'data-path="' + file.path + '"' : ''} style="${file.type === 'folder' ? 'cursor: pointer;' : ''}">
                <td style="width: 30px; text-align: center; vertical-align: middle;">
                    ${checkbox}
                </td>
                <td style="width: 50px; text-align: center; vertical-align: middle;">
                    ${file.type === 'image' 
                        ? `<a href="#" onclick="event.stopPropagation(); previewImage('${file.path}', '${decodedName}'); return false;"><i class="rex-icon ${icon}"></i></a>` 
                        : file.type === 'pdf'
                        ? `<a href="#" onclick="event.stopPropagation(); previewPdf('${file.path}', '${decodedName}'); return false;"><i class="rex-icon ${icon}"></i></a>`
                        : `<i class="rex-icon ${icon}"></i>`}
                </td>
                <td style="max-width: 500px; vertical-align: middle;">${nameContent}</td>
                <td style="width: 100px; vertical-align: middle;">${file.size || ''}</td>
                <td style="width: 150px; vertical-align: middle;">${file.modified || ''}</td>
                <td style="width: 60px; vertical-align: middle;">
                    ${file.type !== 'folder' ? `
                        <div class="btn-group btn-group-xs">
                            <button class="btn btn-primary btn-xs" title="Importieren" onclick="event.stopPropagation(); importFile('${file.path}')">
                                <i class="rex-icon fa-upload"></i>
                            </button>
                            ${(typeof rex !== 'undefined' && rex.nextcloudSharingEnabled) ? `
                            <button class="btn btn-default btn-xs" title="Share-Link erstellen" onclick="event.stopPropagation(); openShareModal('${file.path}', '${decodedName}')">
                                <i class="rex-icon fa-share-alt"></i>
                            </button>` : ''}
                        </div>
                    ` : `
                        <button class="btn btn-default btn-xs">
                            <i class="rex-icon fa-chevron-right"></i>
                        </button>
                    `}
                </td>
            </tr>`;
    });

    // Event-Handler für Ordner-Klicks bleiben gleich
    $('.folder-row').on('click', function() {
        const path = $(this).data('path');
        if (path) {
            loadFiles(path);
        }
    });

    // Event-Handler für Checkboxen bleiben gleich
    $('.file-select').on('change', function(e) {
        e.stopPropagation();
        const path = $(this).data('path');
        if (this.checked) {
            selectedFiles.add(path);
        } else {
            selectedFiles.delete(path);
        }
        updateToolbar();
    });
}

function updateToolbar() {
    // Aktualisiere den Import-Button im Header basierend auf der Auswahl
    const headerButtons = $('.panel-heading .btn-group');
    const importButton = headerButtons.find('#btnImportSelected');
    
    if (selectedFiles.size > 0) {
        if (!importButton.length) {
            headerButtons.prepend(`
                <button class="btn btn-primary btn-xs" id="btnImportSelected" style="margin-right: 10px;">
                    <i class="rex-icon fa-upload"></i> ${selectedFiles.size} importieren
                </button>
            `);
            $('#btnImportSelected').on('click', importSelectedFiles);
        } else {
            importButton.html(`<i class="rex-icon fa-upload"></i> ${selectedFiles.size} importieren`);
        }
    } else {
        importButton.remove();
    }
}

async function importSelectedFiles() {
    const categoryId = $('#rex-mediapool-category').val();
    let imported = 0;
    let failed = [];

    // Progress Modal
    const modal = $(`
        <div class="modal fade" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title">Importiere Dateien...</h4>
                    </div>
                    <div class="modal-body">
                        <div class="progress">
                            <div class="progress-bar" role="progressbar" style="width: 0%;">0%</div>
                        </div>
                        <div id="import-status" class="text-center" style="margin-top: 10px;"></div>
                    </div>
                </div>
            </div>
        </div>
    `);
    
    modal.modal({backdrop: 'static', keyboard: false});

    const files = Array.from(selectedFiles);
    const total = files.length;
    let processed = 0;

    for (const path of files) {
        const fileName = decodeURIComponent(path.split('/').pop());
        
        try {
            modal.find('#import-status').text(
                `Importiere "${fileName}" (${processed + 1} von ${total})`
            );

            const params = {
                page: 'nextcloud/main',
                'rex-api-call': 'nextcloud',
                action: 'import',
                path: path,
                category_id: categoryId
            };
            
            const url = 'index.php?' + $.param(params);
            
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                imported++;
            } else {
                failed.push({
                    name: fileName,
                    error: data.error || 'Unbekannter Fehler'
                });
            }

            // Kleine Pause zwischen den Importen
            await new Promise(resolve => setTimeout(resolve, 500));

        } catch (error) {
            failed.push({
                name: fileName,
                error: error.message
            });
        }

        processed++;
        const progress = Math.round((processed / total) * 100);
        modal.find('.progress-bar')
            .css('width', progress + '%')
            .text(progress + '%');
    }

    // Fertig
    setTimeout(() => {
        modal.modal('hide');
        
        // Detaillierte Zusammenfassung
        if (failed.length > 0) {
            let message = `Import abgeschlossen:\n\n`;
            message += `${imported} Dateien erfolgreich importiert\n`;
            message += `${failed.length} Fehler:\n\n`;
            failed.forEach(({name, error}) => {
                message += `- ${name}: ${error}\n`;
            });
            alert(message);
        } else {
            alert(`Alle ${imported} Dateien wurden erfolgreich importiert.`);
        }
        
        loadFiles(currentPath);
    }, 500);
}

function getFileIcon(type) {
    switch(type) {
        case 'folder': return 'fa-folder-o';
        case 'image': return 'fa-file-image-o';
        case 'pdf': return 'fa-file-pdf-o';
        case 'document': return 'fa-file-text-o';
        case 'archive': return 'fa-file-archive-o';
        case 'audio': return 'fa-file-audio-o';
        case 'video': return 'fa-file-video-o';
        default: return 'fa-file-o';
    }
}

function previewImage(path, name) {
    const params = {
        page: 'nextcloud/main',
        'rex-api-call': 'nextcloud',
        action: 'preview',
        path: path
    };
    
    const previewUrl = 'index.php?' + $.param(params);
    
    const modal = $(`
        <div class="modal fade" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title">${name}</h4>
                    </div>
                    <div class="modal-body text-center">
                        <img src="${previewUrl}" style="max-width: 100%; max-height: 70vh;" alt="${name}">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Schließen</button>
                        <button type="button" class="btn btn-primary" onclick="importFile('${path}')">
                            <i class="rex-icon fa-upload"></i> Importieren
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `);

    modal.modal('show');
    modal.on('hidden.bs.modal', function() {
        modal.remove();
    });
}

function previewPdf(path, name) {
    const params = {
        page: 'nextcloud/main',
        'rex-api-call': 'nextcloud',
        action: 'pdf_preview',
        path: path
    };
    
    const previewUrl = 'index.php?' + $.param(params);
    
    // Open PDF in new window
    window.open(previewUrl, '_blank');
}

function importFile(path) {
    const categoryId = $('#rex-mediapool-category').val();
    const fileName = decodeURIComponent(path.split('/').pop());
    
    const params = {
        page: 'nextcloud/main',
        'rex-api-call': 'nextcloud',
        action: 'import',
        path: path,
        category_id: categoryId
    };
    
    const url = 'index.php?' + $.param(params);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let msg = 'Datei erfolgreich importiert';
                if (data.data && data.data.tags_applied && data.data.tags_applied.length > 0) {
                    msg += '\nTags übernommen: ' + data.data.tags_applied.join(', ');
                }
                alert(msg);
                loadFiles(currentPath);
                // Wenn Modal offen ist, schließen
                $('.modal').modal('hide');
            } else {
                throw new Error(data.error || 'Import failed');
            }
        })
        .catch(error => {
            alert(`Fehler beim Import von "${fileName}": ${error.message}`);
        });
}

// -------------------------------------------------------------------------
// Share-Link Modal
// -------------------------------------------------------------------------

function openShareModal(path, name) {
    const modal = $(`
        <div class="modal fade" tabindex="-1" role="dialog" id="nextcloud-share-modal">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title">
                            <i class="rex-icon fa-share-alt"></i> Share-Link erstellen
                        </h4>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted" style="word-break:break-all;">${name}</p>
                        <p><small class="text-muted">Pfad: <code style="font-size:11px;">${decodeURIComponent(path)}</code></small></p>

                        <div class="form-group">
                            <label for="share-expiry">Ablaufdatum (optional)</label>
                            <input type="date" id="share-expiry" class="form-control"
                                min="${new Date().toISOString().split('T')[0]}">
                        </div>

                        <div id="share-result" style="display:none;">
                            <div class="form-group">
                                <label>Share-Link</label>
                                <div class="input-group">
                                    <input type="text" id="share-url" class="form-control" readonly>
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" id="btn-copy-share" type="button"
                                            title="In Zwischenablage kopieren">
                                            <i class="rex-icon fa-clone"></i>
                                        </button>
                                    </span>
                                </div>
                            </div>
                            <div class="alert alert-info" style="font-size:12px; margin-bottom:0;">
                                <strong>Tipp:</strong> Im Modul-Output verwendbar als:<br>
                                <code>REX_NEXTCLOUD_SHARE[path="${path}"]</code>
                            </div>
                        </div>

                        <div id="share-error" class="alert alert-danger" style="display:none;"></div>
                        <div id="share-loading" style="display:none;" class="text-center">
                            <i class="rex-icon fa-spinner fa-spin fa-2x"></i>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Schließen</button>
                        <button type="button" class="btn btn-primary" id="btn-create-share">
                            <i class="rex-icon fa-share-alt"></i> Link erstellen
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `);

    $('body').append(modal);
    modal.modal('show');
    modal.on('hidden.bs.modal', function () { modal.remove(); });

    modal.find('#btn-create-share').on('click', function () {
        const expiry = modal.find('#share-expiry').val();

        modal.find('#share-result, #share-error').hide();
        modal.find('#share-loading').show();
        modal.find('#btn-create-share').prop('disabled', true);

        const params = {
            page: 'nextcloud/main',
            'rex-api-call': 'nextcloud',
            action: 'share',
            path: path,
        };
        if (expiry) params.expiry = expiry;

        fetch('index.php?' + $.param(params))
            .then(r => r.json())
            .then(data => {
                modal.find('#share-loading').hide();
                modal.find('#btn-create-share').prop('disabled', false);

                if (data.success && data.data && data.data.url) {
                    modal.find('#share-url').val(data.data.url);
                    modal.find('#share-result').show();
                } else {
                    const errMsg = data.error || 'Unbekannter Fehler';
                    let hint = '';
                    if (errMsg.toLowerCase().includes('freigabetyp') || errMsg.toLowerCase().includes('share type')) {
                        hint = '<br><small class="text-muted">Hinweis: Bitte prüfen ob in den Nextcloud-Admineinstellungen '
                            + '(Einstellungen → Freigabe) die Option „Freigabe über Links erlauben" aktiviert ist.</small>';
                    }
                    if (errMsg.toLowerCase().includes('leitet weiter') || errMsg.toLowerCase().includes('redirect')) {
                        hint = '<br><small class="text-muted">Hinweis: Die Nextcloud-URL leitet weiter. '
                            + 'Bitte sicherstellen, dass in den AddOn-Einstellungen die korrekte HTTPS-URL '
                            + 'ohne abschließenden Slash eingetragen ist.</small>';
                    }
                    modal.find('#share-error')
                        .html('<strong>Fehler:</strong> ' + $('<span>').text(errMsg).html() + hint)
                        .show();
                }
            })
            .catch(err => {
                modal.find('#share-loading').hide();
                modal.find('#btn-create-share').prop('disabled', false);
                modal.find('#share-error').text('Fehler: ' + err.message).show();
            });
    });

    // Kopieren in Zwischenablage
    modal.find('#btn-copy-share').on('click', function () {
        const input = modal.find('#share-url')[0];
        input.select();
        try {
            navigator.clipboard.writeText(input.value).then(() => {
                const btn = $(this);
                btn.html('<i class="rex-icon fa-check"></i>');
                setTimeout(() => btn.html('<i class="rex-icon fa-clone"></i>'), 2000);
            });
        } catch (e) {
            document.execCommand('copy');
        }
    });
}

function updateBreadcrumb(path) {
    const parts = path.split('/').filter(Boolean);
    let currentBuildPath = '';
    let breadcrumb = '<i class="rex-icon fa-home"></i> ';
    
    if (parts.length > 0) {
        breadcrumb += `<a href="#" onclick="loadFiles('/'); return false;">/</a> `;
        
        parts.forEach((part, index) => {
            currentBuildPath += '/' + part;
            const isLast = index === parts.length - 1;
            
            breadcrumb += isLast 
                ? `/ ${decodeURIComponent(part)} `
                : `/ <a href="#" onclick="loadFiles('${currentBuildPath}'); return false;">${decodeURIComponent(part)}</a> `;
        });
    }
    
    document.getElementById('pathBreadcrumb').innerHTML = breadcrumb;
}

$(document).on('rex:ready', function() {
    $('#btnRefresh').on('click', function() {
        loadFiles(currentPath);
    });
    
    $('#btnHome').on('click', function() {
        loadFiles('/');
    });
    
    loadFiles('/');
});