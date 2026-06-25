// map.js — Geospatial Map Logic (Leaflet.js + regencies.json)
// Peta menggunakan titik centroid resmi per kab/kota dari regencies.json

'use strict';

// ─── Warna tema (light map) ───────────────────────────────────────────────────
const MAP_COLORS = {
    KI:  { active: '#E05A00', hover: '#FFA040', border: '#B84500', text: '#7C2D00' },
    KEK: { active: '#6D28D9', hover: '#A78BFA', border: '#5B21B6', text: '#3B0764' }
};

// ─── State ──────────────────────────────────────────────────────────────
let kawasanMap      = null;
let markersLayer    = null;
let mapData         = {};   // kdprovkab → { nmkab, kawasan[], total }
let currentJnskwMap = '';
let hoveredMarker   = null;
let tooltipEl       = null;

// Data centroid — diisi dari REGENCIES_DATA (regencies.js) saat initMap dipanggil
let regenciesData = [];

// ─── Tooltip HTML element (custom, lebih kaya dari Leaflet default) ──────────
function createTooltipEl() {
    let el = document.getElementById('map-custom-tooltip');
    if (!el) {
        el = document.createElement('div');
        el.id = 'map-custom-tooltip';
        el.className = 'map-custom-tooltip';
        document.body.appendChild(el);
    }
    return el;
}

// ─── Wait for Leaflet ─────────────────────────────────────────────────────────
function waitForLeaflet(timeout = 10000) {
    return new Promise((resolve, reject) => {
        if (typeof L !== 'undefined') { resolve(); return; }
        const start = Date.now();
        const check = setInterval(() => {
            if (typeof L !== 'undefined') { clearInterval(check); resolve(); }
            else if (Date.now() - start > timeout) {
                clearInterval(check);
                reject(new Error('Library peta tidak termuat'));
            }
        }, 100);
    });
}

// ─── Init Map ─────────────────────────────────────────────────────────────────
async function initMap(jnskw) {
    currentJnskwMap = jnskw;

    const container = document.getElementById('kawasan-map');
    if (!container) return;

    // Hancurkan peta lama
    if (kawasanMap) {
        kawasanMap.remove();
        kawasanMap    = null;
        markersLayer  = null;
    }

    resetMapInfoPanel();
    setMapLoading(true);

    try {
        await waitForLeaflet();

        // 1. Fetch data kawasan dari API
        const res  = await fetch(`api.php?action=get_map_data&jnskw=${jnskw}`);
        const json = await res.json();
        if (json.status !== 'success') throw new Error(json.message);

        mapData = {};
        json.data.forEach(d => {
            const key = String(d.kdprovkab).trim();
            mapData[key] = d;
        });

        // 2. Isi regenciesData dari REGENCIES_DATA (regencies.js embedded)
        if (regenciesData.length === 0) {
            if (typeof REGENCIES_DATA !== 'undefined' && Array.isArray(REGENCIES_DATA) && REGENCIES_DATA.length > 0) {
                regenciesData = REGENCIES_DATA;
            } else {
                // Fallback: coba fetch regencies.json
                try {
                    const rRes = await fetch('regencies.json');
                    if (!rRes.ok) throw new Error('HTTP ' + rRes.status);
                    regenciesData = await rRes.json();
                } catch (fetchErr) {
                    throw new Error('Upload file regencies.js ke server hosting Anda.');
                }
            }
        }

        // 3. Inisialisasi Leaflet
        kawasanMap = L.map('kawasan-map', {
            center: [-2.5, 118],
            zoom: 5,
            zoomControl: true,
            attributionControl: true,
            scrollWheelZoom: true
        });

        // Tile layer CartoDB Positron (tema terang, bersih, modern)
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 18
        }).addTo(kawasanMap);

        // 4. Render markers
        renderMarkers(jnskw);

        // 5. Fix Leaflet hit-area offset
        // Invalidate size at 100ms and 600ms after init so layout is settled
        setTimeout(() => { if (kawasanMap) kawasanMap.invalidateSize(); }, 100);
        setTimeout(() => { if (kawasanMap) kawasanMap.invalidateSize(); }, 600);

        // Invalidate on scroll (debounced — only after scroll stops)
        let scrollTimer = null;
        const onScroll = () => {
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(() => {
                if (kawasanMap) kawasanMap.invalidateSize();
            }, 80);
        };
        window.addEventListener('scroll', onScroll, { passive: true });

        // Invalidate when info panel finishes animating (its width affects map width)
        const infoPanel = document.getElementById('map-info-panel');
        if (infoPanel) {
            infoPanel.addEventListener('transitionend', () => {
                if (kawasanMap) kawasanMap.invalidateSize();
            });
        }

        // ResizeObserver: recalculate whenever the map container size changes
        const mapContainer = document.getElementById('kawasan-map');
        if (mapContainer && window.ResizeObserver) {
            const ro = new ResizeObserver(() => {
                if (kawasanMap) kawasanMap.invalidateSize();
            });
            ro.observe(mapContainer);
        }

        // 6. Sembunyikan tooltip saat klik di luar
        kawasanMap.on('click', () => {
            hideTooltip();
            resetMapInfoPanel();
        });


    } catch (err) {
        console.error('Map init error:', err);
        if (container) container.innerHTML = `
            <div class="map-error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Gagal memuat peta: ${err.message}</p>
                <small>Data direktori tetap tersedia di bawah</small>
            </div>`;
    } finally {
        setMapLoading(false);
    }
}

// ─── Render Markers dari regencies.json ───────────────────────────────────────
function renderMarkers(jnskw) {
    if (!kawasanMap) return;

    if (markersLayer) {
        kawasanMap.removeLayer(markersLayer);
        markersLayer = null;
    }

    const colors = MAP_COLORS[jnskw] || MAP_COLORS.KI;
    markersLayer = L.layerGroup();

    let activeCount = 0;

    regenciesData.forEach(reg => {
        const lat = parseFloat(reg.latitude);
        const lng = parseFloat(reg.longitude);
        const id  = String(reg.id).trim();

        // Validasi koordinat — Indonesia: lat -11 s/d 6, lng 95 s/d 141
        if (isNaN(lat) || isNaN(lng) || lat < -11 || lat > 6 || lng < 95 || lng > 141) {
            console.warn(`Koordinat di luar wilayah Indonesia: ${reg.name} [${lat}, ${lng}]`);
            return;
        }

        const data    = mapData[id];
        const hasData = !!data && data.total > 0;

        if (!hasData) return; // Tampilkan hanya kab yang punya kawasan

        activeCount++;

        // Ukuran marker berdasarkan jumlah perusahaan
        const total  = data.total || 0;
        const radius = Math.max(6, Math.min(20, 6 + Math.sqrt(total) * 1.2));

        const marker = L.circleMarker([lat, lng], {
            radius:      radius,
            fillColor:   colors.active,
            fillOpacity: 0.85,
            color:       colors.border,
            weight:      2,
            opacity:     1
        });

        marker._regData  = data;
        marker._regName  = reg.name;
        marker._regId    = id;
        marker._baseOpts  = { radius, fillColor: colors.active, fillOpacity: 0.88, color: '#ffffff', weight: 2 };
        marker._hoverOpts = { radius: radius + 3, fillColor: colors.hover, fillOpacity: 0.95, color: colors.border, weight: 3 };

        marker.on('mouseover', function(e) {
            this.setStyle(this._hoverOpts);
            this.bringToFront();
            showHoverTooltip(e, this._regData, this._regName);
        });

        marker.on('mouseout', function() {
            this.setStyle(this._baseOpts);
            hideTooltip();
        });

        marker.on('mousemove', function(e) {
            moveTooltip(e);
        });

        marker.on('click', function(e) {
            L.DomEvent.stopPropagation(e);
            showMapInfo(this._regData);
        });

        markersLayer.addLayer(marker);
    });

    markersLayer.addTo(kawasanMap);
    updateMapLegend(jnskw, activeCount);
}

// ─── Hover Tooltip ────────────────────────────────────────────────────────────
function showHoverTooltip(leafletEvent, data, regName) {
    const el = createTooltipEl();

    const kawasanRows = (data.kawasan || []).map(k =>
        `<div class="tooltip-kawasan-row">
            <span class="tooltip-kw-dot"></span>
            <span class="tooltip-kw-name">${escapeHtml(k.nama)}</span>
            <span class="tooltip-kw-count">${k.jml} prsh</span>
        </div>`
    ).join('');

    el.innerHTML = `
        <div class="tooltip-header">
            <i class="fas fa-map-marker-alt"></i>
            <strong>${escapeHtml(regName || data.nmkab)}</strong>
        </div>
        <div class="tooltip-total">
            <i class="fas fa-building"></i> ${data.total} Perusahaan
        </div>
        <div class="tooltip-divider"></div>
        <div class="tooltip-kawasan-list">
            ${kawasanRows || '<em>Tidak ada kawasan</em>'}
        </div>
        <div class="tooltip-hint">Klik untuk detail ›</div>
    `;

    el.style.display = 'block';
    el.classList.add('visible');
    moveTooltip(leafletEvent);
}

function moveTooltip(leafletEvent) {
    const el = document.getElementById('map-custom-tooltip');
    if (!el || el.style.display === 'none') return;

    // Gunakan mouse event dari Leaflet
    const e = leafletEvent.originalEvent || leafletEvent;
    const x = e.clientX || (e.touches && e.touches[0].clientX) || 0;
    const y = e.clientY || (e.touches && e.touches[0].clientY) || 0;

    const tw = el.offsetWidth  || 220;
    const th = el.offsetHeight || 120;
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    let left = x + 16;
    let top  = y - 20;

    if (left + tw > vw - 10) left = x - tw - 16;
    if (top + th > vh - 10)  top  = y - th - 10;
    if (top < 10)             top  = 10;

    el.style.left = left + 'px';
    el.style.top  = top  + 'px';
}

function hideTooltip() {
    const el = document.getElementById('map-custom-tooltip');
    if (el) {
        el.classList.remove('visible');
        el.style.display = 'none';
    }
}

// ─── Info Panel (saat klik marker) ────────────────────────────────────────────
function showMapInfo(data) {
    const panel  = document.getElementById('map-info-panel');
    const kabEl  = document.getElementById('map-info-kab');
    const bodyEl = document.getElementById('map-info-body');
    if (!panel || !kabEl || !bodyEl) return;

    kabEl.textContent = data.nmkab;

    const kawasanHTML = (data.kawasan || []).map(k => `
        <div class="map-info-kawasan" onclick="scrollToKawasan('${escapeHtml(k.nama)}', '${escapeHtml(data.nmkab)}')">
            <div class="info-kaw-name"><i class="fas fa-industry"></i> ${escapeHtml(k.nama)}</div>
            <div class="info-kaw-count">${k.jml} perusahaan</div>
        </div>
    `).join('');

    bodyEl.innerHTML = `
        <div class="map-info-total">
            <i class="fas fa-building"></i>
            <strong>${data.total}</strong> Perusahaan di ${escapeHtml(data.nmkab)}
        </div>
        <div class="map-info-kawasan-list">
            ${kawasanHTML}
        </div>
        <div class="map-info-footer">
            <button class="btn-goto-tree" onclick="scrollToKab('${escapeHtml(data.nmkab)}')">
                <i class="fas fa-list"></i> Lihat di Direktori
            </button>
        </div>
    `;

    panel.classList.add('active');
}

function closeMapInfo() {
    const panel = document.getElementById('map-info-panel');
    if (panel) panel.classList.remove('active');
}

function resetMapInfoPanel() {
    const panel  = document.getElementById('map-info-panel');
    const kabEl  = document.getElementById('map-info-kab');
    const bodyEl = document.getElementById('map-info-body');
    if (panel)  panel.classList.remove('active');
    if (kabEl)  kabEl.textContent = 'Arahkan atau klik wilayah pada peta';
    if (bodyEl) bodyEl.innerHTML = '<p class="map-info-hint"><i class="fas fa-hand-pointer"></i> Klik marker untuk melihat detail kawasan</p>';
}

// ─── Scroll ke node pohon direktori ──────────────────────────────────────────
function scrollToKab(nmkab) {
    const allKabNodes = document.querySelectorAll('.tree-node[data-type="kab"]');
    for (const node of allKabNodes) {
        const titleEl = node.querySelector('.node-title');
        if (titleEl && titleEl.innerText.toUpperCase().includes(nmkab.toUpperCase())) {
            expandAndScroll(node);
            return;
        }
    }
    if (typeof showToast === 'function') showToast(`Mencari: ${nmkab}...`);
}

function scrollToKawasan(namaKawasan, nmkab) {
    const allKawNodes = document.querySelectorAll('.tree-node[data-type="kawasan"]');
    for (const node of allKawNodes) {
        const titleEl = node.querySelector('.node-title');
        if (titleEl && titleEl.innerText === namaKawasan) {
            expandAndScroll(node);
            return;
        }
    }
    scrollToKab(nmkab);
}

function expandAndScroll(node) {
    const content  = node.querySelector('.node-content');
    const children = node.querySelector('.children-container');
    if (content && children && !children.classList.contains('active')) {
        content.classList.add('expanded');
        children.classList.add('active');
    }

    let ancestor = node.parentElement?.closest('.tree-node');
    while (ancestor) {
        const ac = ancestor.querySelector('.node-content');
        const ch = ancestor.querySelector('.children-container');
        if (ac && ch) { ac.classList.add('expanded'); ch.classList.add('active'); }
        ancestor = ancestor.parentElement?.closest('.tree-node');
    }

    setTimeout(() => {
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        node.classList.add('map-highlight');
        setTimeout(() => node.classList.remove('map-highlight'), 2500);
    }, 150);
}

// ─── Legend ───────────────────────────────────────────────────────────────────
function updateMapLegend(jnskw, activeCount) {
    const legend = document.getElementById('map-legend');
    if (!legend) return;
    const colors = MAP_COLORS[jnskw] || MAP_COLORS.KI;
    const label  = jnskw === 'KEK' ? 'KEK' : 'KI';
    legend.innerHTML = `
        <div class="legend-item">
            <span class="legend-dot" style="background:${colors.active}"></span>
            <span>Ada ${label} (${activeCount} Kab/Kota)</span>
        </div>
        <div class="legend-item">
            <span class="legend-dot" style="background:#CBD5E1; opacity:0.4"></span>
            <span>Tanpa kawasan</span>
        </div>
    `;
}

// ─── Loading overlay ──────────────────────────────────────────────────────────
function setMapLoading(active) {
    const overlay = document.getElementById('map-loading');
    if (overlay) overlay.classList.toggle('active', active);
}

// ─── Resize ───────────────────────────────────────────────────────────────────
function resizeMap() {
    if (kawasanMap) setTimeout(() => kawasanMap.invalidateSize(), 100);
}

// ─── Util ─────────────────────────────────────────────────────────────────────
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
