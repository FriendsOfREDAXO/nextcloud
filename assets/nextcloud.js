let currentPath = '/';

function loadFiles(path = '/') {
    currentPath = path;
    const fileList = document.getElementById('fileList');
    fileList.innerHTML = '<tr><td colspan="5" class="text-center"><i class="rex-icon fa-spinner fa-spin"></i></td></tr>';
    
    const params = {
        page: 'nextcloud/main',
        nextcloud_api: '1',
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
            errorMsg.innerHTML = `<td colspan="5" class="alert alert-danger">${error.message}</td>`;
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
                <td><i class="rex-icon fa-level-up"></i></td>
                <td colspan="3">..</td>
                <td></td>
            </tr>`;
    }
    
    files.sort((a, b) => {
        if (a.type === 'folder' && b.type !== 'folder') return -1;
        if (a.type !== 'folder' && b.type === 'folder') return 1;
        return a.name.localeCompare(b.name);
    });
    
    files.forEach(file => {
        const icon = getFileIcon(file.type);
        const action = file.type === 'folder' 
            ? `<button class="btn btn-default btn-xs"><i class="rex-icon fa-chevron-right"></i></button>`
            : `<button class="btn btn-primary btn-xs" onclick="event.stopPropagation(); importFile('${file.path}')"><i class="rex-icon fa-upload"></i></button>`;
            
        const rowClass = file.type === 'folder' ? 'folder-row' : '';
        
        // Klickbares Icon/Name für Bildvorschau
        const nameContent = file.type === 'image' 
            ? `<a href="#" onclick="event.stopPropagation(); previewImage('${file.path}', '${file.name}'); return false;">${file.name}</a>`
            : file.name;
            
        fileList.innerHTML += `
            <tr class="${rowClass}" ${file.type === 'folder' ? 'data-path="' + file.path + '"' : ''} style="${file.type === 'folder' ? 'cursor: pointer;' : ''}">
                <td style="width: 50px; text-align: center;">
                    ${file.type === 'image' 
                        ? `<a href="#" onclick="event.stopPropagation(); previewImage('${file.path}', '${file.name}'); return false;"><i class="rex-icon ${icon}"></i></a>` 
                        : `<i class="rex-icon ${icon}"></i>`}
                </td>
                <td>${nameContent}</td>
                <td>${file.size || ''}</td>
                <td>${file.modified || ''}</td>
                <td>${action}</td>
            </tr>`;
    });

    // Event-Handler für Ordner-Klicks
    $('.folder-row').on('click', function() {
        const path = $(this).data('path');
        if (path) {
            loadFiles(path);
        }
    });
}

function getFileIcon(type) {
    switch(type) {
        case 'folder': return 'fa-folder-o';
        case 'image': return 'fa-file-image-o';
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
        nextcloud_api: '1',
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

function importFile(path) {
    const categoryId = $('#rex-mediapool-category').val();
    
    const params = {
        page: 'nextcloud/main',
        nextcloud_api: '1',
        action: 'import',
        path: path,
        category_id: categoryId
    };
    
    const url = 'index.php?' + $.param(params);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Datei erfolgreich importiert');
                loadFiles(currentPath);
                // Wenn Modal offen ist, schließen
                $('.modal').modal('hide');
            } else {
                throw new Error(data.error || 'Import failed');
            }
        })
        .catch(error => {
            alert('Fehler beim Import: ' + error.message);
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
                ? `/ ${part} `
                : `/ <a href="#" onclick="loadFiles('${currentBuildPath}'); return false;">${part}</a> `;
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