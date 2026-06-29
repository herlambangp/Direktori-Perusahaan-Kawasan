// app.js

'use strict';

let currentJnskw  = '';
let directoryData = [];
let mapPanelOpen  = true;
let isAdmin       = false;   // diisi oleh checkAuthStatus()

// ─── View Navigation ──────────────────────────────────────────────────────────

function openDirectory(jnskw) {
    currentJnskw = jnskw;

    document.getElementById('menu-view').classList.replace('active', 'hidden');
    document.getElementById('directory-view').classList.replace('hidden', 'active');

    document.getElementById('dir-title').innerText =
        jnskw === 'KEK' ? 'Direktori Kawasan Ekonomi Khusus' : 'Direktori Kawasan Industri';

    document.getElementById('search-input').value = '';
    document.getElementById('search-clear').style.display = 'none';

    loadData();
}

function goBack() {
    document.getElementById('directory-view').classList.replace('active', 'hidden');
    document.getElementById('menu-view').classList.replace('hidden', 'active');
}

// ─── Toggle Map Panel ─────────────────────────────────────────────────────────

function toggleMapPanel() {
    const body    = document.getElementById('map-body');
    const iconEl  = document.getElementById('map-toggle-icon');
    mapPanelOpen  = !mapPanelOpen;
    body.classList.toggle('collapsed', !mapPanelOpen);
    iconEl.className = mapPanelOpen ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
    if (mapPanelOpen) resizeMap();
}

// ─── Fetch Tree Data ──────────────────────────────────────────────────────────

async function loadData() {
    const treeRoot = document.getElementById('tree-root');
    const loading  = document.getElementById('loading-spinner');

    treeRoot.innerHTML = '';
    loading.classList.add('active');

    try {
        const response = await fetch(`api.php?action=get_tree&jnskw=${currentJnskw}`);
        const result   = await response.json();

        if (result.status === 'success') {
            directoryData = result.data;

            setTimeout(() => {
                renderTree(directoryData, treeRoot);
                loading.classList.remove('active');
            }, 50);

            // Inisialisasi peta secara paralel
            initMap(currentJnskw);

        } else {
            loading.classList.remove('active');
            treeRoot.innerHTML = `<p class="empty-msg"><i class="fas fa-exclamation-circle"></i> Gagal memuat data: ${result.message}</p>`;
        }
    } catch (error) {
        loading.classList.remove('active');
        treeRoot.innerHTML = `<p class="empty-msg"><i class="fas fa-wifi"></i> Error koneksi: ${error.message}</p>`;
    }
}

// ─── Render Tree ──────────────────────────────────────────────────────────────

function renderTree(data, container) {
    container.innerHTML = '';

    if (!data || data.length === 0) {
        container.innerHTML = '<p class="empty-msg"><i class="fas fa-inbox"></i> Tidak ada data ditemukan.</p>';
        return;
    }

    const fragment = document.createDocumentFragment();
    data.forEach(node => fragment.appendChild(createNodeElement(node)));
    container.appendChild(fragment);
}

function createNodeElement(node) {
    const el = document.createElement('div');
    el.className   = 'tree-node';
    el.dataset.id  = node.id;
    el.dataset.type = node.type;

    const isCompany = node.type === 'perusahaan';

    // Node content row
    const content = document.createElement('div');
    content.className = `node-content ${isCompany ? 'company-item' : ''}`;

    if (!isCompany) {
        content.dataset.dropTarget = 'true';
        content.dataset.nodeData   = JSON.stringify({
            id:         node.id,
            type:       node.type,
            name:       node.name,
            kdprov:     node.kdprov,
            kdkab:      node.kdkab,
            kdprovkab:  node.kdprovkab
        });
    }

    // Toggle icon
    if (!isCompany) {
        const toggle = document.createElement('i');
        toggle.className = 'fas fa-chevron-right toggle-icon';
        content.appendChild(toggle);
    } else {
        const spacer = document.createElement('div');
        spacer.style.width = '34px';
        content.appendChild(spacer);
    }

    // Icon
    const icon = document.createElement('div');
    icon.className = `node-icon icon-${node.type}`;
    const iconMap = { prov: 'fa-map', kab: 'fa-city', kawasan: 'fa-industry', perusahaan: 'fa-building' };
    icon.innerHTML = `<i class="fas ${iconMap[node.type] || 'fa-circle'}"></i>`;
    content.appendChild(icon);

    // Title
    const title = document.createElement('div');
    title.className = 'node-title';
    title.innerText = node.name;
    content.appendChild(title);

    // Count badge (for non-company)
    if (!isCompany && node.children) {
        const count = document.createElement('div');
        count.className = 'node-count';
        count.innerText = countCompanies(node) + ' perusahaan';
        content.appendChild(count);
    }

    el.appendChild(content);

    // ── Company tooltip (muncul saat klik nama perusahaan) ───────────────────
    if (isCompany) {
        // Tombol info kecil di sebelah kanan nama
        const infoBtn = document.createElement('button');
        infoBtn.className = 'company-info-btn';
        infoBtn.title     = 'Lihat detail perusahaan';
        infoBtn.innerHTML = '<i class="fas fa-info-circle"></i>';
        content.appendChild(infoBtn);

        // Bangun konten tooltip
        const fields = [
            { icon: 'fa-id-badge',      label: 'ID STPU',       val: node.idstpu },
            { icon: 'fa-map-marker-alt',label: 'Alamat',         val: node.alamat },
            { icon: 'fa-user',           label: 'Korespondensi', val: node.nmkorespondensi },
            { icon: 'fa-phone',          label: 'No. Kontak',    val: node.nohp },
            { icon: 'fa-envelope',       label: 'Email',         val: node.email },
            { icon: 'fa-sitemap',        label: 'Jar. Usaha',    val: node.jarusaha },
            { icon: 'fa-industry',       label: 'KBLI',          val: node.kbli },
        ];

        const rows = fields.map(f => {
            const v = (f.val && String(f.val).trim()) ? String(f.val).trim() : '—';
            const isEmail = f.label === 'Email' && v !== '—';
            const isPhone = f.label === 'No. Kontak' && v !== '—';
            const valHtml = isEmail ? `<a href="mailto:${v}">${v}</a>`
                          : isPhone ? `<a href="tel:${v}">${v}</a>`
                          : v;
            return `<div class="ttp-row">
                      <span class="ttp-label"><i class="fas ${f.icon}"></i> ${f.label}</span>
                      <span class="ttp-val">${valHtml}</span>
                    </div>`;
        }).join('');

        const tooltip = document.createElement('div');
        tooltip.className = 'company-tooltip';
        tooltip.innerHTML = `
            <div class="ttp-header">
                <i class="fas fa-building"></i>
                <span>${node.name}</span>
                <button class="ttp-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="ttp-body">${rows}</div>`;
        el.appendChild(tooltip);

        // Toggle tooltip saat klik tombol info atau nama perusahaan
        const toggleTooltip = (e) => {
            e.stopPropagation();
            // Tutup tooltip lain yang terbuka
            document.querySelectorAll('.company-tooltip.open').forEach(t => {
                if (t !== tooltip) t.classList.remove('open');
            });
            tooltip.classList.toggle('open');
        };

        infoBtn.addEventListener('click', toggleTooltip);
        // Klik di luar tooltip → tutup
        tooltip.querySelector('.ttp-close').addEventListener('click', (e) => {
            e.stopPropagation();
            tooltip.classList.remove('open');
        });
    }

    // Children
    if (!isCompany && node.children && node.children.length > 0) {
        const childrenContainer = document.createElement('div');
        childrenContainer.className = 'children-container';

        const frag = document.createDocumentFragment();
        node.children.forEach(child => frag.appendChild(createNodeElement(child)));
        childrenContainer.appendChild(frag);
        el.appendChild(childrenContainer);

        content.addEventListener('click', () => {
            content.classList.toggle('expanded');
            childrenContainer.classList.toggle('active');
        });
    }

    // Drag & drop — hanya untuk admin
    if (isCompany && isAdmin) {
        content.draggable = true;
        content.addEventListener('dragstart', handleDragStart);
        content.addEventListener('dragend',   handleDragEnd);
    }
    if (isAdmin) {
        content.addEventListener('dragover',  handleDragOver);
        content.addEventListener('dragleave', handleDragLeave);
        content.addEventListener('drop',      handleDrop);
    }

    return el;
}

function countCompanies(node) {
    if (node.type === 'perusahaan') return 1;
    if (!node.children) return 0;
    if (node.type === 'kawasan') return node.children.length;
    return node.children.reduce((sum, c) => sum + countCompanies(c), 0);
}

// ─── Drag and Drop ────────────────────────────────────────────────────────────

let draggedCompanyId = null;
let draggedCompanyEl = null;

function handleDragStart(e) {
    draggedCompanyId = this.parentElement.dataset.id;
    draggedCompanyEl = this.parentElement;
    e.dataTransfer.effectAllowed = 'move';
    this.style.opacity = '0.5';
}

function handleDragEnd(e) {
    this.style.opacity = '1';
    document.querySelectorAll('.node-content').forEach(el => el.classList.remove('drag-over'));
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    this.classList.add('drag-over');
}

function handleDragLeave(e) {
    this.classList.remove('drag-over');
}

async function handleDrop(e) {
    e.preventDefault();
    this.classList.remove('drag-over');
    if (!draggedCompanyId) return;
    if (this === draggedCompanyEl?.querySelector('.node-content')) return;

    try {
        let targetNodeEl = this.parentElement;
        let targetData;

        if (this.classList.contains('company-item')) {
            const kawasanContainer = targetNodeEl.closest('.children-container').parentElement;
            targetData   = JSON.parse(kawasanContainer.querySelector('.node-content').dataset.nodeData);
            targetNodeEl = kawasanContainer;
        } else {
            targetData = JSON.parse(this.dataset.nodeData);
        }

        if (!confirm(`Pindahkan perusahaan ini ke: ${targetData.name}?`)) return;

        let payload = {
            action:     'move_company',
            company_id: draggedCompanyId,
            target_type: targetData.type
        };

        if (targetData.type === 'kawasan') {
            payload.new_nmkw = targetData.name;
            const kabContainer = targetNodeEl.closest('.children-container').parentElement;
            const kabData = JSON.parse(kabContainer.querySelector('.node-content').dataset.nodeData);
            payload.new_kdkab    = kabData.kdkab;
            payload.new_nmkab    = kabData.name;
            payload.new_kdprovkab = kabData.kdprovkab;
            const provContainer = kabContainer.closest('.children-container').parentElement;
            const provData = JSON.parse(provContainer.querySelector('.node-content').dataset.nodeData);
            payload.new_kdprov = provData.id;
            payload.new_nmprov = provData.name;
        } else if (targetData.type === 'kab') {
            payload.new_kdkab    = targetData.kdkab;
            payload.new_nmkab    = targetData.name;
            payload.new_kdprovkab = targetData.kdprovkab;
            const provContainer = targetNodeEl.closest('.children-container').parentElement;
            const provData = JSON.parse(provContainer.querySelector('.node-content').dataset.nodeData);
            payload.new_kdprov = provData.id;
            payload.new_nmprov = provData.name;
        } else if (targetData.type === 'prov') {
            payload.new_kdprov = targetData.id;
            payload.new_nmprov = targetData.name;
        }

        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();

        if (result.status === 'success') {
            showToast('Berhasil memindahkan perusahaan!');
            loadData();
        } else {
            showToast('Gagal memindahkan: ' + result.message, true);
        }
    } catch (err) {
        console.error(err);
        showToast('Terjadi kesalahan saat memindahkan.', true);
    }
}

// ─── Toast ────────────────────────────────────────────────────────────────────

function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.innerText = message;
    toast.classList.toggle('error', isError);
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

// ─── Search ───────────────────────────────────────────────────────────────────

const searchInput = document.getElementById('search-input');
const searchClear = document.getElementById('search-clear');

if (searchInput) {
    searchInput.addEventListener('input', function (e) {
        const term = e.target.value.trim();
        searchClear.style.display = term ? 'flex' : 'none';
        filterTree(document.getElementById('tree-root'), term.toLowerCase());
    });
}

function clearSearch() {
    searchInput.value = '';
    searchClear.style.display = 'none';
    filterTree(document.getElementById('tree-root'), '');
}

function filterTree(container, term) {
    if (!term) {
        container.querySelectorAll('.tree-node').forEach(n => {
            n.style.display = 'block';
            n.querySelector('.node-content')?.classList.remove('expanded');
            n.querySelector('.children-container')?.classList.remove('active');
        });
        return;
    }

    container.querySelectorAll('.tree-node').forEach(node => {
        const title = node.querySelector('.node-title')?.innerText.toLowerCase() || '';
        if (title.includes(term)) {
            node.style.display = 'block';
            // Expand all ancestors
            let parent = node.parentElement?.closest('.tree-node');
            while (parent) {
                parent.style.display = 'block';
                parent.querySelector('.node-content')?.classList.add('expanded');
                parent.querySelector('.children-container')?.classList.add('active');
                parent = parent.parentElement?.closest('.tree-node');
            }
        } else {
            const hasMatch = [...node.querySelectorAll('.node-title')]
                .some(el => el.innerText.toLowerCase().includes(term));
            node.style.display = hasMatch ? 'block' : 'none';
        }
    });
}

// ─── Summary Stats ────────────────────────────────────────────────────────────

async function loadSummary() {
    try {
        const res    = await fetch('api.php?action=get_summary');
        const result = await res.json();

        if (result.status === 'success') {
            const data  = result.data;
            const kekEl = document.getElementById('stats-kek');
            const kiEl  = document.getElementById('stats-ki');

            if (kekEl) kekEl.innerHTML =
                `<i class="fas fa-chart-pie"></i> ${data.KEK.kawasan} Kawasan &nbsp;|&nbsp; ${data.KEK.perusahaan.toLocaleString('id-ID')} Perusahaan`;
            if (kiEl) kiEl.innerHTML =
                `<i class="fas fa-chart-pie"></i> ${data.KI.kawasan} Kawasan &nbsp;|&nbsp; ${data.KI.perusahaan.toLocaleString('id-ID')} Perusahaan`;
        }
    } catch (e) {
        console.error('Load summary error:', e);
        ['stats-kek', 'stats-ki'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerText = 'Gagal memuat info';
        });
    }
}

loadSummary();
checkAuthStatus();
handleAuthRedirect();

// ─── Auth Status ──────────────────────────────────────────────────────────────

async function checkAuthStatus() {
    try {
        const res    = await fetch('api.php?action=get_auth_status');
        const result = await res.json();
        if (result.status === 'success') {
            isAdmin = result.is_admin;
            if (isAdmin) {
                document.querySelectorAll('.admin-only').forEach(el => el.style.display = '');
                const bar  = document.getElementById('admin-bar');
                const nama = document.getElementById('admin-nama');
                if (bar)  bar.style.display  = 'flex';
                if (nama) nama.textContent    = result.nama || result.username || 'Admin';
                // Nascondi il tasto di login se già autenticato
                const ssoBtn = document.getElementById('btn-sso-login');
                if (ssoBtn) ssoBtn.style.display = 'none';
            }
        }
    } catch (e) {
        console.warn('Auth status check failed:', e);
    }
}

// Tampilkan toast berdasarkan parameter ?auth= dari redirect auth.php
function handleAuthRedirect() {
    const params = new URLSearchParams(window.location.search);
    const auth   = params.get('auth');
    const msg    = params.get('msg');
    if (!auth) return;

    // Hapus query string dari URL tanpa reload
    window.history.replaceState({}, '', window.location.pathname);

    if (auth === 'ok') {
        showToast('✓ Login berhasil! Selamat datang, Admin.');
    } else if (auth === 'notadmin') {
        showToast(msg || 'Akun Anda tidak memiliki hak akses admin.', true);
    } else if (auth === 'error') {
        showToast('Login gagal: ' + (msg || 'Terjadi kesalahan SSO.'), true);
    }
}

// Tutup semua tooltip saat klik di luar elemen perusahaan
document.addEventListener('click', (e) => {
    if (!e.target.closest('.tree-node')) {
        document.querySelectorAll('.company-tooltip.open').forEach(t => t.classList.remove('open'));
    }
});

// ─── Modal Tambah Data ────────────────────────────────────────────────────────

let dbOptions = { provs: [], kabs: [], kws: [] };

function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
    document.getElementById('addForm').reset();

    const jnskwSelect = document.getElementById('input-jnskw');
    if (currentJnskw) {
        jnskwSelect.value    = currentJnskw;
        jnskwSelect.disabled = true;
        loadProvOptions();
    } else {
        jnskwSelect.disabled = false;
        ['input-prov', 'input-kab', 'input-kw'].forEach(id => {
            document.getElementById(id).disabled = true;
        });
    }
    document.getElementById('group-new-kw').classList.add('hidden');
}

function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
}

async function loadProvOptions() {
    const jnskw = document.getElementById('input-jnskw').value;
    if (!jnskw) { document.getElementById('input-prov').disabled = true; return; }

    try {
        const res  = await fetch(`api.php?action=get_options&jnskw=${jnskw}`);
        const data = await res.json();
        if (data.status === 'success') {
            dbOptions = data.data;
            const provSelect = document.getElementById('input-prov');
            provSelect.innerHTML = '<option value="">-- Pilih Provinsi --</option>';
            dbOptions.provs.forEach(p => {
                provSelect.innerHTML += `<option value="${p.kdprov}">${p.kdprov} - ${p.nmprov}</option>`;
            });
            provSelect.disabled = false;
            document.getElementById('input-kab').disabled = true;
            document.getElementById('input-kw').disabled  = true;
        }
    } catch (e) {
        showToast('Gagal memuat daftar Provinsi', true);
    }
}

function loadKabOptions() {
    const kdprov    = document.getElementById('input-prov').value;
    const kabSelect = document.getElementById('input-kab');
    kabSelect.innerHTML = '<option value="">-- Pilih Kabupaten --</option>';

    if (!kdprov) { kabSelect.disabled = true; return; }

    dbOptions.kabs
        .filter(k => k.kdprov === kdprov)
        .forEach(k => {
            kabSelect.innerHTML += `<option value="${k.kdkab}" data-kdprovkab="${k.kdprovkab || ''}" data-nmkab="${k.nmkab}">${k.kdkab} - ${k.nmkab}</option>`;
        });
    kabSelect.disabled = false;
    document.getElementById('input-kw').disabled = true;
}

function loadKwOptions() {
    const kdkab   = document.getElementById('input-kab').value;
    const kdprov  = document.getElementById('input-prov').value;
    const kwSelect = document.getElementById('input-kw');
    kwSelect.innerHTML = '<option value="">-- Pilih Kawasan --</option><option value="_NEW_">+ Tambah Kawasan Baru</option>';

    if (!kdkab) { kwSelect.disabled = true; return; }

    dbOptions.kws
        .filter(k => k.kdprov === kdprov && k.kdkab === kdkab)
        .forEach(k => {
            kwSelect.innerHTML += `<option value="${k.nmkw}">${k.nmkw}</option>`;
        });
    kwSelect.disabled = false;
    toggleNewKw();
}

function toggleNewKw() {
    const val       = document.getElementById('input-kw').value;
    const newKwGrp  = document.getElementById('group-new-kw');
    const inputNKw  = document.getElementById('input-new-kw');
    const isNew     = val === '_NEW_';
    newKwGrp.classList.toggle('hidden', !isNew);
    inputNKw.required = isNew;
}

async function submitForm(e) {
    e.preventDefault();

    const jnskw    = document.getElementById('input-jnskw').value;
    const provSel  = document.getElementById('input-prov');
    const kdprov   = provSel.value;
    const nmprov   = provSel.options[provSel.selectedIndex]?.text.split(' - ')[1] || '';

    const kabSel   = document.getElementById('input-kab');
    const kdkab    = kabSel.value;
    const nmkab    = kabSel.options[kabSel.selectedIndex]?.text.split(' - ')[1] || '';
    const kdprovkab = kabSel.options[kabSel.selectedIndex]?.dataset.kdprovkab || null;

    const kwVal    = document.getElementById('input-kw').value;
    const nmkw     = kwVal === '_NEW_' ? document.getElementById('input-new-kw').value : kwVal;
    const nmprsh   = document.getElementById('input-prsh').value;
    const alamat   = document.getElementById('input-alamat').value;

    // Field baru
    const idstpu          = document.getElementById('input-idstpu').value;
    const nmkorespondensi = document.getElementById('input-nmkoresp').value;
    const nohp            = document.getElementById('input-nohp').value;
    const email           = document.getElementById('input-email').value;
    const jarusaha        = document.getElementById('input-jarusaha').value;
    const kbli            = document.getElementById('input-kbli').value;

    const payload = {
        action: 'add_company',
        jnskw, kdprov, nmprov, kdkab, nmkab, kdprovkab, nmkw, nmprsh, alamat,
        idstpu, nmkorespondensi, nohp, email, jarusaha, kbli
    };

    try {
        const btnSave = document.getElementById('btn-save-data');
        btnSave.disabled = true;
        btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();

        if (result.status === 'success') {
            showToast('Berhasil menambahkan data!');
            closeAddModal();
            loadSummary();
            if (!document.getElementById('directory-view').classList.contains('hidden') && currentJnskw === jnskw) {
                loadData();
            }
        } else {
            showToast('Gagal: ' + result.message, true);
        }
    } catch (err) {
        showToast('Terjadi kesalahan koneksi', true);
    } finally {
        const btnSave = document.getElementById('btn-save-data');
        btnSave.disabled = false;
        btnSave.innerHTML = '<i class="fas fa-save"></i> Simpan Data';
    }
}
