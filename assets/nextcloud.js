/**
 * nextcloud-Addon -- Backend-Browser-Seite (pages/main.php).
 *
 * Seit 2.0.0 Client von cloudconnect (siehe lib/nextcloud.php): jede
 * API-Anfrage haengt die connection_id des aktuell gewaehlten Accounts an
 * (Mehrkonten-Umschalter, siehe initConnections()/switchAccount() unten).
 * Feldform der Datei-/Ordnerlisten hat sich geaendert (cloudconnects
 * NextcloudClient liefert type: 'file'|'folder', rohes filesize (Bytes),
 * filetype (Endung), unformatiertes modified) -- Formatierung (Groesse/
 * Datum) und Icon-Auswahl passieren deshalb hier clientseitig
 * (ncFormatBytes/ncFormatDate/ncFileIcon), aehnliches Prinzip wie
 * MediaPlace's MP3Core.helpers.formatBytes()/fileIcon(), aber eine eigene,
 * kleine Entsprechung ohne Cross-Addon-JS-Abhaengigkeit.
 */

// -------------------------------------------------------------------------
// State
// -------------------------------------------------------------------------
var currentConnectionId = null;
var currentPath = '/';
var selectedFiles = new Set();
var currentFiles = [];
var lastRenderedFiles = [];
var currentSearchTerm = '';
var searchDebounceTimer = null;
var searchRequestCounter = 0;
// 'list' | 'masonry' -- eigener localStorage-Schluessel, getrennt von
// 'nc_connection' (Konto-Wahl), gleiche Persistenz-Konvention.
var currentViewMode = 'list';

// -------------------------------------------------------------------------
// Kleine Helfer
// -------------------------------------------------------------------------
function ncEscHtml(s) {
    return String(null == s ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function ncEscAttr(s) {
    return String(null == s ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;');
}

function ncFormatBytes(bytes) {
    if (null === bytes || undefined === bytes || isNaN(bytes)) {
        return '';
    }
    bytes = Math.max(0, parseInt(bytes, 10) || 0);
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var pow = bytes ? Math.floor(Math.log(bytes) / Math.log(1024)) : 0;
    pow = Math.min(pow, units.length - 1);
    var value = bytes / Math.pow(1024, pow);
    return (Math.round(value * 100) / 100) + ' ' + units[pow];
}

function ncFormatDate(value) {
    if (!value) {
        return '';
    }
    var d = new Date(value);
    if (isNaN(d.getTime())) {
        return '';
    }
    var pad = function (n) { return n < 10 ? '0' + n : n; };
    return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() +
        ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

var NC_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
var NC_PDF_EXT = ['pdf'];
var NC_DOC_EXT = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'rtf'];
var NC_ARCHIVE_EXT = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
var NC_AUDIO_EXT = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'];
var NC_VIDEO_EXT = ['mp4', 'm4v', 'avi', 'mkv', 'mov', 'webm', 'flv', 'wmv', 'ogv'];

function ncFileIcon(file) {
    if ('folder' === file.type) {
        return 'fa-folder-o';
    }
    var ext = (file.filetype || '').toLowerCase();
    if (NC_IMAGE_EXT.indexOf(ext) !== -1) return 'fa-file-image-o';
    if (NC_PDF_EXT.indexOf(ext) !== -1) return 'fa-file-pdf-o';
    if (NC_DOC_EXT.indexOf(ext) !== -1) return 'fa-file-text-o';
    if (NC_ARCHIVE_EXT.indexOf(ext) !== -1) return 'fa-file-archive-o';
    if (NC_AUDIO_EXT.indexOf(ext) !== -1) return 'fa-file-audio-o';
    if (NC_VIDEO_EXT.indexOf(ext) !== -1) return 'fa-file-video-o';
    return 'fa-file-o';
}

function ncFileKind(file) {
    if ('folder' === file.type) {
        return 'folder';
    }
    var ext = (file.filetype || '').toLowerCase();
    if (NC_IMAGE_EXT.indexOf(ext) !== -1) return 'image';
    if (NC_PDF_EXT.indexOf(ext) !== -1) return 'pdf';
    if (NC_VIDEO_EXT.indexOf(ext) !== -1) return 'video';
    return 'other';
}

function ncRex(key, fallback) {
    return (typeof rex !== 'undefined' && undefined !== rex[key]) ? rex[key] : fallback;
}

// -------------------------------------------------------------------------
// Mehrkonten: Account-Umschalter
// -------------------------------------------------------------------------
function ncConnections() {
    return Array.isArray(ncRex('nextcloudConnections', null)) ? rex.nextcloudConnections : [];
}

function initConnections() {
    var connections = ncConnections();
    if (0 === connections.length) {
        return;
    }

    var saved = null;
    try {
        saved = localStorage.getItem('nc_connection');
    } catch (e) { /* Private-Mode o.ae. -- einfach ohne gemerkten Account starten */ }

    var match = connections.filter(function (c) { return c.id === saved; })[0];
    currentConnectionId = match ? match.id : connections[0].id;

    renderAccountSwitcher(connections);
}

function renderAccountSwitcher(connections) {
    var wrap = document.getElementById('nc-accounts');
    if (!wrap) {
        return;
    }
    if (connections.length <= 1) {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = '';
    wrap.innerHTML = connections.map(function (c) {
        var active = c.id === currentConnectionId ? ' nc-account-active' : '';
        // Akzentfarbe optional aus cloudconnect (ConnectionStore::create()/setColor()) --
        // faerbt Button-Rahmen UND Icon ein statt eines separaten Punkts
        // (gleiches Prinzip wie MediaPlace's Icon-Einfaerbung), unabhaengig
        // vom aktiv-Zustand (der laeuft weiterhin zusaetzlich ueber den
        // Hintergrund/nc-account-active).
        var style = c.color ? ' style="border-color:' + ncEscAttr(c.color) + '"' : '';
        var iconStyle = c.color ? ' style="color:' + ncEscAttr(c.color) + '"' : '';
        return '<div class="nc-account-item' + active + '" data-connection="' + ncEscAttr(c.id) + '"' + style + '>' +
            '<i class="rex-icon fa-cloud"' + iconStyle + '></i>' + ncEscHtml(c.label) + '</div>';
    }).join('');

    // Trennlinie unter dem Konto-Umschalter uebernimmt die Akzentfarbe des
    // GERADE AKTIVEN Accounts (falls gesetzt) -- zusaetzlicher, dezenter
    // Hinweis "das ist der Kontext, in dem du gerade browst", auch wenn der
    // Umschalter selbst durch Scrollen aus dem Blick geraet.
    var activeConn = connections.filter(function (c) { return c.id === currentConnectionId; })[0];
    wrap.style.borderBottomColor = (activeConn && activeConn.color) ? activeConn.color : '';

    Array.prototype.forEach.call(wrap.querySelectorAll('.nc-account-item'), function (el) {
        el.addEventListener('click', function () {
            switchAccount(el.getAttribute('data-connection'));
        });
    });
}

function switchAccount(id) {
    if (!id || id === currentConnectionId) {
        return;
    }
    currentConnectionId = id;
    try {
        localStorage.setItem('nc_connection', id);
    } catch (e) { /* ignore */ }

    renderAccountSwitcher(ncConnections());
    selectedFiles.clear();
    openFolder('/');
}

// -------------------------------------------------------------------------
// Ansicht-Umschalter (Liste/Galerie) -- ein einzelner Trigger-Button (zeigt
// das Icon der aktiven Ansicht), oeffnet ein kleines, per JS positioniertes
// Dropdown-Menue. Gleiches Grundmuster wie MediaPlace's
// .mp3-view-toggle-wrap/-btn/-menu (siehe core.js dort), eigene nc-Klassen.
// -------------------------------------------------------------------------
var NC_VIEW_MODE_ICONS = { list: 'fa-list', masonry: 'fa-th-large' };

function initViewMode() {
    var saved = null;
    try {
        saved = localStorage.getItem('nc_view');
    } catch (e) { /* ignore */ }
    currentViewMode = 'masonry' === saved ? 'masonry' : 'list';
    updateViewToggleTrigger();
    updateViewToggleActiveItem();
}

function updateViewToggleTrigger() {
    var icon = document.querySelector('.nc-view-toggle-btn i');
    if (icon) {
        icon.className = 'rex-icon ' + (NC_VIEW_MODE_ICONS[currentViewMode] || NC_VIEW_MODE_ICONS.list);
    }
}

function updateViewToggleActiveItem() {
    Array.prototype.forEach.call(document.querySelectorAll('.nc-view-toggle-menu .nc-view-btn'), function (btn) {
        btn.classList.toggle('nc-view-active', btn.getAttribute('data-view') === currentViewMode);
    });
}

function setViewMode(mode) {
    if (mode === currentViewMode || !NC_VIEW_MODE_ICONS[mode]) {
        return;
    }
    currentViewMode = mode;
    try {
        localStorage.setItem('nc_view', currentViewMode);
    } catch (e) { /* ignore */ }
    updateViewToggleTrigger();
    updateViewToggleActiveItem();
    // Re-render mit den zuletzt geladenen Daten (Ordnerinhalt ODER
    // Suchergebnis) statt eines Server-Roundtrips -- lastRenderedFiles wird
    // in renderFiles() bei jedem Aufruf aktuell gehalten.
    renderFiles(lastRenderedFiles);
}

function initViewToggleUi() {
    var wrap = document.getElementById('nc-view-toggle');
    if (!wrap) {
        return;
    }
    var btn = wrap.querySelector('.nc-view-toggle-btn');
    var menu = wrap.querySelector('.nc-view-toggle-menu');
    if (!btn || !menu) {
        return;
    }

    function positionMenu() {
        var rect = btn.getBoundingClientRect();
        var menuWidth = Math.max(menu.offsetWidth, 150);
        var left = Math.max(8, Math.min(rect.left, window.innerWidth - menuWidth - 8));
        var top = rect.bottom + 4;
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var willOpen = !wrap.classList.contains('nc-view-toggle-open');
        wrap.classList.toggle('nc-view-toggle-open', willOpen);
        if (willOpen) {
            positionMenu();
        }
    });

    document.addEventListener('click', function (e) {
        if (!wrap.classList.contains('nc-view-toggle-open')) {
            return;
        }
        if (e.target.closest('#nc-view-toggle')) {
            return;
        }
        wrap.classList.remove('nc-view-toggle-open');
    });

    menu.addEventListener('click', function (e) {
        var item = e.target.closest('.nc-view-btn');
        if (!item) {
            return;
        }
        wrap.classList.remove('nc-view-toggle-open');
        setViewMode(item.getAttribute('data-view'));
    });
}

// -------------------------------------------------------------------------
// API-Aufrufe
// -------------------------------------------------------------------------
function apiParams(action, extra) {
    var params = {
        page: 'nextcloud/main',
        'rex-api-call': 'nextcloud',
        action: action,
        connection_id: currentConnectionId || '',
    };
    if (extra) {
        Object.keys(extra).forEach(function (key) {
            params[key] = extra[key];
        });
    }
    return params;
}

// -------------------------------------------------------------------------
// Datei-/Ordnerliste
// -------------------------------------------------------------------------
function loadFiles(path) {
    path = path || '/';
    currentPath = path;
    selectedFiles.clear();
    updateToolbar();

    var fileList = document.getElementById('fileList');
    if (!fileList) {
        return;
    }
    if (!currentConnectionId) {
        fileList.innerHTML = '<div class="nc-empty">' + ncEscHtml(ncRex('nextcloudNoAccount', 'Kein Nextcloud-Account ausgewählt.')) + '</div>';
        return;
    }

    fileList.innerHTML = '<div class="nc-loading"><i class="rex-icon fa-spinner fa-spin"></i></div>';

    var url = 'index.php?' + $.param(apiParams('list', { path: path }));

    fetch(url)
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                updateBreadcrumb(path);
                currentFiles = Array.isArray(data.data) ? data.data : [];
                if ('' !== currentSearchTerm.trim()) {
                    performRecursiveSearch(currentSearchTerm);
                } else {
                    renderFiles(currentFiles);
                }
            } else {
                throw new Error(data.error || 'Unknown error occurred');
            }
        })
        .catch(function (error) {
            fileList.innerHTML = '<div class="nc-error">' + ncEscHtml(error.message) + '</div>';
            alert('Fehler: ' + error.message);
        });
}

function openFolder(path) {
    currentSearchTerm = '';
    searchRequestCounter++;

    if (null !== searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }

    var searchInput = document.getElementById('nextcloud-search-input');
    if (searchInput) {
        searchInput.value = '';
    }
    updateSearchCount(false, 0);
    loadFiles(path);
}

function parentRowHtml() {
    var parentPath = currentPath.split('/').slice(0, -1).join('/') || '/';
    return '<div class="nc-row nc-row-folder" data-path="' + ncEscAttr(parentPath) + '" data-action="open-folder">' +
        '<div class="nc-row-check"></div>' +
        '<div class="nc-row-icon"><i class="rex-icon fa-level-up"></i></div>' +
        '<div class="nc-row-name"><span class="nc-row-name-text">..</span></div>' +
        '<div class="nc-row-size"></div>' +
        '<div class="nc-row-date"></div>' +
        '<div class="nc-row-actions"></div>' +
        '</div>';
}

function renderFileRow(file, isSearchActive) {
    var isFolder = 'folder' === file.type;
    var decodedName = decodeURIComponent(file.name || '');
    var pathAttr = ncEscAttr(file.path || '');
    var nameAttr = ncEscAttr(decodedName);
    var rowClasses = ['nc-row'];
    if (isFolder) {
        rowClasses.push('nc-row-folder');
    }
    if (!isFolder && selectedFiles.has(file.path)) {
        rowClasses.push('nc-row-selected');
    }

    var checkbox = !isFolder
        ? '<input type="checkbox" class="nc-row-checkbox" data-path="' + pathAttr + '"' + (selectedFiles.has(file.path) ? ' checked' : '') + '>'
        : '';

    var kind = ncFileKind(file);
    var nameHtml;
    if (!isFolder && ('image' === kind || 'pdf' === kind || 'video' === kind)) {
        nameHtml = '<a href="#" data-action="preview" data-path="' + pathAttr + '">' + ncEscHtml(decodedName) + '</a>';
    } else {
        nameHtml = '<span class="nc-row-name-text">' + ncEscHtml(decodedName) + '</span>';
    }

    var hint = isSearchActive
        ? '<div class="nc-row-hint">' + ncEscHtml(decodeURIComponent(file.path || '')) + '</div>'
        : '';

    var sizeText = isFolder ? '' : ncFormatBytes(file.filesize);
    var dateText = ncFormatDate(file.modified);

    var actions = '';
    if (!isFolder) {
        if (ncRex('nextcloudDownloadEnabled', false)) {
            actions += '<button type="button" class="nc-icon-btn" title="Herunterladen" data-action="download" data-path="' + pathAttr + '" data-type="file"><i class="rex-icon fa-download"></i></button>';
        }
        actions += '<button type="button" class="nc-icon-btn" title="Importieren" data-action="import" data-path="' + pathAttr + '"><i class="rex-icon fa-upload"></i></button>';
        if (ncRex('nextcloudSharingEnabled', false)) {
            actions += '<button type="button" class="nc-icon-btn" title="Share-Link erstellen" data-action="share" data-path="' + pathAttr + '" data-name="' + nameAttr + '"><i class="rex-icon fa-share-alt"></i></button>';
        }
        if (ncRex('nextcloudDeleteEnabled', false)) {
            actions += '<button type="button" class="nc-icon-btn nc-icon-btn-danger" title="Löschen" data-action="delete" data-path="' + pathAttr + '" data-name="' + nameAttr + '"><i class="rex-icon fa-trash"></i></button>';
        }
    } else if (ncRex('nextcloudDownloadEnabled', false)) {
        actions += '<button type="button" class="nc-icon-btn" title="Als ZIP herunterladen" data-action="download" data-path="' + pathAttr + '" data-type="folder"><i class="rex-icon fa-download"></i></button>';
    }

    return '<div class="' + rowClasses.join(' ') + '"' + (isFolder ? ' data-path="' + pathAttr + '" data-action="open-folder"' : '') + '>' +
        '<div class="nc-row-check">' + checkbox + '</div>' +
        '<div class="nc-row-icon"><i class="rex-icon ' + ncFileIcon(file) + '"></i></div>' +
        '<div class="nc-row-name">' + nameHtml + hint + '</div>' +
        '<div class="nc-row-size">' + ncEscHtml(sizeText) + '</div>' +
        '<div class="nc-row-date">' + ncEscHtml(dateText) + '</div>' +
        '<div class="nc-row-actions">' + actions + '</div>' +
        '</div>';
}

function ncEmptyStateHtml() {
    var noResultsText = ncRex('nextcloudSearchNoResults', 'Keine Treffer gefunden.');
    return '<div class="nc-empty">' + ncEscHtml(noResultsText) + '</div>';
}

function renderFiles(files) {
    var fileList = document.getElementById('fileList');
    if (!fileList) {
        return;
    }
    var isSearchActive = '' !== currentSearchTerm.trim();
    var visibleFiles = Array.isArray(files) ? files.slice() : [];
    // Gemerkt fuer setViewMode(): beim Umschalten Liste<->Galerie ohne
    // Server-Roundtrip mit denselben Daten neu rendern.
    lastRenderedFiles = visibleFiles;

    updateSearchCount(isSearchActive, visibleFiles.length);

    visibleFiles.sort(function (a, b) {
        if ('folder' === a.type && 'folder' !== b.type) return -1;
        if ('folder' !== a.type && 'folder' === b.type) return 1;
        return decodeURIComponent(a.name || '').localeCompare(decodeURIComponent(b.name || ''));
    });

    if ('masonry' === currentViewMode) {
        renderMasonryView(visibleFiles);
    } else {
        renderListView(visibleFiles, isSearchActive);
    }
}

function renderListView(visibleFiles, isSearchActive) {
    var fileList = document.getElementById('fileList');
    // className explizit setzen (nicht nur Inhalt): sonst bleibt z.B.
    // "nc-list nc-view-masonry" vom letzten Render stehen (gleicher Grund
    // wie MediaPlace's renderFiles()-Kommentar zu grid.className).
    fileList.className = 'nc-list';

    var html = '';
    if ('/' !== currentPath && !isSearchActive) {
        html += parentRowHtml();
    }
    if (0 === visibleFiles.length) {
        html += ncEmptyStateHtml();
    }
    visibleFiles.forEach(function (file) {
        html += renderFileRow(file, isSearchActive);
    });

    fileList.innerHTML = html;
}

function renderMasonryView(visibleFiles) {
    var fileList = document.getElementById('fileList');
    fileList.className = 'nc-list nc-view-masonry';

    var html = '';
    if (0 === visibleFiles.length) {
        html += ncEmptyStateHtml();
    }
    // Kein ".."-Kachel-Aequivalent in der Galerie-Ansicht -- Navigation nach
    // oben laeuft hier ueber den Breadcrumb (gleiche Entscheidung wie
    // MediaPlace: dessen Media-Wall/Grid hat ebenfalls keine "hoch"-Kachel,
    // Navigation laeuft dort ausschliesslich ueber die Sidebar).
    visibleFiles.forEach(function (file) {
        html += renderMasonryTile(file);
    });

    fileList.innerHTML = html;
}

/**
 * Galerie-/Masonry-Kachel -- MediaPlace-.mp3-masonry-card-inspiriert
 * (eigene nc-Klassen). Bild-Kacheln nutzen die bestehende 'preview'-Action
 * als <img src>, Video-Kacheln die bestehende 'video_preview'-Action als
 * <video>-Thumbnail (zeigt den ersten Frame nach Laden der Metadaten) --
 * beide Endpunkte liefern bereits Rohbytes, kein neuer Server-Code noetig.
 * PDFs/Dokumente/Audio/Archive bekommen ein grosses Icon-Platzhalterfeld
 * (kein PDF-Rendering-Endpunkt vorhanden). Ordner ebenfalls nur ein Icon.
 */
function renderMasonryTile(file) {
    var isFolder = 'folder' === file.type;
    var decodedName = decodeURIComponent(file.name || '');
    var pathAttr = ncEscAttr(file.path || '');
    var nameAttr = ncEscAttr(decodedName);
    var isSelected = !isFolder && selectedFiles.has(file.path);
    var kind = ncFileKind(file);

    var cardClasses = ['nc-masonry-card'];
    if (isSelected) {
        cardClasses.push('nc-masonry-card-selected');
    }

    var mediaHtml;
    if ('image' === kind) {
        var previewUrl = 'index.php?' + $.param(apiParams('preview', { path: file.path }));
        mediaHtml = '<img src="' + previewUrl + '" alt="' + nameAttr + '" loading="lazy">';
    } else if ('video' === kind) {
        var videoUrl = 'index.php?' + $.param(apiParams('video_preview', { path: file.path }));
        mediaHtml = '<video src="' + videoUrl + '" muted preload="metadata"></video>';
    } else {
        mediaHtml = '<div class="nc-masonry-icon"><i class="rex-icon ' + ncFileIcon(file) + '"></i></div>';
    }

    var toolbar = '<div class="nc-masonry-toolbar">';
    if (!isFolder) {
        toolbar += '<span class="nc-masonry-check" title="Auswählen" data-action="toggle-select" data-path="' + pathAttr + '"><i class="rex-icon ' + (isSelected ? 'fa-check-square-o' : 'fa-square-o') + '"></i></span>';
        if (ncRex('nextcloudDownloadEnabled', false)) {
            toolbar += '<button type="button" class="nc-icon-btn" title="Herunterladen" data-action="download" data-path="' + pathAttr + '" data-type="file"><i class="rex-icon fa-download"></i></button>';
        }
        toolbar += '<button type="button" class="nc-icon-btn" title="Importieren" data-action="import" data-path="' + pathAttr + '"><i class="rex-icon fa-upload"></i></button>';
        if (ncRex('nextcloudSharingEnabled', false)) {
            toolbar += '<button type="button" class="nc-icon-btn" title="Share-Link erstellen" data-action="share" data-path="' + pathAttr + '" data-name="' + nameAttr + '"><i class="rex-icon fa-share-alt"></i></button>';
        }
        if (ncRex('nextcloudDeleteEnabled', false)) {
            toolbar += '<button type="button" class="nc-icon-btn nc-icon-btn-danger" title="Löschen" data-action="delete" data-path="' + pathAttr + '" data-name="' + nameAttr + '"><i class="rex-icon fa-trash"></i></button>';
        }
    } else if (ncRex('nextcloudDownloadEnabled', false)) {
        toolbar += '<button type="button" class="nc-icon-btn" title="Als ZIP herunterladen" data-action="download" data-path="' + pathAttr + '" data-type="folder"><i class="rex-icon fa-download"></i></button>';
    }
    toolbar += '</div>';

    var tileAction = '';
    if (isFolder) {
        tileAction = ' data-action="open-folder"';
    } else if ('image' === kind || 'pdf' === kind || 'video' === kind) {
        tileAction = ' data-action="preview"';
    }

    var sizeMetaParts = [];
    if (!isFolder) {
        var sizeText = ncFormatBytes(file.filesize);
        if (sizeText) {
            sizeMetaParts.push(sizeText);
        }
    }
    var dateText = ncFormatDate(file.modified);
    if (dateText) {
        sizeMetaParts.push(dateText);
    }

    return '<div class="' + cardClasses.join(' ') + '" data-path="' + pathAttr + '"' + tileAction + '>' +
        toolbar +
        '<div class="nc-masonry-media">' + mediaHtml + '</div>' +
        '<div class="nc-masonry-footer">' +
        '<span class="nc-masonry-name" title="' + nameAttr + '">' + ncEscHtml(decodedName) + '</span>' +
        '<span class="nc-masonry-meta">' + ncEscHtml(sizeMetaParts.join(' · ')) + '</span>' +
        '</div>' +
        '</div>';
}

// -------------------------------------------------------------------------
// Suche
// -------------------------------------------------------------------------
function applyLiveSearch(term) {
    currentSearchTerm = (term || '').trim();

    if (null !== searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }

    if ('' === currentSearchTerm) {
        updateSearchCount(false, 0);
        renderFiles(currentFiles);
        return;
    }

    searchDebounceTimer = setTimeout(function () {
        performRecursiveSearch(currentSearchTerm);
    }, 250);
}

function performRecursiveSearch(term) {
    var requestId = ++searchRequestCounter;
    var fileList = document.getElementById('fileList');
    updateSearchCount(true, 0, true);
    if (fileList) {
        fileList.innerHTML = '<div class="nc-loading"><i class="rex-icon fa-spinner fa-spin"></i> Suche läuft...</div>';
    }

    var url = 'index.php?' + $.param(apiParams('search', { path: currentPath, query: term }));

    fetch(url)
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (requestId !== searchRequestCounter) {
                return;
            }
            if (data.success) {
                selectedFiles.clear();
                updateToolbar();
                renderFiles(Array.isArray(data.data) ? data.data : []);
                return;
            }
            throw new Error(data.error || 'Suche fehlgeschlagen');
        })
        .catch(function (error) {
            if (requestId !== searchRequestCounter) {
                return;
            }
            updateSearchCount(true, 0);
            if (fileList) {
                fileList.innerHTML = '<div class="nc-error">' + ncEscHtml(error.message) + '</div>';
            }
        });
}

function updateSearchCount(isSearchActive, count, isLoading) {
    var countNode = document.getElementById('nextcloud-search-count');
    if (!countNode) {
        return;
    }
    if (!isSearchActive) {
        countNode.textContent = '';
        return;
    }
    if (isLoading) {
        countNode.textContent = 'Suche läuft...';
        return;
    }
    var suffix = ncRex('nextcloudSearchResultSuffix', 'Treffer');
    countNode.textContent = count + ' ' + suffix;
}

// -------------------------------------------------------------------------
// Auswahl-Toolbar (Mehrfachauswahl: Import/Löschen/ZIP-Download)
// -------------------------------------------------------------------------
function updateToolbar() {
    var container = document.getElementById('nc-toolbar-actions');
    if (!container) {
        return;
    }
    var importBtn = container.querySelector('#btnImportSelected');
    var deleteBtn = container.querySelector('#btnDeleteSelected');
    var downloadBtn = container.querySelector('#btnDownloadSelected');

    if (selectedFiles.size > 0) {
        if (!importBtn) {
            importBtn = document.createElement('button');
            importBtn.type = 'button';
            importBtn.id = 'btnImportSelected';
            importBtn.className = 'nc-btn nc-btn-primary';
            importBtn.addEventListener('click', importSelectedFiles);
            container.insertBefore(importBtn, container.firstChild);
        }
        importBtn.innerHTML = '<i class="rex-icon fa-upload"></i> ' + selectedFiles.size + ' importieren';

        if (ncRex('nextcloudDeleteEnabled', false)) {
            if (!deleteBtn) {
                deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.id = 'btnDeleteSelected';
                deleteBtn.className = 'nc-btn nc-btn-danger';
                deleteBtn.addEventListener('click', deleteSelectedFiles);
                container.insertBefore(deleteBtn, container.firstChild);
            }
            deleteBtn.innerHTML = '<i class="rex-icon fa-trash"></i> ' + selectedFiles.size + ' löschen';
        }

        if (ncRex('nextcloudDownloadEnabled', false)) {
            if (!downloadBtn) {
                downloadBtn = document.createElement('button');
                downloadBtn.type = 'button';
                downloadBtn.id = 'btnDownloadSelected';
                downloadBtn.className = 'nc-btn';
                downloadBtn.addEventListener('click', downloadSelectedFilesAsZip);
                container.insertBefore(downloadBtn, container.firstChild);
            }
            downloadBtn.innerHTML = '<i class="rex-icon fa-download"></i> ' + selectedFiles.size + ' als ZIP';
        }
    } else {
        if (importBtn) importBtn.remove();
        if (deleteBtn) deleteBtn.remove();
        if (downloadBtn) downloadBtn.remove();
    }
}

async function importSelectedFiles() {
    var categoryEl = document.getElementById('rex-mediapool-category');
    var categoryId = categoryEl ? categoryEl.value : 0;
    var files = Array.from(selectedFiles);
    var total = files.length;
    if (0 === total) {
        return;
    }

    var imported = 0;
    var failed = [];
    var modal = ncCreateProgressModal('Importiere Dateien...');
    var processed = 0;

    for (var i = 0; i < files.length; i++) {
        var path = files[i];
        var fileName = decodeURIComponent(path.split('/').pop());

        ncSetProgress(modal, Math.round((processed / total) * 100), 'Importiere "' + fileName + '" (' + (processed + 1) + ' von ' + total + ')');

        try {
            var url = 'index.php?' + $.param(apiParams('import', { path: path, category_id: categoryId }));
            var response = await fetch(url);
            var data = await response.json();

            if (data.success) {
                imported++;
            } else {
                failed.push({ name: fileName, error: data.error || 'Unbekannter Fehler' });
            }

            await new Promise(function (resolve) { setTimeout(resolve, 500); });
        } catch (error) {
            failed.push({ name: fileName, error: error.message });
        }

        processed++;
        ncSetProgress(modal, Math.round((processed / total) * 100));
    }

    setTimeout(function () {
        modal.modal('hide');

        if (failed.length > 0) {
            var message = 'Import abgeschlossen:\n\n' + imported + ' Dateien erfolgreich importiert\n' + failed.length + ' Fehler:\n\n';
            failed.forEach(function (f) { message += '- ' + f.name + ': ' + f.error + '\n'; });
            alert(message);
        } else {
            alert('Alle ' + imported + ' Dateien wurden erfolgreich importiert.');
        }

        loadFiles(currentPath);
    }, 500);
}

async function deleteSelectedFiles() {
    if (!ncRex('nextcloudDeleteEnabled', false)) {
        alert('Keine Berechtigung zum Löschen von Nextcloud-Dateien.');
        return;
    }

    var files = Array.from(selectedFiles);
    if (0 === files.length) {
        alert('Bitte zuerst Dateien auswählen.');
        return;
    }

    if (!window.confirm(files.length + ' ausgewählte Dateien wirklich löschen?')) {
        return;
    }

    var modal = ncCreateProgressModal('Lösche Dateien...');
    var deleted = 0;
    var failed = [];

    for (var i = 0; i < files.length; i++) {
        var path = files[i];
        var fileName = decodeURIComponent(path.split('/').pop() || path);

        ncSetProgress(modal, Math.round((i / files.length) * 100), 'Lösche "' + fileName + '" (' + (i + 1) + ' von ' + files.length + ')');

        try {
            var url = 'index.php?' + $.param(apiParams('delete', { path: path }));
            var response = await fetch(url);
            var data = await response.json();

            if (data.success) {
                deleted++;
            } else {
                failed.push({ name: fileName, error: data.error || 'Unbekannter Fehler' });
            }
        } catch (error) {
            failed.push({ name: fileName, error: error.message });
        }

        ncSetProgress(modal, Math.round(((i + 1) / files.length) * 100));
    }

    modal.modal('hide');

    if (failed.length > 0) {
        var message = 'Löschen abgeschlossen:\n\n' + deleted + ' Dateien erfolgreich gelöscht\n' + failed.length + ' Fehler:\n\n';
        failed.forEach(function (f) { message += '- ' + f.name + ': ' + f.error + '\n'; });
        alert(message);
    } else {
        alert('Alle ' + deleted + ' Dateien wurden erfolgreich gelöscht.');
    }

    loadFiles(currentPath);
}

function downloadSelectedFilesAsZip() {
    if (!ncRex('nextcloudDownloadEnabled', false)) {
        alert('Keine Berechtigung zum Herunterladen von Nextcloud-Dateien.');
        return;
    }

    var files = Array.from(selectedFiles);
    if (0 === files.length) {
        alert('Bitte zuerst Dateien auswählen.');
        return;
    }

    if (!window.confirm(files.length + ' ausgewählte Dateien als ZIP herunterladen?')) {
        return;
    }

    submitDownloadForm(apiParams('download_zip', { paths_json: JSON.stringify(files) }));
}

function downloadRemoteItem(path, itemType) {
    if (!ncRex('nextcloudDownloadEnabled', false)) {
        alert('Keine Berechtigung zum Herunterladen von Nextcloud-Dateien.');
        return;
    }

    window.open('index.php?' + $.param(apiParams('download', { path: path, item_type: itemType })), '_blank');
}

function submitDownloadForm(params) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php';
    form.target = '_blank';

    Object.keys(params).forEach(function (key) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = params[key];
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function importFile(path) {
    var categoryEl = document.getElementById('rex-mediapool-category');
    var categoryId = categoryEl ? categoryEl.value : 0;
    var fileName = decodeURIComponent(path.split('/').pop());

    var url = 'index.php?' + $.param(apiParams('import', { path: path, category_id: categoryId }));

    fetch(url)
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                var msg = 'Datei erfolgreich importiert';
                if (data.data && data.data.tags_applied && data.data.tags_applied.length > 0) {
                    msg += '\nTags übernommen: ' + data.data.tags_applied.join(', ');
                }
                alert(msg);
                loadFiles(currentPath);
                $('.modal').modal('hide');
            } else {
                throw new Error(data.error || 'Import failed');
            }
        })
        .catch(function (error) {
            alert('Fehler beim Import von "' + fileName + '": ' + error.message);
        });
}

function deleteRemoteFile(path, name) {
    if (!ncRex('nextcloudDeleteEnabled', false)) {
        alert('Keine Berechtigung zum Löschen von Nextcloud-Dateien.');
        return;
    }

    if (!window.confirm('Datei wirklich löschen?\n\n' + name)) {
        return;
    }

    var url = 'index.php?' + $.param(apiParams('delete', { path: path }));

    fetch(url)
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.error || 'Löschen fehlgeschlagen');
            }
            alert('Datei wurde in Nextcloud gelöscht.');
            loadFiles(currentPath);
        })
        .catch(function (error) {
            alert('Fehler beim Löschen: ' + error.message);
        });
}

// -------------------------------------------------------------------------
// Upload Medienpool -> Nextcloud
// -------------------------------------------------------------------------
function getMedialistFilenames() {
    var hiddenInput = document.getElementById('REX_MEDIALIST_nextcloud-mediapool-upload-list');
    if (!hiddenInput) {
        return [];
    }

    var rawValue = hiddenInput.value.trim();
    if (!rawValue) {
        return [];
    }

    var unique = new Set();
    rawValue.split(',').forEach(function (entry) {
        var filename = entry.trim();
        if (filename) {
            unique.add(filename);
        }
    });

    return Array.from(unique);
}

async function uploadMediapoolFilesToCurrentFolder() {
    if (!ncRex('nextcloudUploadFromMediapoolEnabled', false)) {
        alert('Keine Berechtigung für diesen Upload.');
        return;
    }

    var mediaFilenames = getMedialistFilenames();
    if (0 === mediaFilenames.length) {
        alert('Bitte zuerst Dateien aus dem Medienpool auswählen.');
        return;
    }

    var progressModal = ncCreateProgressModal('Lade Dateien nach Nextcloud hoch...');
    var button = $('#btnUploadMedialistToNextcloud');
    var originalLabel = button.html();
    button.prop('disabled', true).html('<i class="rex-icon fa-spinner fa-spin"></i> Upload...');

    var uploaded = 0;
    var failed = [];

    for (var i = 0; i < mediaFilenames.length; i++) {
        var mediaFilename = mediaFilenames[i];
        ncSetProgress(progressModal, Math.round((i / mediaFilenames.length) * 100), 'Lade "' + mediaFilename + '" (' + (i + 1) + ' von ' + mediaFilenames.length + ')');

        try {
            var url = 'index.php?' + $.param(apiParams('upload_mediapool', { media_filename: mediaFilename, target_path: currentPath }));
            var response = await fetch(url);
            var data = await response.json();

            if (data.success) {
                uploaded++;
            } else {
                failed.push({ name: mediaFilename, error: data.error || 'Unbekannter Fehler' });
            }
        } catch (error) {
            failed.push({ name: mediaFilename, error: error.message });
        }

        ncSetProgress(progressModal, Math.round(((i + 1) / mediaFilenames.length) * 100));
    }

    progressModal.modal('hide');
    $('#nextcloud-mediapool-upload-modal').modal('hide');
    button.prop('disabled', false).html(originalLabel);

    if (failed.length > 0) {
        var message = 'Upload abgeschlossen:\n\n' + uploaded + ' Dateien erfolgreich hochgeladen\n' + failed.length + ' Fehler:\n\n';
        failed.forEach(function (f) { message += '- ' + f.name + ': ' + f.error + '\n'; });
        alert(message);
    } else {
        alert('Alle ' + uploaded + ' Dateien wurden erfolgreich nach Nextcloud hochgeladen.');
    }

    loadFiles(currentPath);
}

// -------------------------------------------------------------------------
// Fortschritts-Modal (Import-/Löschen-/Upload-Mehrfachaktionen)
// -------------------------------------------------------------------------
function ncCreateProgressModal(title) {
    var modal = $(
        '<div class="modal fade nc-modal" tabindex="-1" role="dialog">' +
        '<div class="modal-dialog" role="document">' +
        '<div class="modal-content">' +
        '<div class="modal-header"><h4 class="modal-title">' + ncEscHtml(title) + '</h4></div>' +
        '<div class="modal-body">' +
        '<div class="nc-progress"><div class="nc-progress-bar" style="width:0%"></div></div>' +
        '<div class="nc-modal-status"></div>' +
        '</div>' +
        '</div>' +
        '</div>' +
        '</div>',
    );

    $('body').append(modal);
    modal.modal({ backdrop: 'static', keyboard: false });
    modal.on('hidden.bs.modal', function () { modal.remove(); });

    return modal;
}

function ncSetProgress(modal, percent, statusText) {
    modal.find('.nc-progress-bar').css('width', percent + '%');
    if (undefined !== statusText) {
        modal.find('.nc-modal-status').text(statusText);
    }
}

// -------------------------------------------------------------------------
// Vorschau-Modal (Bild/PDF/Video vereinheitlicht, MediaPlace-Detail-Panel-
// inspiriert: grosse Vorschau, Meta-Tabelle, Aktions-Icon-Reihe)
// -------------------------------------------------------------------------
function openPreviewModal(file) {
    if (!file) {
        return;
    }

    var kind = ncFileKind(file);
    var name = decodeURIComponent(file.name || '');
    var previewAction = 'image' === kind ? 'preview' : ('pdf' === kind ? 'pdf_preview' : ('video' === kind ? 'video_preview' : null));
    var previewUrl = previewAction ? 'index.php?' + $.param(apiParams(previewAction, { path: file.path })) : '';

    var stageHtml;
    if ('image' === kind) {
        stageHtml = '<img src="' + previewUrl + '" alt="' + ncEscAttr(name) + '">';
    } else if ('video' === kind) {
        stageHtml = '<video controls preload="metadata" src="' + previewUrl + '">Ihr Browser unterstützt die Video-Vorschau nicht.</video>';
    } else if ('pdf' === kind) {
        stageHtml = '<iframe src="' + previewUrl + '" title="' + ncEscAttr(name) + '"></iframe>';
    } else {
        stageHtml = '<div class="nc-preview-icon"><i class="rex-icon ' + ncFileIcon(file) + '"></i></div>';
    }

    var metaRows = ''
        + '<tr><td>Name</td><td>' + ncEscHtml(name) + '</td></tr>'
        + '<tr><td>Pfad</td><td><code style="font-size:11px;">' + ncEscHtml(decodeURIComponent(file.path || '')) + '</code></td></tr>'
        + '<tr><td>Größe</td><td>' + (ncFormatBytes(file.filesize) || '—') + '</td></tr>'
        + '<tr><td>Geändert</td><td>' + (ncFormatDate(file.modified) || '—') + '</td></tr>';

    var pathAttr = ncEscAttr(file.path || '');
    var nameAttr = ncEscAttr(name);
    var actions = '<button type="button" class="nc-btn nc-btn-primary" data-action="import" data-path="' + pathAttr + '"><i class="rex-icon fa-upload"></i> Importieren</button>';
    if (ncRex('nextcloudDownloadEnabled', false)) {
        actions += '<button type="button" class="nc-icon-btn" title="Herunterladen" data-action="download" data-path="' + pathAttr + '"><i class="rex-icon fa-download"></i></button>';
    }
    if (ncRex('nextcloudSharingEnabled', false)) {
        actions += '<button type="button" class="nc-icon-btn" title="Share-Link erstellen" data-action="share" data-path="' + pathAttr + '" data-name="' + nameAttr + '"><i class="rex-icon fa-share-alt"></i></button>';
    }
    if (ncRex('nextcloudDeleteEnabled', false)) {
        actions += '<button type="button" class="nc-icon-btn nc-icon-btn-danger" title="Löschen" data-action="delete" data-path="' + pathAttr + '" data-name="' + nameAttr + '"><i class="rex-icon fa-trash"></i></button>';
    }

    var modal = $(
        '<div class="modal fade nc-modal" tabindex="-1" role="dialog">' +
        '<div class="modal-dialog modal-lg" role="document">' +
        '<div class="modal-content">' +
        '<div class="modal-header">' +
        '<h4 class="modal-title">' + ncEscHtml(name) + '</h4>' +
        '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
        '</div>' +
        '<div class="modal-body">' +
        '<div class="nc-preview-stage">' + stageHtml + '</div>' +
        '<table class="nc-meta-table">' + metaRows + '</table>' +
        '<div class="nc-detail-actions-row">' + actions + '</div>' +
        '</div>' +
        '</div>' +
        '</div>' +
        '</div>',
    );

    $('body').append(modal);
    modal.modal('show');
    modal.on('hidden.bs.modal', function () { modal.remove(); });

    modal.find('[data-action="import"]').on('click', function () {
        importFile($(this).data('path'));
    });
    modal.find('[data-action="download"]').on('click', function () {
        downloadRemoteItem($(this).data('path'), 'file');
    });
    modal.find('[data-action="share"]').on('click', function () {
        modal.modal('hide');
        openShareModal($(this).data('path'), $(this).data('name'));
    });
    modal.find('[data-action="delete"]').on('click', function () {
        modal.modal('hide');
        deleteRemoteFile($(this).data('path'), $(this).data('name'));
    });
}

// -------------------------------------------------------------------------
// Share-Link-Modal
// -------------------------------------------------------------------------
function openShareModal(path, name) {
    var decodedPath = decodeURIComponent(path || '');
    var connectionHint = currentConnectionId ? (' connection="' + ncEscHtml(currentConnectionId) + '"') : '';

    var modal = $(
        '<div class="modal fade nc-modal" tabindex="-1" role="dialog" id="nextcloud-share-modal">' +
        '<div class="modal-dialog" role="document">' +
        '<div class="modal-content">' +
        '<div class="modal-header">' +
        '<h4 class="modal-title"><i class="rex-icon fa-share-alt"></i> Share-Link erstellen</h4>' +
        '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
        '</div>' +
        '<div class="modal-body">' +
        '<p style="word-break:break-all; color:var(--nc-text-muted); font-size:12px;">' + ncEscHtml(name) + '</p>' +
        '<p style="font-size:11px; color:var(--nc-text-muted);">Pfad: <code>' + ncEscHtml(decodedPath) + '</code></p>' +
        '<div class="nc-form-group">' +
        '<label for="share-expiry">Ablaufdatum (optional)</label>' +
        '<input type="date" id="share-expiry" class="nc-search-input" style="max-width:220px;" min="' + new Date().toISOString().split('T')[0] + '">' +
        '</div>' +
        '<div id="share-result" class="nc-share-result" style="display:none;">' +
        '<div class="nc-share-url-row">' +
        '<input type="text" id="share-url" readonly>' +
        '<button class="nc-icon-btn" id="btn-copy-share" type="button" title="In Zwischenablage kopieren"><i class="rex-icon fa-clone"></i></button>' +
        '</div>' +
        '<p class="nc-hint" style="margin-top:8px;">Im Modul-Output verwendbar als:<br><code>REX_NEXTCLOUD_SHARE[path="' + ncEscHtml(path) + '"' + connectionHint + ']</code></p>' +
        '</div>' +
        '<div id="share-error" class="nc-error" style="display:none;"></div>' +
        '<div id="share-loading" style="display:none; text-align:center;"><i class="rex-icon fa-spinner fa-spin fa-2x"></i></div>' +
        '</div>' +
        '<div class="modal-footer">' +
        '<button type="button" class="nc-btn" data-dismiss="modal">Schließen</button>' +
        '<button type="button" class="nc-btn nc-btn-primary" id="btn-create-share"><i class="rex-icon fa-share-alt"></i> Link erstellen</button>' +
        '</div>' +
        '</div>' +
        '</div>' +
        '</div>',
    );

    $('body').append(modal);
    modal.modal('show');
    modal.on('hidden.bs.modal', function () { modal.remove(); });

    modal.find('#btn-create-share').on('click', function () {
        var expiry = modal.find('#share-expiry').val();

        modal.find('#share-result, #share-error').hide();
        modal.find('#share-loading').show();
        modal.find('#btn-create-share').prop('disabled', true);

        var params = apiParams('share', { path: path });
        if (expiry) {
            params.expiry = expiry;
        }

        fetch('index.php?' + $.param(params))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                modal.find('#share-loading').hide();
                modal.find('#btn-create-share').prop('disabled', false);

                if (data.success && data.data && data.data.url) {
                    modal.find('#share-url').val(data.data.url);
                    modal.find('#share-result').show();
                } else {
                    var errMsg = data.error || 'Unbekannter Fehler';
                    var hint = '';
                    if (errMsg.toLowerCase().indexOf('freigabetyp') !== -1 || errMsg.toLowerCase().indexOf('share type') !== -1) {
                        hint = '<br><small>Hinweis: Bitte prüfen ob in den Nextcloud-Admineinstellungen '
                            + '(Einstellungen → Freigabe) die Option „Freigabe über Links erlauben" aktiviert ist.</small>';
                    }
                    if (errMsg.toLowerCase().indexOf('leitet weiter') !== -1 || errMsg.toLowerCase().indexOf('redirect') !== -1) {
                        hint = '<br><small>Hinweis: Die Nextcloud-URL leitet weiter. '
                            + 'Bitte in cloudconnect die korrekte HTTPS-URL ohne abschließenden Slash eintragen.</small>';
                    }
                    modal.find('#share-error').html('<strong>Fehler:</strong> ' + ncEscHtml(errMsg) + hint).show();
                }
            })
            .catch(function (err) {
                modal.find('#share-loading').hide();
                modal.find('#btn-create-share').prop('disabled', false);
                modal.find('#share-error').text('Fehler: ' + err.message).show();
            });
    });

    modal.find('#btn-copy-share').on('click', function () {
        var input = modal.find('#share-url')[0];
        input.select();
        try {
            navigator.clipboard.writeText(input.value).then(function () {
                var btn = modal.find('#btn-copy-share');
                btn.html('<i class="rex-icon fa-check"></i>');
                setTimeout(function () { btn.html('<i class="rex-icon fa-clone"></i>'); }, 2000);
            });
        } catch (e) {
            document.execCommand('copy');
        }
    });
}

// -------------------------------------------------------------------------
// Breadcrumb
// -------------------------------------------------------------------------
function updateBreadcrumb(path) {
    var breadcrumbEl = document.getElementById('nc-breadcrumb');
    if (!breadcrumbEl) {
        return;
    }

    var parts = path.split('/').filter(Boolean);
    var buildPath = '';
    var html = '<a href="#" data-path="/" title="Startverzeichnis" aria-label="Startverzeichnis"><i class="rex-icon fa-home"></i></a>';

    parts.forEach(function (part, index) {
        buildPath += '/' + part;
        var isLast = index === parts.length - 1;
        var label = decodeURIComponent(part);

        html += ' <span class="nc-breadcrumb-sep" aria-hidden="true">/</span> ';
        html += isLast
            ? '<span class="nc-breadcrumb-current">' + ncEscHtml(label) + '</span>'
            : '<a href="#" data-path="' + ncEscAttr(buildPath) + '">' + ncEscHtml(label) + '</a>';
    });

    breadcrumbEl.innerHTML = html;
}

// -------------------------------------------------------------------------
// Event-Delegation: Dateiliste, Breadcrumb
// -------------------------------------------------------------------------
function findFileByPath(path) {
    // lastRenderedFiles statt currentFiles: haelt auch waehrend einer
    // aktiven Suche die tatsaechlich gerade angezeigten Eintraege (siehe
    // renderFiles()) -- currentFiles ist nur der zuletzt geladene
    // Ordnerinhalt und waere waehrend einer Suche veraltet.
    return lastRenderedFiles.filter(function (f) { return f.path === path; })[0] || null;
}

/**
 * Gemeinsame Auswahl-Umschaltung fuer beide Ansichten -- Zeilen-Checkbox
 * (Listenansicht) und Auswahl-Icon (Galerie-Kachel-Toolbar) muessen bei
 * einem Klick jeweils synchron bleiben, egal welche Ansicht gerade sichtbar
 * ist (z.B. Auswahl in der Liste treffen, dann per Umschalter in die
 * Galerie wechseln -- Kachel muss die Auswahl korrekt zeigen).
 */
function toggleFileSelection(path) {
    if (!path) {
        return;
    }
    if (selectedFiles.has(path)) {
        selectedFiles.delete(path);
    } else {
        selectedFiles.add(path);
    }
    syncSelectionUi(path);
    updateToolbar();
}

/**
 * Aktualisiert die Auswahl-Darstellung fuer einen Pfad in beiden
 * Render-Varianten, ohne kompletten Re-Render -- iteriert ueber die feste
 * Klasse und vergleicht das data-path-Attribut manuell (kein dynamisch
 * gebauter CSS-Selector-String mit dem Pfad: der kann beliebige Zeichen
 * wie Anfuehrungszeichen enthalten und wuerde einen Attribut-Selector
 * potenziell zerbrechen).
 */
function syncSelectionUi(path) {
    var fileList = document.getElementById('fileList');
    if (!fileList) {
        return;
    }
    var isSelected = selectedFiles.has(path);

    Array.prototype.forEach.call(fileList.querySelectorAll('.nc-row-checkbox'), function (checkbox) {
        if (checkbox.getAttribute('data-path') !== path) {
            return;
        }
        checkbox.checked = isSelected;
        var row = checkbox.closest('.nc-row');
        if (row) {
            row.classList.toggle('nc-row-selected', isSelected);
        }
    });

    Array.prototype.forEach.call(fileList.querySelectorAll('.nc-masonry-card'), function (card) {
        if (card.getAttribute('data-path') !== path) {
            return;
        }
        card.classList.toggle('nc-masonry-card-selected', isSelected);
        var icon = card.querySelector('.nc-masonry-check i');
        if (icon) {
            icon.className = 'rex-icon ' + (isSelected ? 'fa-check-square-o' : 'fa-square-o');
        }
    });
}

function onFileListClick(e) {
    var target = e.target.closest('[data-action]');
    if (!target) {
        return;
    }
    e.stopPropagation();

    var action = target.getAttribute('data-action');
    var path = target.getAttribute('data-path');
    var name = target.getAttribute('data-name');
    var type = target.getAttribute('data-type');

    switch (action) {
        case 'open-folder':
            openFolder(path);
            break;
        case 'preview':
            e.preventDefault();
            openPreviewModal(findFileByPath(path));
            break;
        case 'toggle-select':
            e.preventDefault();
            toggleFileSelection(path);
            break;
        case 'import':
            importFile(path);
            break;
        case 'download':
            downloadRemoteItem(path, type || 'file');
            break;
        case 'share':
            openShareModal(path, name);
            break;
        case 'delete':
            deleteRemoteFile(path, name);
            break;
        default:
            break;
    }
}

function onFileListChange(e) {
    var checkbox = e.target.closest('.nc-row-checkbox');
    if (!checkbox) {
        return;
    }
    // Checkbox hat den "checked"-Zustand bereits selbst umgeschaltet (native
    // Browser-Semantik) -- toggleFileSelection() spiegelt selectedFiles auf
    // denselben Zustand, syncSelectionUi() macht das hier redundant, aber
    // idempotent (gleicher Wert), haelt beide Code-Pfade aber auf einer
    // gemeinsamen Funktion statt doppelter Toggle-Logik.
    toggleFileSelection(checkbox.getAttribute('data-path'));
}

function onBreadcrumbClick(e) {
    var link = e.target.closest('a[data-path]');
    if (!link) {
        return;
    }
    e.preventDefault();
    openFolder(link.getAttribute('data-path'));
}

// -------------------------------------------------------------------------
// Init
// -------------------------------------------------------------------------
$(document).on('rex:ready', function () {
    initConnections();

    if (0 === ncConnections().length) {
        // Kein Account konfiguriert -- main.php rendert in diesem Fall nur
        // den Hinweis-Block, die dynamischen Elemente unten existieren nicht.
        return;
    }

    initViewMode();
    initViewToggleUi();

    var btnRefresh = document.getElementById('btnRefresh');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () { loadFiles(currentPath); });
    }

    var btnHome = document.getElementById('btnHome');
    if (btnHome) {
        btnHome.addEventListener('click', function () { openFolder('/'); });
    }

    $('#btnOpenMediapoolUploadModal').on('click', function () {
        $('#nextcloud-mediapool-upload-modal').modal('show');
    });

    $('#btnUploadMedialistToNextcloud').on('click', function () {
        uploadMediapoolFilesToCurrentFolder();
    });

    var searchInput = document.getElementById('nextcloud-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function () { applyLiveSearch(this.value); });
    }

    var searchClear = document.getElementById('nextcloud-search-clear');
    if (searchClear) {
        searchClear.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            applyLiveSearch('');
        });
    }

    var fileListEl = document.getElementById('fileList');
    if (fileListEl) {
        fileListEl.addEventListener('click', onFileListClick);
        fileListEl.addEventListener('change', onFileListChange);
    }

    var breadcrumbEl = document.getElementById('nc-breadcrumb');
    if (breadcrumbEl) {
        breadcrumbEl.addEventListener('click', onBreadcrumbClick);
    }

    openFolder('/');
});
