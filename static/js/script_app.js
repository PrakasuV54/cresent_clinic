/**
 * Crescent Clinic and Scans — Frontend Logic
 */

// Global fix for toFixed failing on string types returned from database
if (!String.prototype.toFixed) {
    String.prototype.toFixed = function (precision) {
        return (parseFloat(this) || 0).toFixed(precision);
    };
}

(function () {
    'use strict';

    document.addEventListener("DOMContentLoaded", async () => {
        if (window.self !== window.top) {
            // Only hide the logout footer in the embedded view so admin doesn't log out from iframe
            const sidebarFooter = document.querySelector('.sidebar-footer');
            if (sidebarFooter) sidebarFooter.style.display = 'none';
        }

        // Mobile Menu Toggle logic
        const initMobileMenu = () => {
            const sidebar = document.querySelector('.sidebar');
            if (!sidebar) return; // Do not initialize mobile menu toggle if there is no sidebar (e.g. on login page)

            // Insert mobile sidebar page title above the logout button in the footer
            const badgeEl = sidebar.querySelector('.sidebar-header .badge') || sidebar.querySelector('.sidebar-header small') || sidebar.querySelector('.sidebar-header .badge-reception');
            let badgeText = badgeEl ? badgeEl.textContent.trim() : '';
            if (badgeText.toLowerCase().includes('admin') || badgeText.toLowerCase().includes('management')) {
                badgeText = 'Management';
            } else if (badgeText.toLowerCase().includes('reception')) {
                badgeText = 'Reception';
            } else if (badgeText.toLowerCase().includes('pharmacy') || badgeText.toLowerCase().includes('pharmacist')) {
                badgeText = 'Pharmacy';
            } else if (badgeText.toLowerCase().includes('doctor')) {
                badgeText = 'Doctor';
            }

            const footer = sidebar.querySelector('.sidebar-footer');
            if (footer && badgeText && !footer.querySelector('.mobile-sidebar-page-title')) {
                const titleDiv = document.createElement('div');
                titleDiv.className = 'mobile-sidebar-page-title';
                titleDiv.textContent = badgeText;

                const logoutBtn = footer.querySelector('a[href="/logout"]');
                if (logoutBtn) {
                    footer.insertBefore(titleDiv, logoutBtn);
                } else {
                    footer.appendChild(titleDiv);
                }
            }

            if (window.innerWidth <= 768 && !document.querySelector('.mobile-menu-toggle')) {
                const toggleBtn = document.createElement('button');
                toggleBtn.className = 'mobile-menu-toggle';
                toggleBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>';
                document.body.appendChild(toggleBtn);

                toggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    sidebar.classList.toggle('mobile-open');
                });

                document.addEventListener('click', (e) => {
                    if (sidebar.classList.contains('mobile-open') && !sidebar.contains(e.target) && e.target !== toggleBtn && !toggleBtn.contains(e.target)) {
                        sidebar.classList.remove('mobile-open');
                    }
                });

                document.querySelectorAll('.nav-item').forEach(item => {
                    item.addEventListener('click', () => {
                        sidebar.classList.remove('mobile-open');
                    });
                });
            }
        };
        initMobileMenu();
        window.addEventListener('resize', initMobileMenu);

        // Load global UPI accounts - MUST AWAIT to ensure accounts are ready before forms open
        try {
            await window.loadGlobalUpiAccounts();
        } catch (e) {
            console.error('Failed to load UPI accounts initially', e);
        }

        // Auto-clear zero/placeholder values on focus
        document.addEventListener('focus', function (e) {
            if (e.target.tagName === 'INPUT' && (e.target.type === 'number' || e.target.type === 'text')) {
                const val = e.target.value.trim();
                if (val === '0.00' || val === '0' || val === '000' || val === '0.0' || val === '00') {
                    e.target.setAttribute('data-original-placeholder-val', val);
                    e.target.value = '';
                }
            }
        }, true);

        document.addEventListener('blur', function (e) {
            if (e.target.tagName === 'INPUT' && (e.target.type === 'number' || e.target.type === 'text')) {
                if (e.target.value.trim() === '') {
                    const origVal = e.target.getAttribute('data-original-placeholder-val');
                    if (origVal) {
                        e.target.value = origVal;
                    }
                }
            }
        }, true);
    });

    // ═══════════════════════════════════════════
    // LIVE SYNCHRONIZATION
    // ═══════════════════════════════════════════
    window.hospitalSyncChannel = new BroadcastChannel('hospital_live_sync');

    window.hospitalSyncChannel.onmessage = (event) => {
        if (event.data && event.data.type === 'DATA_UPDATED') {
            if (typeof reloadCurrentSection === 'function') {
                reloadCurrentSection();
            }
        }
    };

    window.triggerLiveSync = function () {
        window.hospitalSyncChannel.postMessage({ type: 'DATA_UPDATED' });
    };

    window.reloadCurrentSection = function () {
        if (typeof window.loadPatients === 'function') {
            window.loadPatients();
        }
        if (typeof window.loadDirectSales === 'function') {
            window.loadDirectSales();
        }
        if (typeof window.loadDirectSalesAdmin === 'function') {
            window.loadDirectSalesAdmin();
        }
        if (typeof window.loadAgencyStock === 'function') {
            window.loadAgencyStock();
        }
        if (typeof window.loadPharmacyReturns === 'function') {
            window.loadPharmacyReturns();
        }
        if (typeof window.loadAnalytics === 'function') {
            window.loadAnalytics(null, true);
            window.loadAnalytics(null, false);
        }
        if (typeof window.loadInventory === 'function') {
            window.loadInventory();
        }
        if (typeof window.loadPatientsList === 'function') {
            window.loadPatientsList();
        }
    };

    // ═══════════════════════════════════════════
    // UTILITY HELPERS
    // ═══════════════════════════════════════════
    function $(sel) { return document.querySelector(sel); }
    function $$(sel) { return document.querySelectorAll(sel); }

    window.formatDoctorName = function (name, type) {
        if (!name) return name;
        const suffix = (type && type.toLowerCase().includes('gents')) ? ' (Sir)' : ' (Madam)';
        if (name.includes('(Sir)') || name.includes('(Madam)')) return name;
        return name + suffix;
    }

    window.toast = function (msg, type = 'success', duration = 3500) {
        const c = $('#toastContainer');
        if (!c) return;
        const t = document.createElement('div');
        t.className = `toast toast-${type}`;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, duration);
    }

    window.api = async function (url, opts = {}) {
        const reqMethod = opts.method ? opts.method.toUpperCase() : 'GET';
        if (reqMethod === 'GET') {
            const sep = url.includes('?') ? '&' : '?';
            url += sep + '_t=' + Date.now();
        }
        if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
            opts.headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
            opts.body = JSON.stringify(opts.body);
        }
        
        if (reqMethod !== 'GET') {
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                opts.headers = { 'X-CSRF-Token': csrfMeta.getAttribute('content'), ...(opts.headers || {}) };
            }
        }
        const res = await fetch(url, opts);
        if (!res.ok) {
            let errMsg = `HTTP ${res.status}`;
            try {
                const errData = await res.json();
                if (errData.error) errMsg = errData.error;
            } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
            throw new Error(errMsg);
        }
        const data = await res.json();

        // Broadcast sync event if this was a mutation
        const method = opts.method ? opts.method.toUpperCase() : 'GET';
        if (method === 'POST' || method === 'PUT' || method === 'DELETE') {
            window.triggerLiveSync();
        }

        return data;
    }

    // Load global UPI accounts
    window.globalUpiAccounts = [];
    window.loadGlobalUpiAccounts = async function () {
        try {
            const upiRes = await api('/api/upi_accounts');
            window.globalUpiAccounts = upiRes.filter(a => a.is_active == 1);

            const populateDropdown = (id) => {
                const el = document.getElementById(id);
                if (!el) {
                    return;
                }
                const optionsHtml = window.globalUpiAccounts.map(a => `<option value="${a.short_name || a.account_name}">${a.account_name} ${a.short_name ? `(${a.short_name})` : ''}</option>`).join('');
                el.innerHTML = '<option value="">Select Account</option>' + optionsHtml;
                if (window.globalUpiAccounts.length > 0) {
                    console.log(`[UPI Accounts] Populated #${id} with ${window.globalUpiAccounts.length} account(s)`);
                } else {
                    console.warn(`[UPI Accounts] No active accounts to populate in #${id}`);
                }
            };

            populateDropdown('payUpiAccount');
            populateDropdown('agPayUpiAccount');
            populateDropdown('rmRefundUpiAccount');
            populateDropdown('ppUpiAccount');
            populateDropdown('clearPrevBalanceUpiAccount');
        } catch (e) {
            console.error('Failed to load UPI accounts', e);
        }
    };

    // ═══════════════════════════════════════════
    // SECTION NAVIGATION
    // ═══════════════════════════════════════════
    window.showSection = function (name) {
        $$('.section').forEach(s => s.classList.remove('active'));
        $$('.nav-item').forEach(n => n.classList.remove('active'));
        const secId = 'section' + name.charAt(0).toUpperCase() + name.slice(1);
        const sec = document.getElementById(secId);
        if (sec) sec.classList.add('active');
        // Activate matching nav
        const navId = 'nav' + name.charAt(0).toUpperCase() + name.slice(1);
        const nav = document.getElementById(navId);
        if (nav) nav.classList.add('active');
        // Reload data when switching
        if (name === 'patients' || name === 'queue' || name === 'prescriptions') {
            loadPatients().then(() => window.scrollTo(0, 0));
        } else {
            window.scrollTo(0, 0);
        }
    };



    // ═══════════════════════════════════════════
    // MODAL HELPERS
    // ═══════════════════════════════════════════
    window.closeModal = function (id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('active');
    };

    window.openModal = function (id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('active');
    };

    // ═══════════════════════════════════════════
    // DETECT PAGE ROLE
    // ═══════════════════════════════════════════
    const path = window.location.pathname;
    const qs = new URLSearchParams(window.location.search);
    const mod = qs.get('module');

    let PAGE_ROLE = 'login';
    if (path.includes('receptionist') || mod === 'receptionist') PAGE_ROLE = 'receptionist';
    else if (path.includes('doctor') || mod === 'doctor') PAGE_ROLE = 'doctor';
    else if (path.includes('pharmacy') || mod === 'pharmacy') PAGE_ROLE = 'pharmacist';
    else if (path.includes('management') || path.includes('admin')) PAGE_ROLE = 'management';
    else PAGE_ROLE = 'login'; // Default to login for any other path (like / or /login)

    // ═══════════════════════════════════════════
    // LOGIN PAGE LOGIC
    // ═══════════════════════════════════════════
    if (PAGE_ROLE === 'login') {
        window.selectRole = function (role, type = '', username = '') { };
        window.showRoles = function () { };

        document.addEventListener('DOMContentLoaded', function () {
            const usernameField = $('#username');
            if (usernameField) {
                usernameField.focus();
            }
        });
    }

    // ═══════════════════════════════════════════
    // RECEPTIONIST LOGIC
    // ═══════════════════════════════════════════
    const medDataCache = {}; // Cache for medicine info (price, tps)

    if (PAGE_ROLE === 'receptionist') {
        window.currentPatients = []; // Store today's patients

        window.updateTokenGrid = function () {
            const tokenGrid = $('#tokenGrid');
            if (!tokenGrid || !window.currentPatients) return;

            const doctorType = $('#patDoctor') ? $('#patDoctor').value : '';
            if (!doctorType) {
                // If no doctor selected, clear used status
                $$('.btn-token').forEach(btn => {
                    btn.classList.remove('btn-token-used');
                    btn.disabled = false;
                    btn.title = '';
                });
                return;
            }

            // Determine today's date in local time string 'YYYY-MM-DD'
            const today = new Date();
            const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

            // Find used tokens for the currently selected doctor today
            const usedTokens = window.currentPatients
                .filter(p => String(p.doctor_id) === String(doctorType) && p.created_at.startsWith(todayStr))
                .map(p => {
                    const parts = p.token.split('-');
                    return parts.length > 1 ? parseInt(parts[1], 10) : -1;
                });

            $$('.btn-token').forEach(btn => {
                const val = parseInt(btn.dataset.val, 10);
                if (usedTokens.includes(val)) {
                    btn.classList.add('btn-token-used');
                    btn.disabled = true;
                    btn.title = 'Already Assigned';
                    btn.classList.remove('selected');
                    if ($('#patManualToken') && $('#patManualToken').value == val) {
                        $('#patManualToken').value = '';
                    }
                } else {
                    btn.classList.remove('btn-token-used');
                    btn.disabled = false;
                    btn.title = '';
                }
            });
        };

        // Load dynamic doctors for the dropdown
        document.addEventListener('DOMContentLoaded', async function () {
            const doctorSel = $('#patDoctor');
            if (doctorSel) {
                try {
                    const doctors = await api('/api/doctors');
                    window.allDoctors = doctors;
                    doctorSel.innerHTML = '<option value="">Select Doctor</option>' +
                        doctors.map(d => `<option value="${d.id}">${formatDoctorName(d.display_name, d.doctor_type)}</option>`).join('');
                } catch (e) {
                    console.error('Failed to load doctors', e);
                }

                doctorSel.addEventListener('change', function () {
                    if (window.updateTokenGrid) window.updateTokenGrid();
                });
            }

            // Load global UPI accounts
            await window.loadGlobalUpiAccounts();

            // Prevent Enter key from submitting the registration form
            const registerForm = $('#registerForm');
            if (registerForm) {
                registerForm.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                        e.preventDefault();
                    }
                });
            }

            // Quick fetch on Enter
            const fetchPhoneInput = $('#fetchPhone');
            if (fetchPhoneInput) {
                fetchPhoneInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        fetchPatient();
                    }
                });
            }

            // Initialize token grid
            const tokenGrid = $('#tokenGrid');
            if (tokenGrid) {
                let gridHtml = '';
                for (let i = 1; i <= 100; i++) {
                    gridHtml += `<button type="button" class="btn-token" data-val="${i}">${i}</button>`;
                }
                tokenGrid.innerHTML = gridHtml;

                $$('.btn-token').forEach(btn => {
                    btn.addEventListener('click', function () {
                        if (this.classList.contains('btn-token-used') || this.disabled) return;
                        $$('.btn-token').forEach(b => b.classList.remove('selected'));
                        this.classList.add('selected');
                        $('#patManualToken').value = this.dataset.val;
                    });
                });
            }

            loadPatients();
        });

        window.clearSelectedToken = function () {
            $$('.btn-token').forEach(b => b.classList.remove('selected'));
            const input = $('#patManualToken');
            if (input) input.value = '';
        }

        window.registerPatient = async function (e) {
            e.preventDefault();
            const data = {
                name: $('#patName').value.trim(),
                age: parseInt($('#patAge').value),
                gender: $('#patGender').value,
                phone: $('#patPhone').value.trim(),
                address: $('#patAddress').value.trim(),
                doctor_id: $('#patDoctor').value,
                complaint: $('#patComplaint').value.trim(),
                bp: $('#patBP').value.trim(),
                temp: $('#patTemp').value.trim(),
                pulse: $('#patPulse').value.trim(),
                weight: $('#patWeight').value.trim(),
                height: $('#patHeight').value.trim(),
                spo2: $('#patSpO2') && $('#patSpO2').value ? parseInt($('#patSpO2').value) : null,
                manual_token: $('#patManualToken') ? $('#patManualToken').value : ''
            };

            if (!data.name || !data.phone || !data.gender || !data.doctor_id) {
                toast('Please fill all required fields', 'error');
                return;
            }

            try {
                // Modified api utility usage: we need to handle non-ok manually to parse custom messages
                const resRaw = await fetch('/api/register_patient', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify(data)
                });
                const res = await resRaw.json();

                if (resRaw.ok && res.success) {
                    toast('Patient registered successfully!');
                    // Show token
                    const tr = $('#tokenResult');
                    if (tr) {
                        tr.classList.remove('hidden');
                        $('#tokenValue').textContent = res.token;
                        $('#tokenDoctor').textContent = 'Assigned to: ' + res.doctor_name;
                        // Scroll to token section
                        tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    // Reset form
                    $('#registerForm').reset();
                    clearSelectedToken();
                    loadPatients();
                } else {
                    toast(res.message || 'Registration failed', 'error');
                }
            } catch (err) {
                toast('Registration failed: ' + err.message, 'error');
            }
        };

        window.fetchPatient = async function () {
            const phone = $('#fetchPhone').value.trim();
            if (!phone) return toast('Enter a phone number', 'error');
            const resultsDiv = $('#fetchResults');
            const resultsList = $('#fetchResultsList');
            if (resultsDiv) resultsDiv.style.display = 'none';

            try {
                const res = await api('/api/fetch_patient/' + encodeURIComponent(phone));
                if (res.found) {
                    if (res.patients.length === 1) {
                        applyPatientData(res.patients[0]);
                    } else {
                        // Show selection list
                        if (resultsDiv && resultsList) {
                            resultsList.innerHTML = res.patients.map((p, i) => `
                                <div class="stat-card" style="padding:12px; cursor:pointer; flex-direction:row; align-items:center; justify-content:space-between; margin:0; border-color:var(--border);" onclick="applyPatientDataByIndex(${i})">
                                    <div style="display:flex; flex-direction:column; gap:2px;">
                                        <div style="font-weight:600; font-family:var(--font-heading);">${p.name}</div>
                                        <div style="font-size:0.75rem; color:var(--text-muted);">${p.age} yrs • ${p.gender}</div>
                                    </div>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                </div>
                            `).join('');
                            resultsDiv.style.display = 'block';
                            window.fetchedPatients = res.patients; // Store globally for index access
                            toast(`Found ${res.patients.length} patients`);
                        }
                    }
                } else {
                    toast('No patient found with that phone', 'error');
                }
            } catch (err) {
                toast('Fetch failed', 'error');
            }
        };

        window.applyPatientDataByIndex = function (index) {
            if (window.fetchedPatients && window.fetchedPatients[index]) {
                applyPatientData(window.fetchedPatients[index]);
                const resultsDiv = $('#fetchResults');
                if (resultsDiv) resultsDiv.style.display = 'none';
            }
        };

        function applyPatientData(p) {
            // Only fill basic identity details
            $('#patName').value = p.name || '';
            $('#patAge').value = p.age || '';
            $('#patGender').value = p.gender || '';

            // Format phone to strip leading zeros if any
            let phoneVal = p.phone ? String(p.phone).trim() : '';
            if (phoneVal.startsWith('0')) {
                phoneVal = phoneVal.replace(/^0+/, '');
            }
            $('#patPhone').value = phoneVal;

            $('#patAddress').value = p.address || '';

            // Explicitly clear medical data (vitals & issues) for the new visit
            $('#patComplaint').value = '';
            $('#patBP').value = '';
            $('#patTemp').value = '';
            $('#patPulse').value = '';
            $('#patWeight').value = '';
            $('#patHeight').value = '';
            if ($('#patSpO2')) $('#patSpO2').value = '';

            toast('Patient data loaded');
        }
    }

    // ═══════════════════════════════════════════
    // DOCTOR LOGIC
    // ═══════════════════════════════════════════
    let currentPrescribePatientId = null;

    if (PAGE_ROLE === 'doctor') {
        document.addEventListener('DOMContentLoaded', function () {
            loadPatients();
        });
    }

    function renderDoctorQueue(patients) {
        patients.sort((a, b) => {
            if (a.status === 'waiting' && b.status !== 'waiting') return -1;
            if (a.status !== 'waiting' && b.status === 'waiting') return 1;
            return 0;
        });
        const container = $('#patientQueue');
        const empty = $('#emptyQueue');
        if (!container) return;

        if (patients.length === 0) {
            container.innerHTML = '';
            if (empty) empty.style.display = '';
            return;
        }
        if (empty) empty.style.display = 'none';

        container.innerHTML = patients.map(p => {
            const statusClass = p.status === 'waiting' ? 'badge-waiting'
                : p.status === 'prescribed' ? 'badge-consulted' : 'badge-completed';
            const displayStatus = p.status === 'prescribed' ? 'Consulted' : p.status;
            return `
            <div class="patient-card" onclick="openPrescribe(${p.id})">
                <div class="pc-info">
                    <div class="pc-token">${p.token}</div>
                    <div>
                        <div class="pc-name">${p.name}</div>
                        <div class="pc-meta">ID: ${p.patient_id || '-'}</div>
                        <div class="pc-meta">${p.age} yrs • ${p.gender} • ${p.phone}</div>
                        <div class="pc-meta">${p.complaint || 'No complaint noted'}</div>
                    </div>
                </div>
                <div>
                    <span class="badge ${statusClass}" style="text-transform: capitalize;">${displayStatus}</span>
                </div>
            </div>
        `;
        }).join('');
    }

    window.openPrescribe = async function (pid) {
        currentPrescribePatientId = pid;
        try {
            const p = await api('/api/patient/' + pid);
            $('#modalPatientName').textContent = p.name + ' (' + p.token + ')';
            let spo2Html = '';
            if (p.spo2) {
                let color = 'var(--text-primary)';
                if (p.spo2 >= 95) color = '#22c55e'; // Green
                else if (p.spo2 >= 90) color = '#f59e0b'; // Orange
                else color = '#ef4444'; // Red
                spo2Html = `<div class="detail-item"><div class="label">SpO2</div><div class="value" style="color: ${color}; font-weight: 600;">${p.spo2}%</div></div>`;
            }

            $('#modalPatientDetails').innerHTML = `
                <div class="detail-item"><div class="label">Age / Gender</div><div class="value">${p.age} / ${p.gender}</div></div>
                <div class="detail-item"><div class="label">Phone</div><div class="value">${p.phone}</div></div>
                <div class="detail-item"><div class="label">Complaint</div><div class="value">${p.complaint || '-'}</div></div>
                <div class="detail-item"><div class="label">Doctor</div><div class="value">${p.doctor_name}</div></div>
                <div class="detail-item"><div class="label">BP</div><div class="value">${p.bp || '-'}</div></div>
                <div class="detail-item"><div class="label">Temp</div><div class="value">${p.temp || '-'}</div></div>
                <div class="detail-item"><div class="label">Pulse</div><div class="value">${p.pulse || '-'}</div></div>
                <div class="detail-item"><div class="label">Weight / Height</div><div class="value">${p.weight || '-'} / ${p.height || '-'}</div></div>
                ${spo2Html}
            `;

            const isWaiting = p.status === 'waiting';

            // Pre-fill inputs
            $('#feeInput').value = p.consultation_fee !== null ? p.consultation_fee : 0;
            $('#scanFeeInput').value = p.scan_fee !== null ? p.scan_fee : 0;
            if ($('#prescriptionInput')) $('#prescriptionInput').value = p.prescription_text || '';
            if ($('#uptCardInput')) $('#uptCardInput').checked = p.upt_card ? true : false;

            // Conditional Fee Visibility based on Doctor Type
            if (typeof DOCTOR_TYPE !== 'undefined' && DOCTOR_TYPE !== 'Lady') {
                if ($('#scanFeeContainer')) $('#scanFeeContainer').style.display = 'none';
                if ($('#uptContainer')) $('#uptContainer').style.display = 'none';
                $('#scanFeeInput').value = 0;
                if ($('#uptCardInput')) $('#uptCardInput').checked = false;
            } else {
                if ($('#uptContainer')) $('#uptContainer').style.display = 'flex';
            }

            if ($('#checkInjection')) {
                if (p.injection_details) {
                    $('#checkInjection').checked = true;
                    $('#inputInjectionContainer').style.display = 'block';
                    $('#inputInjection').value = p.injection_details;
                } else {
                    $('#checkInjection').checked = false;
                    $('#inputInjectionContainer').style.display = 'none';
                    $('#inputInjection').value = '';
                }
                $('#checkInjection').disabled = !isWaiting;
                if ($('#inputInjection')) $('#inputInjection').disabled = !isWaiting;
            }


            // Disable inputs if already approved
            $('#feeInput').disabled = !isWaiting;
            $('#scanFeeInput').disabled = !isWaiting;
            if ($('#prescriptionInput')) $('#prescriptionInput').disabled = !isWaiting;
            if ($('#uptCardInput')) $('#uptCardInput').disabled = !isWaiting;

            if ($('#uptContainer')) {
                $('#uptContainer').style.display = DOCTOR_TYPE === 'Lady' ? 'flex' : 'none';
            }
            if ($('#labelCheckScan')) {
                $('#labelCheckScan').style.display = DOCTOR_TYPE === 'Lady' ? 'inline-flex' : 'none';
            }
            if ($('#checkScan')) {
                if (p.scan_type || p.scan_fee > 0 || p.scan_notes) {
                    $('#checkScan').checked = true;
                    $('#scanFeeContainer').style.display = 'block';
                } else {
                    $('#checkScan').checked = false;
                    $('#scanFeeContainer').style.display = 'none';
                }
                $('#checkScan').disabled = !isWaiting;
            }

            // Toggle buttons and message
            const btn = $('#btnPrescribe');
            const msg = $('#alreadyApprovedMsg');
            if (btn) btn.style.display = isWaiting ? 'flex' : 'none';
            if (msg) {
                msg.style.display = isWaiting ? 'none' : 'flex';
                if (!isWaiting) {
                    const displayStatus = p.status === 'prescribed' ? 'Consulted' : p.status.charAt(0).toUpperCase() + p.status.slice(1);
                    msg.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px;"><polyline points="20 6 9 17 4 12"/></svg> Consultation already completed (${displayStatus})`;
                }
            }

            openModal('prescribeModal');
        } catch (err) {
            toast('Could not load patient', 'error');
        }
    };
    window.toggleTreatmentInput = function (type) {
        if (type === 'Injection') {
            const checked = $('#checkInjection').checked;
            $('#inputInjectionContainer').style.display = checked ? 'block' : 'none';
            if (!checked) $('#inputInjection').value = '';
        } else if (type === 'Scan') {
            const checked = $('#checkScan').checked;
            $('#scanFeeContainer').style.display = checked ? 'block' : 'none';
            if (!checked) {
                if ($('#inputScanName')) $('#inputScanName').value = '';
                if ($('#scanFeeInput')) $('#scanFeeInput').value = '0';
                if ($('#inputScanNotes')) $('#inputScanNotes').value = '';
            }
        }
    };
    window.submitPrescription = async function () {
        if (!currentPrescribePatientId) return;
        const prescription_text = $('#prescriptionInput') ? $('#prescriptionInput').value.trim() : '';
        const consultation_fee = parseFloat($('#feeInput').value) || 0;
        const scan_fee = parseFloat($('#scanFeeInput').value) || 0;

        const scan_type = $('#inputScanName') ? $('#inputScanName').value.trim() : '';
        const scan_notes = $('#inputScanNotes') ? $('#inputScanNotes').value.trim() : '';

        const formData = new FormData();
        formData.append('patient_id', currentPrescribePatientId);
        formData.append('diagnosis', '');
        formData.append('prescription_text', prescription_text);
        formData.append('consultation_fee', consultation_fee);
        if ($('#checkScan') && $('#checkScan').checked) {
            formData.append('scan_fee', scan_fee);
            formData.append('scan_type', scan_type === '' ? ' ' : scan_type);
            formData.append('scan_notes', scan_notes);
        } else {
            formData.append('scan_fee', 0);
            formData.append('scan_type', '');
            formData.append('scan_notes', '');
        }

        if ($('#checkInjection') && $('#checkInjection').checked) {
            let val = $('#inputInjection').value.trim();
            formData.append('injection_details', val === '' ? ' ' : val);
            formData.append('injection_cost', $('#doctorInjectionCost') ? parseFloat($('#doctorInjectionCost').value) || 0 : 0);
        } else {
            formData.append('injection_details', '');
            formData.append('injection_cost', 0);
        }
        formData.append('iv_details', '');
        formData.append('iv_cost', 0);

        if ($('#uptCardInput')) {
            formData.append('upt_card', $('#uptCardInput').checked ? '1' : '0');
        }

        if ($('#prescriptionPhoto') && $('#prescriptionPhoto').files[0]) {
            formData.append('prescription_photo', $('#prescriptionPhoto').files[0]);
        }

        try {
            const res = await api('/api/prescribe', {
                method: 'POST',
                body: formData
            });
            if (res.success) {
                toast('Prescription saved!');
                closeModal('prescribeModal');
                loadPatients();
            }
        } catch (err) {
            toast('Failed to save prescription', 'error');
        }
    };

    // ═══════════════════════════════════════════
    // PHARMACIST LOGIC
    // ═══════════════════════════════════════════
    let currentPrescId = null;

    if (PAGE_ROLE === 'pharmacist') {
        window.showPharmacySection = function (id, btn) {
            document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
            const target = document.getElementById('section' + id);
            if (target) target.classList.add('active');

            document.querySelectorAll('.sidebar-nav .nav-item').forEach(el => el.classList.remove('active'));
            if (btn) btn.classList.add('active');

            if (id === 'Prescriptions') {
                if (btn && btn.id === 'navFilterAll') {
                    setPharmacyFilter('All', btn);
                } else if (window.currentPharmacyFilter) {
                    setPharmacyFilter(window.currentPharmacyFilter, null);
                } else {
                    setPharmacyFilter('All', btn);
                }
            }
            if (id === 'DirectSale') {
                if (window.loadDirectSales) window.loadDirectSales();
                if (window.openDirectPharmacy) openDirectPharmacy();
            }
            if (id === 'Inventory') {
                if (typeof window.loadInventory === 'function') window.loadInventory();
            }
            if (id === 'Agency') {
                if (typeof window.loadAgencyStock === 'function') window.loadAgencyStock();
            }
        };

        window.currentPharmacyFilter = 'All';
        window.setPharmacyFilter = function (filter, btnElement) {
            window.currentPharmacyFilter = filter;

            // Make sure the main section is active
            document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
            const target = document.getElementById('sectionPrescriptions');
            if (target) target.classList.add('active');

            // Update UI highlight
            $$('.nav-item').forEach(b => b.classList.remove('active'));
            $$('.stat-card').forEach(c => c.classList.remove('active-card'));

            // Highlight the clicked button if it's a nav-item
            if (btnElement && btnElement.classList.contains('nav-item')) {
                btnElement.classList.add('active');
            }

            // Sync highlights for dynamic IDs
            const nav = document.getElementById(`navFilter${filter}`);
            if (nav) nav.classList.add('active');

            const card = document.getElementById(`cardFilter${filter}`);
            if (card) card.classList.add('active-card');

            const title = $('#tableTitle');
            if (title) {
                title.textContent = filter === 'All' ? 'Prescription List' : `Prescription List / Filtered`;
            }

            if (btnElement && btnElement.classList.contains('stat-card')) {
                const sec = document.getElementById('sectionPrescriptions');
                if (sec) sec.scrollIntoView({ behavior: 'smooth' });
            }

            if (window.currentPatients) {
                renderPharmacyTable(window.currentPatients);
            }
        };

        document.addEventListener('DOMContentLoaded', function () {
            loadPatients();
        });
    }

    function renderPharmacyTable(patients) {
        const body = $('#prescBody');
        const empty = $('#emptyPresc');
        if (!body) return;

        // Apply filter
        let filtered = patients;
        if (window.currentPharmacyFilter !== 'All') {
            filtered = patients.filter(p => String(p.doctor_id) === String(window.currentPharmacyFilter));
        }

        if (filtered.length === 0) {
            body.innerHTML = '';
            if (empty) empty.style.display = '';
            return;
        }
        if (empty) empty.style.display = 'none';

        body.innerHTML = filtered.map(p => {
            const isPending = p.presc_status === 'pending';
            const badge = isPending
                ? '<span class="badge badge-consulted">Consulted</span>'
                : '<span class="badge badge-completed">Completed</span>';
            const actions = isPending
                ? `<button class="btn btn-warning btn-sm" onclick="openMedicineModal(${p.presc_id}, '${p.name.replace(/'/g, "\\'")}', '${(p.diagnosis || '').replace(/'/g, "\\'")}', '${(p.prescription_text || '').replace(/'/g, "\\'")}')">Add Medicines</button>`
                : `<button class="btn btn-outline btn-sm" onclick="viewDetail(${p.presc_id})">View / PDF</button>`;

            const uptBadge = (p.presc_doctor_type === 'Lady' && p.upt_card) ?
                '<span class="badge" style="margin-top: 6px; display: inline-block; font-size: 0.6rem; border-color: #f43f5e; color: #f43f5e; margin-left: 4px;">UPT Card Required</span>' : '';

            const formatLocalTime = (ts) => {
                if (!ts) return '-';
                try {
                    // Try parsing as YYYY-MM-DD HH:MM:SS
                    const parts = ts.split(' ');
                    if (parts.length === 2) {
                        const [d, t] = parts;
                        const [y, m, day] = d.split('-').map(Number);
                        const [h, min, s] = t.split(':').map(Number);
                        const dt = new Date(y, m - 1, day, h, min, s);
                        if (!isNaN(dt.getTime())) {
                            return dt.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
                        }
                    }
                    // Fallback to native parsing
                    const dt = new Date(ts);
                    if (!isNaN(dt.getTime())) {
                        return dt.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
                    }
                } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
                return ts;
            };

            const timeIn = formatLocalTime(p.created_at);
            const timeOut = formatLocalTime(p.completed_at);

            return `<tr>
                <td><strong>${p.token}</strong></td>
                <td><span style="font-size:0.75rem; color:var(--text-secondary); font-weight:600;">${p.patient_id || '-'}</span></td>
                <td>
                    <div style="font-weight: 500;">${p.name}</div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">+91 ${p.phone}</div>
                    ${uptBadge}
                </td>
                <td>${formatDoctorName(p.doctor_name, p.doctor_type)}</td>
                <td>${timeIn}</td>
                <td>${timeOut}</td>
                <td>${badge}</td>
                <td>${actions}</td>
            </tr>`;
        }).join('');
    }

    window.openMedicineModal = async function (prescId, name, diag, presc) {
        currentPrescId = prescId;

        // Ensure UPI accounts are loaded before showing form
        await window.loadGlobalUpiAccounts();

        $('#medModalPatient').textContent = name;
        $('#medModalDiag').textContent = diag || '-';
        $('#medModalPresc').textContent = presc || '-';
        $('#medicineRows').innerHTML = '';

        if ($('#medModalPatientInfo')) $('#medModalPatientInfo').style.display = 'block';
        if ($('#medModalDirectFields')) $('#medModalDirectFields').style.display = 'none';

        const clearInput = $('#clearPrevBalanceInput');
        if (clearInput) clearInput.value = '';

        // Check for previous balance
        const patient = window.currentPatients.find(p => p.presc_id === prescId);
        const container = document.getElementById('prevBalanceContainer');
        const formSec = document.getElementById('prevBalanceForm');
        const statusSec = document.getElementById('prevBalanceStatusSection');
        if (container) {
            container.style.background = '#fff1f2';
            container.style.borderColor = '#fda4af';
        }
        if (formSec) formSec.style.display = 'flex';
        if (statusSec) statusSec.style.display = 'none';

        if (patient) {
            try {
                const res = await api('/api/patient_total_balance/' + patient.phone);
                const totalBalance = res.total_balance || 0;
                if (totalBalance > 0) {
                    document.getElementById('prevBalanceAmount').textContent = '₹' + totalBalance.toFixed(2);
                    if (container) container.style.display = 'block';
                    window.currentPatientPhone = patient.phone; // Store for clearing
                } else {
                    if (container) container.style.display = 'none';
                }
            } catch (e) { console.error('Error fetching balance:', e); }
        }

        try {
            const patient = window.currentPatients.find(p => p.presc_id === prescId);
            if (patient) {
                $('#medModalFee').value = patient.consultation_fee || 0;
                $('#medModalScan').value = patient.scan_fee || 0;
                if ($('#medModalScanType')) $('#medModalScanType').value = patient.scan_type || '';
                if ($('#medModalScanNotes')) $('#medModalScanNotes').value = patient.scan_notes || '';
                if (patient.presc_doctor_type === 'Lady' && (patient.scan_fee > 0 || patient.scan_type || patient.scan_notes)) {
                    if ($('#pharmacyCheckScan')) $('#pharmacyCheckScan').checked = true;
                    if ($('#pharmacyScanGroup')) $('#pharmacyScanGroup').style.display = 'block';
                    $('#medModalScanContainer').style.display = 'block';
                } else {
                    if ($('#pharmacyCheckScan')) $('#pharmacyCheckScan').checked = false;
                    if ($('#pharmacyScanGroup')) $('#pharmacyScanGroup').style.display = 'block';
                    $('#medModalScanContainer').style.display = 'none';
                    $('#medModalScan').value = 0;
                    if ($('#medModalScanType')) $('#medModalScanType').value = '';
                    if ($('#medModalScanNotes')) $('#medModalScanNotes').value = '';
                }

                if ($('#pharmacyInjectionGroup')) $('#pharmacyInjectionGroup').style.display = 'block';
                // Sync Injection
                const injRowsContainer = $('#pharmacyInjectionRows');
                if (injRowsContainer) injRowsContainer.innerHTML = '';

                if (patient.injection_details && patient.injection_details !== '') {
                    $('#pharmacyCheckInjection').checked = true;
                    $('#pharmacyInjectionInputs').style.display = 'block';
                    if (typeof addPharmacyInjectionRow === 'function') {
                        addPharmacyInjectionRow();
                        setTimeout(() => {
                            const firstRow = $('#pharmacyInjectionRows').querySelector('.medicine-row');
                            if (firstRow) {
                                firstRow.querySelector('.inj-name').value = patient.injection_details.trim();
                                firstRow.querySelector('.inj-cost').value = patient.injection_cost || 0;
                            }
                        }, 50);
                    }
                } else {
                    $('#pharmacyCheckInjection').checked = false;
                    $('#pharmacyInjectionInputs').style.display = 'none';
                }

                // Sync UPT Card
                const uptGroup = $('#pharmacyUPTGroup');
                const uptCheck = $('#pharmacyCheckUPT');
                const uptInputs = $('#pharmacyUPTInputs');
                const statusEl = $('#pharmacyUPTStatus');

                if (patient.presc_doctor_type === 'Lady') {
                    if (uptGroup) uptGroup.style.display = 'block';
                } else {
                    if (uptGroup) uptGroup.style.display = 'none';
                    if (uptCheck) uptCheck.checked = false;
                    $('#medModalUPTCost').value = 0;
                }

                if (patient.upt_card) {
                    if (uptCheck) uptCheck.checked = true;
                    if (uptInputs) uptInputs.style.display = 'block';
                    if (statusEl) {
                        statusEl.textContent = 'UPT Card Selected by Doctor';
                        statusEl.style.color = '#ef4444';
                        statusEl.style.fontWeight = '600';
                    }
                    try {
                        const items = await api(`/api/inventory/search?category=UPT%20Card&q=`);
                        if (items.length > 0 && items[0].selling_price) {
                            $('#medModalUPTCost').value = items[0].selling_price;
                        } else {
                            $('#medModalUPTCost').value = 0;
                        }
                    } catch (e) { $('#medModalUPTCost').value = 0; }
                } else {
                    if (uptCheck) uptCheck.checked = false;
                    if (uptInputs) uptInputs.style.display = 'none';
                    if (statusEl) {
                        statusEl.textContent = 'Manual Control Available';
                        statusEl.style.color = 'var(--text-secondary)';
                        statusEl.style.fontWeight = 'normal';
                    }
                    $('#medModalUPTCost').value = 0;
                }
            }
        } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }

        if ($('#manualPendingAmount')) $('#manualPendingAmount').value = '';
        if ($('#applyDiscountCheck')) {
            $('#applyDiscountCheck').checked = false;
            if ($('#discountInputGroup')) $('#discountInputGroup').style.display = 'none';
            if ($('#discountPercent')) $('#discountPercent').value = '';
        }
        if ($('#payCashCheck')) $('#payCashCheck').checked = false;
        if ($('#payGPayCheck')) $('#payGPayCheck').checked = false;
        if ($('#payPhonePeCheck')) $('#payPhonePeCheck').checked = false;
        if ($('#cashInputGroup')) $('#cashInputGroup').style.display = 'none';
        if ($('#gpayInputGroup')) $('#gpayInputGroup').style.display = 'none';
        if ($('#phonepeInputGroup')) $('#phonepeInputGroup').style.display = 'none';
        if ($('#payCashAmount')) $('#payCashAmount').value = 0;
        if ($('#payGPayAmount')) $('#payGPayAmount').value = 0;
        if ($('#payPhonePeAmount')) $('#payPhonePeAmount').value = 0;
        if ($('#feeScanContainer')) $('#feeScanContainer').style.display = 'grid';
        addMedicineRow();
        updateGrandTotal();
        openModal('medicineModal');
    };

    window.togglePayInputs = async function () {
        const cCash = $('#payCashCheck').checked;
        const cGpay = $('#payGPayCheck').checked;

        if (cCash) $('#cashInputGroup').style.display = 'block';
        else { $('#cashInputGroup').style.display = 'none'; $('#payCashAmount').value = 0; }

        if (cGpay) {
            $('#gpayInputGroup').style.display = 'block';
            await window.loadGlobalUpiAccounts();
            if ($('#payUpiAccount')) $('#payUpiAccount').focus();
        }
        else { $('#gpayInputGroup').style.display = 'none'; $('#payGPayAmount').value = 0; }

        updateGrandTotal();
    };

    window.toggleDiscountInput = function () {
        const check = $('#applyDiscountCheck');
        if (check) {
            $('#discountInputGroup').style.display = check.checked ? 'flex' : 'none';
            if (!check.checked) {
                $('#discountPercent').value = '';
            }
            updateGrandTotal();
        }
    };

    // Global Number Input "0" behavior
    document.addEventListener('focusin', (e) => {
        if (e.target.tagName === 'INPUT' && (e.target.type === 'number' || e.target.classList.contains('med-qty') || e.target.classList.contains('med-price')) && e.target.value === '0') {
            e.target.value = '';
        }
    });
    document.addEventListener('focusout', (e) => {
        if (e.target.tagName === 'INPUT' && (e.target.type === 'number' || e.target.classList.contains('med-qty') || e.target.classList.contains('med-price')) && e.target.value === '') {
            e.target.value = '0';
        }
    });

    window.addMedicineRow = function () {
        const container = $('#medicineRows');
        const row = document.createElement('div');
        row.className = 'medicine-row';
        row.style.display = 'grid';
        row.style.gridTemplateColumns = '2.2fr 0.8fr 0.8fr 1fr 1fr 30px';
        row.style.gap = '10px';
        row.style.marginBottom = '10px';
        row.style.alignItems = 'end';
        row.innerHTML = `
            <div class="form-group" style="margin:0; position:relative;">
                <label style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 2px; display: block;">Medicine Name</label>
                <input type="text" placeholder="Medicine name" class="med-name" autocomplete="off" oninput="searchMedicine(this)" onblur="autoFillMedData(this)">
                <input type="hidden" class="med-batch-id" value="">
                <input type="hidden" class="med-tps" value="0">
                <div class="med-suggestions" style="display:none; position:absolute; top:100%; left:0; width:100%; background:var(--bg-card); border:1px solid var(--border); z-index:1000; border-radius:4px; max-height:200px; overflow-y:auto; box-shadow:0 8px 16px rgba(0,0,0,0.5);"></div>
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 2px; display: block;">Tablets</label>
                <input type="number" placeholder="0" class="med-qty" min="0" value="0" oninput="calcRowAmount(this)">
            </div>
            <div class="form-group med-strips-group" style="margin:0;">
                <label style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 2px; display: block;">Strip / Card (Attai)</label>
                <input type="number" placeholder="0" class="med-strips" min="0" value="0" oninput="calcRowAmount(this)">
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 2px; display: block;">Unit Price</label>
                <input type="number" placeholder="0.00" class="med-price" step="0.01" oninput="calcRowAmount(this)">
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 2px; display: block;">Total Amount</label>
                <input type="number" placeholder="0.00" class="med-amount" readonly>
            </div>
            <button class="btn-remove-med" onclick="this.parentElement.remove();updateGrandTotal();" style="margin-bottom: 8px;">✕</button>
        `;
        const nameInput = row.querySelector('.med-name');
        nameInput.addEventListener('keydown', function (e) {
            const suggBox = row.querySelector('.med-suggestions');
            if (suggBox.style.display === 'block') {
                const items = suggBox.querySelectorAll('.med-sugg-item');
                let activeIdx = Array.from(items).findIndex(i => i.classList.contains('active-sugg'));

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (activeIdx < items.length - 1) activeIdx++;
                    else activeIdx = 0;
                    items.forEach(i => { i.classList.remove('active-sugg'); i.style.background = ''; });
                    if (items[activeIdx]) {
                        items[activeIdx].classList.add('active-sugg');
                        items[activeIdx].style.background = 'var(--bg-hover)';
                        items[activeIdx].scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (activeIdx > 0) activeIdx--;
                    else activeIdx = items.length - 1;
                    items.forEach(i => { i.classList.remove('active-sugg'); i.style.background = ''; });
                    if (items[activeIdx]) {
                        items[activeIdx].classList.add('active-sugg');
                        items[activeIdx].style.background = 'var(--bg-hover)';
                        items[activeIdx].scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIdx >= 0 && items[activeIdx]) {
                        items[activeIdx].click();
                    } else if (items.length > 0) {
                        items[0].click();
                    }
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                row.querySelector('.med-qty').focus();
            }
        });

        row.querySelector('.med-qty').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); row.querySelector('.med-strips').focus(); }
        });
        row.querySelector('.med-strips').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); row.querySelector('.med-price').focus(); }
        });
        row.querySelector('.med-price').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addMedicineRow(); }
        });

        container.appendChild(row);
        setTimeout(() => { nameInput.focus(); }, 10);
    };

    window.addPharmacyInjectionRow = function () {
        const container = $('#pharmacyInjectionRows');
        if (!container) return;
        const row = document.createElement('div');
        row.className = 'medicine-row';
        row.style.display = 'grid';
        row.style.gridTemplateColumns = '2fr 1fr 30px';
        row.style.gap = '10px';
        row.style.marginBottom = '10px';
        row.style.alignItems = 'end';
        const injId = 'injName_' + Date.now() + Math.floor(Math.random() * 1000);
        row.innerHTML = `
            <div class="form-group" style="margin:0; position:relative;">
                <label style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 2px; display: block;">Injection Name</label>
                <input type="text" placeholder="Enter injection name" class="inj-name" id="${injId}" autocomplete="off" oninput="searchInventoryCategory('Injection', this)">
                <div class="med-suggestions" style="display:none; position:absolute; top:100%; left:0; width:100%; z-index:1000; background:var(--bg-card); border:1px solid var(--border); border-radius:4px; max-height:200px; overflow-y:auto; box-shadow:0 8px 16px rgba(0,0,0,0.5);"></div>
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 2px; display: block;">Amount (₹)</label>
                <input type="number" placeholder="0" class="inj-cost" value="0" oninput="updateGrandTotal()" onfocus="if(this.value==='0') this.value='';" onblur="if(this.value==='') this.value='0';">
            </div>
            <button type="button" class="btn-remove-med" onclick="this.parentElement.remove();updateGrandTotal();" style="margin-bottom: 8px;">✕</button>
        `;

        row.querySelector('.inj-name').addEventListener('keydown', function (e) {
            const suggBox = row.querySelector('.med-suggestions');
            if (suggBox.style.display === 'block') {
                const items = suggBox.querySelectorAll('.med-sugg-item');
                let activeIdx = Array.from(items).findIndex(i => i.classList.contains('active-sugg'));
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (activeIdx < items.length - 1) activeIdx++; else activeIdx = 0;
                    items.forEach(i => { i.classList.remove('active-sugg'); i.style.background = ''; });
                    if (items[activeIdx]) {
                        items[activeIdx].classList.add('active-sugg');
                        items[activeIdx].style.background = 'var(--bg-hover)';
                        items[activeIdx].scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (activeIdx > 0) activeIdx--; else activeIdx = items.length - 1;
                    items.forEach(i => { i.classList.remove('active-sugg'); i.style.background = ''; });
                    if (items[activeIdx]) {
                        items[activeIdx].classList.add('active-sugg');
                        items[activeIdx].style.background = 'var(--bg-hover)';
                        items[activeIdx].scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIdx >= 0 && items[activeIdx]) items[activeIdx].click();
                    else if (items.length > 0) items[0].click();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                row.querySelector('.inj-cost').focus();
            }
        });

        row.querySelector('.inj-cost').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addPharmacyInjectionRow();
            }
        });

        container.appendChild(row);
        setTimeout(() => { row.querySelector('.inj-name').focus(); }, 10);
    };

    window.searchMedicine = async function (inputEl) {
        const q = inputEl.value.trim();
        const row = inputEl.closest('.medicine-row');
        const suggBox = row.querySelector('.med-suggestions');

        if (q.length < 2) {
            suggBox.style.display = 'none';
            return;
        }

        try {
            const isPharmacy = inputEl.id.startsWith('pharmacy') || inputEl.closest('#medicineRows');
            const url = isPharmacy ? '/api/inventory/search?category=medicine&q=' + encodeURIComponent(q) : '/api/inventory/search?q=' + encodeURIComponent(q);
            const items = await api(url);
            // Cache results for quick calculation
            items.forEach(item => {
                medDataCache[item.name.toLowerCase()] = {
                    price: item.selling_price,
                    tps: item.tablets_per_strip || 0
                };
            });
            if (items.length > 0) {
                suggBox.innerHTML = items.map((item, idx) => {
                    const expText = item.expiry_date || 'N/A';
                    const batchLabel = item.batch_number || '-';
                    const stockWarn = item.stock <= (item.min_stock || 0) ? ' ⚠️ LOW' : '';
                    const activeStyle = idx === 0 ? 'background:var(--bg-hover);' : '';
                    const activeClass = idx === 0 ? 'active-sugg' : '';

                    let cat = (item.category || '').toUpperCase();
                    let nameUpper = (item.name || '').toUpperCase();

                    let priceLabel = '';
                    let stockLabel = '';
                    let extraLabel = '';

                    let isTablet = cat.includes('TAB') || cat.includes('CAP') || nameUpper.includes('TAB') || nameUpper.includes('CAP');
                    let isSyrup = cat.includes('SYP') || cat.includes('SYRUP') || nameUpper.includes('SYP') || nameUpper.includes('SYRUP');
                    let isInjection = cat.includes('INJ') || nameUpper.includes('INJ');
                    let isOintment = cat.includes('OINT') || cat.includes('CREAM') || cat.includes('DROP') || nameUpper.includes('OINT') || nameUpper.includes('CREAM') || nameUpper.includes('DROP');
                    let isPowder = cat.includes('POW') || cat.includes('GRANULE') || nameUpper.includes('POW') || nameUpper.includes('GRANULE');

                    let matchVolume = item.name.match(/(\d+(\.\d+)?\s*(ml|l|gm|mg|kg|oz))/i);
                    let extText = matchVolume ? matchVolume[0] : 'N/A';

                    let showDynamic = true;

                    if (isTablet) {
                        let tps = parseInt(item.tablets_per_strip) || 1;
                        let strips = Math.floor(item.stock / tps);
                        let loose = item.stock % tps;
                        let stockText = strips + ' Strips';
                        if (loose > 0) stockText += ` + ${loose} Tabs`;
                        stockText += stockWarn;

                        priceLabel = `MRP : ₹${parseFloat(item.mrp || item.selling_price || 0).toFixed(2)} / Strip`;
                        stockLabel = `Stock : ${stockText}`;
                        extraLabel = `Pack : ${tps} Tablets / Strip`;
                    } else if (isSyrup) {
                        priceLabel = `MRP : ₹${parseFloat(item.mrp || item.selling_price || 0).toFixed(2)} / Bottle`;
                        stockLabel = `Stock : ${item.stock} Bottles${stockWarn}`;
                        extraLabel = `Volume : ${extText}`;
                    } else if (isInjection) {
                        priceLabel = `MRP : ₹${parseFloat(item.mrp || item.selling_price || 0).toFixed(2)} / Vial`;
                        stockLabel = `Stock : ${item.stock} Vials${stockWarn}`;
                        extraLabel = `Volume : ${extText}`;
                    } else if (isOintment) {
                        priceLabel = `MRP : ₹${parseFloat(item.mrp || item.selling_price || 0).toFixed(2)} / Tube`;
                        stockLabel = `Stock : ${item.stock} Tubes${stockWarn}`;
                        extraLabel = `Weight : ${extText}`;
                    } else if (isPowder) {
                        priceLabel = `MRP : ₹${parseFloat(item.mrp || item.selling_price || 0).toFixed(2)} / Jar`;
                        stockLabel = `Stock : ${item.stock} Jars${stockWarn}`;
                        extraLabel = `Weight : ${extText}`;
                    } else {
                        let catName = item.category ? item.category : 'Unit';
                        // Keep original capitalization but add 's' if needed
                        let pluralCat = catName;
                        if (!pluralCat.toLowerCase().endsWith('s')) {
                            pluralCat += 's';
                        }

                        priceLabel = `MRP : ₹${parseFloat(item.mrp || item.selling_price || 0).toFixed(2)} / ${catName}`;
                        stockLabel = `Stock : ${item.stock} ${pluralCat}${stockWarn}`;
                        extraLabel = `Pack : ${item.tablets_per_strip || 1} ${pluralCat} / Pack`;
                    }

                    return `
                    <div class="med-sugg-item ${activeClass}" style="padding:10px 12px; cursor:pointer; border-bottom:1px solid var(--border); transition: background 0.15s; ${activeStyle}" 
                         onmouseenter="this.parentElement.querySelectorAll('.med-sugg-item').forEach(i => {i.classList.remove('active-sugg'); i.style.background=''}); this.classList.add('active-sugg'); this.style.background='var(--bg-hover)';"
                         onmouseleave="this.classList.remove('active-sugg'); this.style.background=''"
                         onclick="selectMedicine(this, '${item.name.replace(/'/g, "\\'")}', ${item.selling_price}, ${item.id}, ${item.tablets_per_strip || 0})">
                        <div style="font-weight:700; color:var(--text-primary); margin-bottom:2px; font-size:0.95em; letter-spacing:0.02em;">${item.name}</div>
                        ${item.generic_name ? `<div style="font-size:0.78em; color:#6366f1; font-style:italic; margin-bottom:4px; font-weight:500;">Generic: ${item.generic_name}</div>` : ''}
                        ${item.agency_name ? `<div style="font-size:0.8em; color:var(--text-secondary); margin-bottom:4px;">Agency : ${item.agency_name}</div>` : ''}
                        <div style="font-size:0.8em; color:var(--text-secondary); margin-bottom:4px; display:flex; flex-direction:column; gap:2px;">
                            <span>Batch No : ${batchLabel}</span>
                            <span>Expiry : ${expText}</span>
                        </div>
                        <div style="font-size:0.78rem; color:var(--text-secondary); display:flex; flex-direction:column; gap:3px;">
                            <span>${priceLabel}</span>
                            <span>${stockLabel}</span>
                            <span>${extraLabel}</span>
                            ${item.row_location ? `<span>Row : ${item.row_location}</span>` : ''}
                            ${item.col_location ? `<span>Column : ${item.col_location}</span>` : ''}
                        </div>
                    </div>`;
                }).join('');
                suggBox.style.display = 'block';
            } else {
                suggBox.style.display = 'none';
            }
        } catch (e) {
            console.error(e);
        }
    };

    window.selectMedicine = function (el, name, price, batchId, tps) {
        const row = el.closest('.medicine-row');
        row.querySelector('.med-name').value = name;
        row.querySelector('.med-price').value = price;
        row.querySelector('.med-batch-id').value = batchId || '';
        row.querySelector('.med-tps').value = tps || 0;

        row.querySelector('.med-suggestions').style.display = 'none';
        calcRowAmount(row.querySelector('.med-qty'));

        setTimeout(() => {
            const qtyInput = row.querySelector('.med-qty');
            qtyInput.focus();
            qtyInput.select();
        }, 10);
    };

    window.autoFillMedData = async function (inputEl) {
        const name = inputEl.value.trim();
        if (!name) return;
        const row = inputEl.closest('.medicine-row');
        const currentPrice = parseFloat(row.querySelector('.med-price').value) || 0;
        const currentTps = parseInt(row.querySelector('.med-tps').value) || 0;

        // If we already have data, don't refetch
        if (currentPrice > 0 && currentTps > 0) return;

        try {
            const isPharmacy = inputEl.id.startsWith('pharmacy') || inputEl.closest('#medicineRows');
            const url = isPharmacy ? '/api/inventory/search?category=medicine&q=' + encodeURIComponent(name) : '/api/inventory/search?q=' + encodeURIComponent(name);
            const items = await api(url);
            // Find exact match or first match
            const match = items.find(i => i.name.toLowerCase() === name.toLowerCase()) || items[0];
            if (match) {
                if (!currentPrice) row.querySelector('.med-price').value = match.selling_price || 0;
                if (!currentTps) row.querySelector('.med-tps').value = match.tablets_per_strip || 0;
                calcRowAmount(inputEl);
            }
        } catch (e) { console.error(e); }
    };

    window.searchTreatment = async function (type, inputEl) {
        const q = inputEl.value.trim();
        const isPharmacy = inputEl.id.startsWith('pharmacy');
        const suggBoxId = type === 'injection' ? (isPharmacy ? 'injectionSuggestions' : 'injectionSuggestions') : (isPharmacy ? 'ivSuggestions' : 'ivSuggestions');

        // Find the suggestion box relative to the input or by ID
        const suggBox = $('#' + suggBoxId);
        if (!suggBox) return;

        if (q.length < 1) {
            suggBox.style.display = 'none';
            return;
        }

        try {
            const items = await api(`/api/treatment/search?type=${type}&q=${encodeURIComponent(q)}`);
            if (items.length > 0) {
                suggBox.innerHTML = items.map(name => `
                    <div class="med-sugg-item" style="padding:10px 12px; cursor:pointer; border-bottom:1px solid var(--border); background: var(--bg-card); color: var(--text-primary);" 
                         onmouseenter="this.style.background='var(--bg-hover)'"
                         onmouseleave="this.style.background='var(--bg-card)'"
                         onclick="selectTreatment('${type}', '${name.replace(/'/g, "\\'")}', '${inputEl.id}')">
                        ${name}
                    </div>
                `).join('');
                suggBox.style.display = 'block';
            } else {
                suggBox.style.display = 'none';
            }
        } catch (e) {
            console.error(e);
        }
    };

    window.searchInventoryCategory = async function (category, inputEl) {
        const q = inputEl.value.trim();
        const suggBox = inputEl.nextElementSibling;
        if (!suggBox || !suggBox.classList.contains('med-suggestions')) return;

        if (q.length < 2) {
            suggBox.style.display = 'none';
            return;
        }

        try {
            const items = await api(`/api/inventory/search?category=${encodeURIComponent(category)}&q=${encodeURIComponent(q)}`);
            if (items.length > 0) {
                suggBox.innerHTML = items.map(item => {
                    const price = item.selling_price || 0;
                    return `
                    <div class="med-sugg-item" style="padding:10px 12px; cursor:pointer; border-bottom:1px solid var(--border); transition: background 0.15s; background: var(--bg-card); color: var(--text-primary);" 
                         onmouseenter="this.style.background='var(--bg-hover)'"
                         onmouseleave="this.style.background='var(--bg-card)'"
                         onclick="selectInventoryCategoryItem('${category}', '${item.name.replace(/'/g, "\\'")}', ${price}, '${inputEl.id}')">
                        <div style="font-weight:600; margin-bottom:3px;">${item.name}</div>
                          ${item.agency_name ? `<div style="font-size:0.8em; color:var(--text-secondary); margin-bottom:4px;">${item.agency_name}</div>` : ''}
                        <div style="font-size:0.78rem; color:var(--text-secondary);">MRP: ₹${price.toFixed(2)} | Stock: ${item.stock}
                            ${(item.row_location || item.col_location) ? ' | <span style="color:#6366f1;font-weight:600;">&#128205; Row: ' + (item.row_location || '-') + ' | Col: ' + (item.col_location || '-') + '</span>' : ''}
                        </div>
                    </div>`;
                }).join('');
                suggBox.style.display = 'block';
            } else {
                suggBox.style.display = 'none';
            }
        } catch (e) {
            console.error(e);
        }
    };

    window.selectInventoryCategoryItem = function (category, name, price, inputId) {
        const input = $('#' + inputId);
        const suggBox = input ? input.nextElementSibling : null;
        if (input) input.value = name;
        if (suggBox) suggBox.style.display = 'none';

        // Find corresponding cost input
        let costInput = null;
        if (PAGE_ROLE === 'pharmacist') {
            if (category === 'Injection') {
                const row = input.closest('.medicine-row');
                if (row) costInput = row.querySelector('.inj-cost');
                else costInput = $('#medModalInjectionCost');
            }
            else if (category === 'IV') costInput = $('#medModalIVCost');
        } else if (PAGE_ROLE === 'doctor') {
            if (category === 'Injection') costInput = $('#doctorInjectionCost');
            else if (category === 'IV') costInput = $('#doctorIVCost');
            else if (category === 'Scan') costInput = $('#scanFeeInput');
        }

        if (costInput) {
            costInput.value = price;
            if (typeof updateGrandTotal === 'function') updateGrandTotal();
        }
    };

    // Auto-fill direct customer name by phone
    let phoneLookupTimeout;
    window.checkDirectCustomerPhone = function (phone) {
        if (phone.length >= 10) {
            clearTimeout(phoneLookupTimeout);
            phoneLookupTimeout = setTimeout(async () => {
                try {
                    const res = await api('/api/patients/lookup_by_phone?phone=' + encodeURIComponent(phone));
                    if (res.success && res.name) {
                        const nameEl = document.getElementById('directPatName');
                        if (nameEl && !nameEl.value.trim()) {
                            nameEl.value = res.name;
                            toast('Customer found, name auto-filled', 'success');
                        }
                    }
                } catch (e) { console.error(e); }
            }, 500);
        }
    };

    window.selectTreatment = function (type, name, inputId) {
        const input = $('#' + inputId);
        const suggBox = type === 'injection' ? $('#injectionSuggestions') : $('#ivSuggestions');
        if (input) input.value = name;
        if (suggBox) suggBox.style.display = 'none';
    };

    window.calcRowAmount = function (el) {
        const row = el.closest('.medicine-row');
        const name = row.querySelector('.med-name').value.trim().toLowerCase();
        let price = parseFloat(row.querySelector('.med-price').value) || 0;
        let tps = parseInt(row.querySelector('.med-tps').value) || 0;

        // Use cache if data is missing
        if (name && (price === 0 || tps === 0)) {
            const cached = medDataCache[name];
            if (cached) {
                if (price === 0) {
                    price = cached.price;
                    row.querySelector('.med-price').value = price;
                }
                if (tps === 0) {
                    tps = cached.tps;
                    row.querySelector('.med-tps').value = tps;
                }
            }
        }

        const strips = parseFloat(row.querySelector('.med-strips').value) || 0;
        const tablets = parseFloat(row.querySelector('.med-qty').value) || 0;

        // NEW LOGIC: DB stores the FULL STRIP price as the unit price.
        // So 'price' is the cost of 1 Strip.
        const actualTps = tps > 0 ? tps : 1; // Prevent division by zero
        const tabletPrice = price / actualTps;

        const amount = (strips * price) + (tablets * tabletPrice);

        row.querySelector('.med-amount').value = amount.toFixed(2);
        updateGrandTotal();
    };

    window.updateGrandTotal = function () {
        let medTotal = 0;
        $$('.med-amount').forEach(el => { medTotal += parseFloat(el.value) || 0; });

        const consultationFee = parseFloat($('#medModalFee').value) || 0;
        const scanFee = parseFloat($('#medModalScan').value) || 0;

        let injectionCost = 0;
        if ($('#pharmacyCheckInjection') && $('#pharmacyCheckInjection').checked) {
            $$('#pharmacyInjectionRows .inj-cost').forEach(el => { injectionCost += parseFloat(el.value) || 0; });
        }

        const uptCost = ($('#pharmacyCheckUPT') && $('#pharmacyCheckUPT').checked) ? (parseFloat($('#medModalUPTCost').value) || 0) : 0;

        const subtotalBill = medTotal + consultationFee + scanFee + injectionCost + uptCost;

        // Discount Logic
        let discountPercent = 0;
        const applyDiscountCheck = $('#applyDiscountCheck');
        if (applyDiscountCheck && applyDiscountCheck.checked) {
            discountPercent = parseFloat($('#discountPercent').value) || 0;
        }

        const discountAmount = subtotalBill * (discountPercent / 100);
        const totalBill = subtotalBill - discountAmount;

        const cashAmount = parseFloat($('#payCashAmount').value) || 0;
        const gpayAmount = parseFloat($('#payGPayAmount').value) || 0;
        const phonepeAmount = $('#payPhonePeAmount') ? parseFloat($('#payPhonePeAmount').value) || 0 : 0;
        const paidAmount = cashAmount + gpayAmount + phonepeAmount;

        // Breakdown Update
        if ($('#bdMedTotal')) $('#bdMedTotal').textContent = '₹' + medTotal.toFixed(2);
        if ($('#bdDocFee')) $('#bdDocFee').textContent = '₹' + consultationFee.toFixed(2);

        if ($('#bdScanRow')) {
            if (scanFee > 0) { $('#bdScanRow').style.display = 'flex'; $('#bdScanFee').textContent = '₹' + scanFee.toFixed(2); }
            else { $('#bdScanRow').style.display = 'none'; }
        }
        if ($('#bdInjRow')) {
            if (injectionCost > 0) { $('#bdInjRow').style.display = 'flex'; $('#bdInjFee').textContent = '₹' + injectionCost.toFixed(2); }
            else { $('#bdInjRow').style.display = 'none'; }
        }
        if ($('#bdUptRow')) {
            if (uptCost > 0) { $('#bdUptRow').style.display = 'flex'; $('#bdUptFee').textContent = '₹' + uptCost.toFixed(2); }
            else { $('#bdUptRow').style.display = 'none'; }
        }
        if ($('#bdCashRow')) {
            if (cashAmount > 0) { $('#bdCashRow').style.display = 'flex'; $('#bdCashPaid').textContent = '₹' + cashAmount.toFixed(2); }
            else { $('#bdCashRow').style.display = 'none'; }
        }
        if ($('#bdGpayRow')) {
            if (gpayAmount > 0) { $('#bdGpayRow').style.display = 'flex'; $('#bdGpayPaid').textContent = '₹' + gpayAmount.toFixed(2); }
            else { $('#bdGpayRow').style.display = 'none'; }
        }
        if ($('#bdPhonePeRow')) {
            if (phonepeAmount > 0) { $('#bdPhonePeRow').style.display = 'flex'; $('#bdPhonePePaid').textContent = '₹' + phonepeAmount.toFixed(2); }
            else { $('#bdPhonePeRow').style.display = 'none'; }
        }

        if ($('#subTotal')) $('#subTotal').textContent = '₹' + subtotalBill.toFixed(2);

        if ($('#bdDiscountRow')) {
            if (discountPercent > 0) {
                $('#bdDiscountRow').style.display = 'flex';
                $('#bdDiscountPercent').textContent = discountPercent;
                $('#bdDiscountAmount').textContent = '-₹' + discountAmount.toFixed(2);
            } else {
                $('#bdDiscountRow').style.display = 'none';
            }
        }

        const gt = $('#grandTotal'); if (gt) gt.textContent = '₹' + totalBill.toFixed(2);
        const pt = $('#paidTotal'); if (pt) pt.textContent = '₹' + paidAmount.toFixed(2);

        const balanceLabel = $('#balanceLabel');
        const balanceAmountEl = $('#balanceAmount');
        const statusMsg = $('#paymentStatusMsg');

        const manualInput = $('#manualPendingAmount') ? $('#manualPendingAmount').value.trim() : '';

        if (manualInput !== '') {
            const manualVal = parseFloat(manualInput) || 0;
            balanceLabel.textContent = 'Balance:';
            balanceAmountEl.textContent = 'Pending ₹' + manualVal.toFixed(2);
            balanceAmountEl.style.color = 'var(--danger)';
            statusMsg.textContent = `Manual Override Applied`;
            statusMsg.style.color = 'var(--danger)';
            balanceAmountEl.setAttribute('data-val', manualVal.toFixed(2));
            return;
        }

        if (paidAmount < totalBill) {
            const bal = totalBill - paidAmount;
            balanceLabel.textContent = 'Balance Pending:';
            balanceAmountEl.textContent = '₹' + bal.toFixed(2);
            balanceAmountEl.style.color = 'var(--danger)';
            statusMsg.textContent = `Pending Amount ₹${bal.toFixed(2)}`;
            statusMsg.style.color = 'var(--danger)';
            balanceAmountEl.setAttribute('data-val', bal.toFixed(2));
        } else if (paidAmount === totalBill) {
            balanceLabel.textContent = 'Balance:';
            balanceAmountEl.textContent = 'Paid in Full';
            balanceAmountEl.style.color = 'var(--success)';
            statusMsg.textContent = 'Payment Completed';
            statusMsg.style.color = 'var(--success)';
            balanceAmountEl.setAttribute('data-val', '0.00');
        } else {
            const ret = paidAmount - totalBill;
            balanceLabel.textContent = 'Balance Return:';
            balanceAmountEl.textContent = '₹' + ret.toFixed(2);
            balanceAmountEl.style.color = '#ca8a04';
            statusMsg.textContent = `Return Amount ₹${ret.toFixed(2)}`;
            statusMsg.style.color = '#ca8a04';
            balanceAmountEl.setAttribute('data-val', '0.00');
        }
    };

    window.submitMedicines = async function (sendWhatsApp = false) {
        if (!currentPrescId) return;
        const rows = $$('#medicineRows .medicine-row');
        const medicines = [];
        rows.forEach(row => {
            const name = row.querySelector('.med-name').value.trim();
            const tps = parseInt(row.querySelector('.med-tps').value) || 0;
            const strips = parseFloat(row.querySelector('.med-strips').value) || 0;
            const tablets = parseFloat(row.querySelector('.med-qty').value) || 0;

            const totalQty = (strips * tps) + tablets;
            const amount = parseFloat(row.querySelector('.med-amount').value) || 0;
            const unit_price = totalQty > 0 ? (amount / totalQty) : 0;
            const batch_id = row.querySelector('.med-batch-id') ? row.querySelector('.med-batch-id').value : '';
            if (name && totalQty > 0) medicines.push({ name, qty: totalQty, unit_price, amount, batch_id });
        });

        let injCost = 0;
        let injNames = [];
        if ($('#pharmacyCheckInjection') && $('#pharmacyCheckInjection').checked) {
            $$('#pharmacyInjectionRows .medicine-row').forEach(row => {
                const name = row.querySelector('.inj-name').value.trim();
                const cost = parseFloat(row.querySelector('.inj-cost').value) || 0;
                if (name || cost > 0) {
                    if (name) injNames.push(name);
                    injCost += cost;
                }
            });
        }
        const injName = injNames.join(', ');

        const uptCost = ($('#pharmacyCheckUPT') && $('#pharmacyCheckUPT').checked) ? (parseFloat($('#medModalUPTCost').value) || 0) : 0;

        const hasFees = (parseFloat($('#medModalFee').value) || 0) > 0 || (parseFloat($('#medModalScan').value) || 0) > 0 || injCost > 0 || uptCost > 0;
        if (medicines.length === 0 && !hasFees) return toast('Add at least one medicine or fee', 'error');

        const cashChecked = $('#payCashCheck').checked;
        const gpayChecked = $('#payGPayCheck').checked;
        const phonepeChecked = $('#payPhonePeCheck') ? $('#payPhonePeCheck').checked : false;
        const cashVal = cashChecked ? (parseFloat($('#payCashAmount').value) || 0) : 0;
        const gpayVal = gpayChecked ? (parseFloat($('#payGPayAmount').value) || 0) : 0;
        const upiAccount = gpayChecked ? $('#payUpiAccount').value : null;
        if (gpayChecked && !upiAccount && gpayVal > 0) {
            return toast('Please select a Bank Account.', 'error');
        }
        const phonepeVal = 0;

        let discountPercent = 0;
        if ($('#applyDiscountCheck') && $('#applyDiscountCheck').checked) {
            discountPercent = parseFloat($('#discountPercent').value) || 0;
        }

        const payload = {
            prescription_id: currentPrescId,
            medicines: medicines,
            consultation_fee: parseFloat($('#medModalFee').value) || 0,
            scan_fee: parseFloat($('#medModalScan').value) || 0,
            scan_type: $('#medModalScanType') ? $('#medModalScanType').value.trim() : null,
            scan_notes: $('#medModalScanNotes') ? $('#medModalScanNotes').value.trim() : null,
            injection_cost: injCost,
            injection_details: injName,
            iv_cost: 0,
            iv_details: '',
            upt_cost: uptCost,
            discount_percent: discountPercent,
            cash_amount: cashVal,
            gpay_amount: gpayVal,
            phonepe_amount: phonepeVal,
            paid_amount: cashVal + gpayVal + phonepeVal,
            balance_amount: parseFloat($('#balanceAmount').getAttribute('data-val')) || 0,
            upi_account: upiAccount
        };

        // Open blank window immediately if WhatsApp option selected, to avoid popup blocker
        let waWin = null;
        if (sendWhatsApp) {
            waWin = window.open('about:blank', '_blank');
        }

        // ── STEP 2: Now save billing via API ──
        try {
            const endpoint = (currentPrescId === 'direct') ? '/api/direct_sales/add' : '/api/add_medicines';
            if (currentPrescId === 'direct') {
                payload.customer_name = $('#directPatName').value.trim();
                payload.mobile_number = $('#directPatPhone').value.trim();
                if (!payload.customer_name) {
                    if (waWin) waWin.close();
                    return toast('Customer name is required for Direct Sale', 'error');
                }
                delete payload.patient_name;
                delete payload.patient_phone;
                delete payload.prescription_id;
            }

            const res = await api(endpoint, {
                method: 'POST',
                body: payload
            });
            if (res.success) {
                toast('Billing saved successfully!');
                closeModal('medicineModal');

                if (currentPrescId === 'direct') {
                    if (window.loadDirectSales) window.loadDirectSales();
                } else {
                    loadPatients();
                }

                // If WhatsApp requested, generate the bill image and open the share flow
                if (sendWhatsApp && res.data) {
                    let p = Object.assign({}, res.data);
                    if (currentPrescId === 'direct') {
                        p.name = res.data.customer_name || 'Direct Sale';
                        p.phone = res.data.mobile_number || '';
                        p.token = 'DS-' + res.data.id;
                        p.age = 0;
                        p.gender = 'Other';
                        p.doctor_name = 'Direct Medicine Sales';
                        p.doctor_type = 'Pharmacy';
                        p.patient_id = '-';
                    } else {
                        p.name = res.data.name;
                        p.phone = res.data.phone;
                        p.token = res.data.token;
                        p.age = res.data.age;
                        p.gender = res.data.gender;
                        p.patient_id = res.data.patient_id;
                        p.doctor_name = res.data.doctor_name;
                        p.doctor_type = res.data.doctor_type;
                        p.created_at = res.data.created_at;
                        p.completed_at = res.data.completed_at;
                        p.presc_id = res.data.presc_id || currentPrescId;
                    }

                    // Call the existing WhatsApp Web/Share link workflow
                    window.sendWhatsAppImage(p, waWin);
                }
            } else {
                if (waWin) waWin.close();
                toast('Failed to save billing', 'error');
            }
        } catch (err) {
            if (waWin) waWin.close();
            console.error('Save Billing Error:', err);
        }
    };

    window.openDirectPharmacy = async function () {
        currentPrescId = 'direct';

        // Ensure UPI accounts are loaded before showing form
        await window.loadGlobalUpiAccounts();

        $('#medModalPatient').textContent = 'Direct Medicine Sale';
        $('#medModalDiag').textContent = '-';
        $('#medModalPresc').textContent = '-';
        $('#medicineRows').innerHTML = '';

        $('#medModalPatientInfo').style.display = 'none';
        $('#medModalDirectFields').style.display = 'block';
        $('#directPatName').value = '';
        $('#directPatPhone').value = '';

        const container = document.getElementById('prevBalanceContainer');
        if (container) container.style.display = 'none';

        $('#medModalFee').value = 0;
        $('#medModalScan').value = 0;
        if ($('#pharmacyCheckScan')) $('#pharmacyCheckScan').checked = false;
        if ($('#feeScanContainer')) $('#feeScanContainer').style.display = 'none';
        $('#medModalScanContainer').style.display = 'none';

        $('#pharmacyCheckInjection').checked = false;
        $('#pharmacyInjectionInputs').style.display = 'none';
        if ($('#pharmacyInjectionRows')) $('#pharmacyInjectionRows').innerHTML = '';

        if ($('#pharmacyInjectionGroup')) $('#pharmacyInjectionGroup').style.display = 'none';

        const uptGroup = $('#pharmacyUPTGroup');
        const uptCheck = $('#pharmacyCheckUPT');
        const uptInputs = $('#pharmacyUPTInputs');
        if (uptGroup) uptGroup.style.display = 'block';
        if (uptCheck) uptCheck.checked = false;
        if (uptInputs) uptInputs.style.display = 'none';
        $('#medModalUPTCost').value = 0;

        if ($('#manualPendingAmount')) $('#manualPendingAmount').value = '';
        if ($('#applyDiscountCheck')) {
            $('#applyDiscountCheck').checked = false;
            if ($('#discountInputGroup')) $('#discountInputGroup').style.display = 'none';
            if ($('#discountPercent')) $('#discountPercent').value = '';
        }

        if ($('#payCashCheck')) $('#payCashCheck').checked = false;
        if ($('#payGPayCheck')) $('#payGPayCheck').checked = false;
        if ($('#payPhonePeCheck')) $('#payPhonePeCheck').checked = false;
        if ($('#cashInputGroup')) $('#cashInputGroup').style.display = 'none';
        if ($('#gpayInputGroup')) $('#gpayInputGroup').style.display = 'none';
        if ($('#phonepeInputGroup')) $('#phonepeInputGroup').style.display = 'none';
        if ($('#payCashAmount')) $('#payCashAmount').value = 0;
        if ($('#payGPayAmount')) $('#payGPayAmount').value = 0;
        if ($('#payPhonePeAmount')) $('#payPhonePeAmount').value = 0;

        const balEl = $('#balanceAmount');
        if (balEl) {
            balEl.setAttribute('data-val', '0.00');
            balEl.textContent = '₹0.00';
        }

        addMedicineRow();
        updateGrandTotal();
        openModal('medicineModal');
    };

    window.viewDetail = async function (prescId) {
        currentPrescId = prescId;
        try {
            // Check cache first
            let p = (window.currentPatients || []).find(x => String(x.presc_id) === String(prescId));
            if (!p) {
                const patients = await api(`/api/patients?as_role=${PAGE_ROLE}`);
                p = patients.find(x => String(x.presc_id) === String(prescId));
            }
            if (!p) return toast('Prescription not found', 'error');

            window.currentReturnContext = {
                sale_type: 'prescription',
                sale_id: prescId,
                patient_name: p.name,
                bill_number: 'PR-' + prescId,
                medicines: p.medicines || [],
                balance_amount: parseFloat(p.balance_amount) || 0,
                paid_amount: parseFloat(p.paid_amount) || 0,
                total_amount: parseFloat(p.total_amount) || 0,
                discount_percent: parseFloat(p.discount_percent) || 0
            };

            const medicines = p.medicines || [];
            const paidAmt = parseFloat(p.paid_amount) || 0;
            const total = parseFloat(p.total_amount) || 0;
            const consultationFee = parseFloat(p.consultation_fee) || 0;
            const injCost = parseFloat(p.injection_cost) || 0;
            const ivCost = parseFloat(p.iv_cost) || 0;
            const uptCost = parseFloat(p.upt_cost) || 0;
            const scanFee = parseFloat(p.scan_fee) || 0;
            let grandTotal = total + consultationFee + scanFee + injCost + ivCost + uptCost;
            const discountPercent = parseFloat(p.discount_percent) || 0;

            let medsHtml = '';
            if (medicines.length > 0) {
                medsHtml = `<table class="data-table" style="margin-top:12px;">
                    <thead><tr><th>#</th><th>Medicine</th><th>Qty</th><th>Price</th><th>Amount</th></tr></thead>
                    <tbody>${medicines.map((m, i) => {
                    const retQty = parseFloat(m.returned_qty) || 0;
                    const qtyHtml = retQty > 0 ? `${m.qty} <span style="color:var(--danger);font-size:0.8rem;">(-${retQty})</span>` : m.qty;
                    return `<tr><td>${i + 1}</td><td>${m.name}</td><td>${qtyHtml}</td><td>₹${(parseFloat(m.unit_price) || 0).toFixed(2)}</td><td>₹${(parseFloat(m.amount) || 0).toFixed(2)}</td></tr>`;
                }).join('')}</tbody>
                </table>
                <div class="total-row" style="font-size:0.9rem; padding: 8px 0; border: none; margin: 0;"><span>Medicines Total:</span><span>₹${total.toFixed(2)}</span></div>`;
            }

            medsHtml += `<div class="total-row" style="font-size:0.9rem; padding: 8px 0; border: none; margin: 0;"><span>Consultation Fee:</span><span>₹${consultationFee.toFixed(2)}</span></div>`;
            if (scanFee > 0) medsHtml += `<div class="total-row" style="font-size:0.9rem; padding: 8px 0; border: none; margin: 0;"><span>Scan Fee:</span><span>₹${scanFee.toFixed(2)}</span></div>`;
            if (injCost > 0) medsHtml += `<div class="total-row" style="font-size:0.9rem; padding: 8px 0; border: none; margin: 0;"><span>Injection Cost:</span><span>₹${injCost.toFixed(2)}</span></div>`;
            if (uptCost > 0) medsHtml += `<div class="total-row" style="font-size:0.9rem; padding: 8px 0; border: none; margin: 0;"><span>UPT Card Cost:</span><span>₹${uptCost.toFixed(2)}</span></div>`;

            if (discountPercent > 0) {
                const discountAmt = grandTotal * (discountPercent / 100);
                medsHtml += `<div class="total-row" style="font-size:0.9rem; padding: 8px 0; border: none; margin: 0; color: var(--danger);"><span>Discount (${discountPercent}%):</span><span>-₹${discountAmt.toFixed(2)}</span></div>`;
                grandTotal -= discountAmt;
            }

            medsHtml += `<div class="total-row"><span>Grand Total:</span><span class="total-value">₹${grandTotal.toFixed(2)}</span></div>`;

            let spo2Html = '';
            if (p.spo2) {
                let color = 'var(--text-primary)';
                if (p.spo2 >= 95) color = '#22c55e';
                else if (p.spo2 >= 90) color = '#f59e0b';
                else color = '#ef4444';
                spo2Html = `<div class="detail-item"><div class="label">SpO2</div><div class="value" style="color: ${color}; font-weight: 600;">${p.spo2}%</div></div>`;
            }

            let treatmentsHtml = '';
            if (p.injection_details) treatmentsHtml += `<div class="detail-item"><div class="label">Injection</div><div class="value">${p.injection_details}</div></div>`;
            if (p.scan_type) treatmentsHtml += `<div class="detail-item"><div class="label">Scan Type</div><div class="value">${p.scan_type}</div></div>`;
            if (p.scan_notes) treatmentsHtml += `<div class="detail-item"><div class="label">Scan Notes</div><div class="value">${p.scan_notes}</div></div>`;

            $('#detailBody').innerHTML = `
                <div class="detail-grid">
                    <div class="detail-item"><div class="label">Patient ID</div><div class="value" style="font-weight:700; color:var(--primary);">${p.patient_id || '-'}</div></div>
                    <div class="detail-item"><div class="label">Patient</div><div class="value">${p.name}</div></div>
                    <div class="detail-item"><div class="label">Token</div><div class="value">${p.token}</div></div>
                    <div class="detail-item"><div class="label">Doctor</div><div class="value">${formatDoctorName(p.doctor_name, p.doctor_type)}</div></div>
                    <div class="detail-item"><div class="label">Phone</div><div class="value">${p.phone}</div></div>
                    ${spo2Html}
                </div>
                <hr style="border:none;border-top:1px solid var(--border);margin:16px 0;">
                <div class="detail-item"><div class="label">Diagnosis</div><div class="value">${p.diagnosis || '-'}</div></div>
                <div class="detail-item"><div class="label">Prescription</div><div class="value">${p.prescription_text || '-'}</div></div>
                ${treatmentsHtml}
                ${medsHtml}
                <div id="returnHistoryDiv_PR_${p.id}"></div>
            `;
            openModal('detailModal');

            api(`/api/returns_history?sale_id=${p.id}&sale_type=prescription`).then(returns => {
                if (returns && returns.length > 0) {
                    const div = document.getElementById('returnHistoryDiv_PR_' + p.id);
                    if (div) {
                        renderReturnTimeline(div, returns, grandTotal, paidAmt);
                    }
                }
            }).catch(e => { });
        } catch (err) {
            console.error('viewDetail Error:', err);
            toast('Could not load details', 'error');
        }
    };

    window.loadDirectSales = async function (filter = 'today', btn = null) {
        if (btn) {
            ['dsPBtnToday', 'dsPBtnYesterday'].forEach(id => {
                const b = document.getElementById(id);
                if (b) { b.style.borderColor = ''; b.style.color = ''; b.style.background = ''; }
            });
            btn.style.borderColor = 'var(--primary)';
            btn.style.color = '#fff';
            btn.style.background = 'var(--primary)';
        }
        try {
            const res = await api('/api/direct_sales/list?filter=' + encodeURIComponent(filter));
            const data = Array.isArray(res) ? res : (res.data || []);
            const tbody = document.getElementById('dsPBody');
            const empty = document.getElementById('dsPEmpty');
            if (!tbody || !empty) return; // not on pharmacy page
            if (data.length === 0) {
                tbody.innerHTML = '';
                empty.style.display = 'block';
                return;
            }
            empty.style.display = 'none';
            tbody.innerHTML = data.map((r, i) => {
                const dt = r.created_at ? r.created_at.replace('T', ' ').substring(11, 16) : '-';
                const statusBadge = r.status === 'completed'
                    ? '<span class="badge" style="background:var(--success-bg,#d1fae5);color:var(--success);">Paid</span>'
                    : '<span class="badge" style="background:#fff1f2;color:var(--danger);">Pending</span>';
                const medicines = r.medicines || [];
                const injCost = parseFloat(r.injection_cost) || 0;
                const ivCost = parseFloat(r.iv_cost) || 0;
                const uptCost = parseFloat(r.upt_cost) || 0;
                let subtotal = medicines.reduce((s, m) => s + (parseFloat(m.amount) || 0), 0) + injCost + ivCost + uptCost;
                const disc = parseFloat(r.discount_percent) || 0;
                if (disc > 0) subtotal = subtotal - subtotal * (disc / 100);

                return `<tr>
                    <td>${i + 1}</td>
                    <td>${dt}</td>
                    <td><strong>${r.customer_name}</strong></td>
                    <td>${r.mobile_number || '-'}</td>
                    <td>₹${subtotal.toFixed(2)}</td>
                    <td>${statusBadge}</td>
                    <td><button class="btn btn-outline btn-sm" onclick='viewDirectSaleDetailP(${JSON.stringify(r).replace(/'/g, "&apos;")})'>View</button></td>
                </tr>`;
            }).join('');
        } catch (e) { toast('Failed to load direct sales', 'error'); }
    };

    window.viewDirectSaleDetailP = function (sale) {
        window.currentReturnContext = {
            sale_type: 'direct_sale',
            sale_id: sale.id,
            patient_name: sale.customer_name,
            bill_number: 'DS-' + sale.id,
            medicines: sale.medicines || [],
            balance_amount: parseFloat(sale.balance_amount) || 0,
            paid_amount: parseFloat(sale.paid_amount) || 0,
            total_amount: parseFloat(sale.total_amount) || 0
        };

        const medicines = sale.medicines || [];
        const injCost = parseFloat(sale.injection_cost) || 0;
        const ivCost = parseFloat(sale.iv_cost) || 0;
        const uptCost = parseFloat(sale.upt_cost) || 0;
        const disc = parseFloat(sale.discount_percent) || 0;
        const cashAmt = parseFloat(sale.cash_amount) || 0;
        const gpayAmt = parseFloat(sale.gpay_amount) || 0;
        const phonepeAmt = parseFloat(sale.phonepe_amount) || 0;
        const bankAmt = parseFloat(sale.bank_amount) || 0;
        const paidAmt = parseFloat(sale.paid_amount) || 0;
        const balAmt = parseFloat(sale.balance_amount) || 0;
        const dt = sale.created_at ? sale.created_at.replace('T', ' ').substring(0, 16) : '-';

        let medTotal = medicines.reduce((s, m) => s + (parseFloat(m.amount) || 0), 0);
        let subtotal = medTotal + injCost + ivCost + uptCost;
        let discAmt = subtotal * (disc / 100);
        let grand = subtotal - discAmt;

        let medsHtml = '';
        if (medicines.length > 0) {
            medsHtml = `<table class="data-table" style="margin-bottom:10px;">
                <thead><tr><th>#</th><th>Medicine</th><th>Qty</th><th>Unit Price</th><th>Amount</th></tr></thead>
                <tbody>${medicines.map((m, i) => {
                const retQty = parseFloat(m.returned_qty) || 0;
                const qtyHtml = retQty > 0 ? `${m.qty} <span style="color:var(--danger);font-size:0.8rem;">(-${retQty})</span>` : m.qty;
                return `<tr>
                    <td>${i + 1}</td>
                    <td>${m.name}</td>
                    <td>${qtyHtml}</td>
                    <td>₹${(parseFloat(m.unit_price) || 0).toFixed(2)}</td>
                    <td>₹${(parseFloat(m.amount) || 0).toFixed(2)}</td>
                </tr>`;
            }).join('')}</tbody>
            </table>`;
        }

        let extrasHtml = '';
        if (injCost > 0) extrasHtml += `<div class="total-row" style="font-size:0.9rem;padding:6px 0;border:none;"><span>Injection:</span><span>₹${injCost.toFixed(2)}</span></div>`;
        if (ivCost > 0) extrasHtml += `<div class="total-row" style="font-size:0.9rem;padding:6px 0;border:none;"><span>IV Fluid:</span><span>₹${ivCost.toFixed(2)}</span></div>`;
        if (uptCost > 0) extrasHtml += `<div class="total-row" style="font-size:0.9rem;padding:6px 0;border:none;"><span>UPT Card:</span><span>₹${uptCost.toFixed(2)}</span></div>`;
        if (disc > 0) extrasHtml += `<div class="total-row" style="font-size:0.9rem;padding:6px 0;border:none;color:var(--danger);"><span>Discount (${disc}%):</span><span>-₹${discAmt.toFixed(2)}</span></div>`;

        const paymentMode = [
            cashAmt > 0 ? `Cash ₹${cashAmt.toFixed(2)}` : '',
            gpayAmt > 0 ? `GPay ₹${gpayAmt.toFixed(2)}` : '',
            phonepeAmt > 0 ? `PhonePe ₹${phonepeAmt.toFixed(2)}` : '',
            bankAmt > 0 ? `Bank ₹${bankAmt.toFixed(2)}` : ''
        ].filter(Boolean).join(' + ') || '-';

        let historyHtml = '';
        try {
            const hist = typeof sale.payment_history === 'string' ? JSON.parse(sale.payment_history) : (sale.payment_history || []);
            if (hist && hist.length > 0) {
                historyHtml = `<div style="margin-top:16px;">
                    <h4 style="margin-bottom:10px; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">Payment History</h4>
                    <table class="data-table" style="font-size:0.85rem;">
                        <thead><tr><th>Date & Time</th><th>Methods</th><th>Amount</th></tr></thead>
                        <tbody>${hist.map(h => `<tr><td>${h.date} ${h.time}</td><td>${h.method}</td><td><span style="color:var(--success); font-weight:600;">₹${parseFloat(h.total_cleared).toFixed(2)}</span></td></tr>`).join('')}</tbody>
                    </table>
                </div>`;
            }
        } catch (e) { }

        const clearBtnHtml = balAmt > 0 ? `<div style="margin-top:16px; text-align:right;"><button class="btn btn-warning" onclick="openPendingPaymentModal(${sale.id}, ${balAmt})">Clear Pending Payment</button></div>` : '';

        $('#detailBody').innerHTML = `
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; background:var(--bg-secondary); padding:14px; border-radius:var(--radius);">
                <div><div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:2px;">Customer Name</div><div style="font-weight:600;">${sale.customer_name}</div></div>
                <div><div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:2px;">Mobile</div><div style="font-weight:600;">${sale.mobile_number || '-'}</div></div>
                <div><div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:2px;">Date & Time</div><div>${dt}</div></div>
                <div><div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:2px;">Status</div><div style="font-weight:600; color:${sale.status === 'completed' ? 'var(--success)' : 'var(--danger)'};">${sale.status === 'completed' ? 'Paid' : 'Pending'}</div></div>
            </div>
            <div style="margin-bottom:16px;">
                <h4 style="margin-bottom:10px; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">Purchase Details</h4>
                ${medsHtml}
                ${extrasHtml}
            </div>
            <div style="background:var(--bg-secondary); padding:14px; border-radius:var(--radius);">
                <h4 style="margin-bottom:10px; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">Payment Details</h4>
                <div class="total-row" style="padding:6px 0;"><span>Grand Total:</span><span style="font-weight:700; color:var(--primary);">₹${grand.toFixed(2)}</span></div>
                <div class="total-row" style="padding:6px 0;font-size:0.9rem;"><span>Overall Mode(s):</span><span>${paymentMode}</span></div>
                <div class="total-row" style="padding:6px 0;font-size:0.9rem;"><span>Total Paid:</span><span style="color:var(--success);">₹${paidAmt.toFixed(2)}</span></div>
                ${balAmt > 0 ? `<div class="total-row" style="padding:6px 0;font-size:0.9rem;"><span>Balance Due:</span><span style="color:var(--danger); font-weight:700;">₹${balAmt.toFixed(2)}</span></div>` : ''}
            </div>
            ${historyHtml}
            ${clearBtnHtml}
            <div id="returnHistoryDiv_DS_${sale.id}"></div>
        `;
        openModal('detailModal');

        api(`/api/returns_history?sale_id=${sale.id}&sale_type=direct_sale`).then(returns => {
            if (returns && returns.length > 0) {
                const div = document.getElementById('returnHistoryDiv_DS_' + sale.id);
                if (div) {
                    let trueMedTotal = parseFloat(sale.total_amount) || 0;
                    let trueSubtotal = trueMedTotal + injCost + ivCost + uptCost;
                    let trueDiscAmt = trueSubtotal * (disc / 100);
                    let trueRetained = trueSubtotal - trueDiscAmt;
                    renderReturnTimeline(div, returns, trueRetained, paidAmt);
                }
            }
        }).catch(e => { });
    };

    window.downloadPDF = async function () {
        if (!currentPrescId) return;
        try {
            // Get patient data
            let p = (window.currentPatients || []).find(x => String(x.presc_id) === String(currentPrescId));
            if (!p) {
                const res = await api('/api/whatsapp_link/' + currentPrescId);
                if (res.data) p = res.data;
            }
            if (!p) {
                const patients = await api(`/api/patients?as_role=${PAGE_ROLE}`);
                p = patients.find(x => String(x.presc_id) === String(currentPrescId));
            }
            if (!p) return toast('Patient data not found', 'error');
            // Generate bill image using same canvas as WhatsApp
            const canvas = await generateBillCanvas(p);
            canvas.toBlob((blob) => {
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `Bill_${p.name}_${p.token}.jpg`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                toast('Bill downloaded successfully!', 'success');
            }, 'image/jpeg', 0.95);
        } catch (err) {
            console.error('PDF download error:', err);
            toast('Failed to generate bill', 'error');
        }
    };

    window.sendWhatsApp = async function () {
        if (!currentPrescId) return;
        try {
            const res = await api('/api/whatsapp_link/' + currentPrescId);
            if (res.link) window.open(res.link, '_blank');
        } catch (err) {
            toast('Could not generate WhatsApp link', 'error');
        }
    };

    window.generateBillCanvas = function (p) {
        return new Promise((resolve, reject) => {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = 800;

                const fmtTime = (ts) => {
                    if (!ts) return '-';
                    if (ts instanceof Date) {
                        return ts.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true }).toLowerCase();
                    }
                    try {
                        if (typeof ts !== 'string') ts = String(ts);
                        const parts = ts.split(' ');
                        if (parts.length === 2) {
                            const [d, t] = parts;
                            const [y, m, day] = d.split('-').map(Number);
                            const [h, min, s] = t.split(':').map(Number);
                            const dt = new Date(y, m - 1, day, h, min, s);
                            if (!isNaN(dt.getTime())) return dt.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true }).toLowerCase();
                        }
                        const dt = new Date(ts);
                        if (!isNaN(dt.getTime())) return dt.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true }).toLowerCase();
                    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
                    return ts;
                };

                const medicines = p.medicines || [];
                const otherFees = [
                    { label: 'Doctor Fee:', val: parseFloat(p.consultation_fee) || 0 },
                    { label: 'Medicine Total:', val: parseFloat(p.total_amount) || 0 },
                    { label: 'Scan Fee:', val: parseFloat(p.scan_fee) || 0 },
                    { label: 'Injection Fee:', val: parseFloat(p.injection_cost) || 0 },
                    { label: 'IV Fluid Fee:', val: parseFloat(p.iv_cost) || 0 },
                    { label: 'UPT Card Fee:', val: parseFloat(p.upt_cost) || 0 }
                ];

                const activeFees = otherFees.filter(f => f.val > 0 || f.label === 'Doctor Fee:' || f.label === 'Medicine Total:');

                // Calculate height dynamically to prevent clipping
                const numMeds = medicines.length;
                const numFees = activeFees.length;
                let dynamicHeight = 310 + (numMeds * 30) + 10 + 50 + (numFees * 25) + 60 + 80 + 60 + 100;
                if (p.prev_balance_info) dynamicHeight += 150;
                canvas.height = Math.max(1000, dynamicHeight);

                // Background
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                // Header
                ctx.fillStyle = '#0f172a'; // Deep slate navy
                ctx.fillRect(0, 0, canvas.width, 110);

                ctx.fillStyle = '#38bdf8'; // Border line below header
                ctx.fillRect(0, 110, canvas.width, 4);

                // Header text (centered)
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 30px Helvetica';
                ctx.textAlign = 'center';
                ctx.fillText('Crescent Clinic and Scans', canvas.width / 2, 45);

                ctx.font = '16px Helvetica';
                ctx.fillStyle = '#e2e8f0';
                ctx.fillText('Medical Prescription & Bill', canvas.width / 2, 72);

                const headerTime = new Date().toLocaleString('en-IN', { hour12: true }).toLowerCase();
                ctx.font = '13px Helvetica';
                ctx.fillStyle = '#38bdf8';
                ctx.fillText(headerTime, canvas.width / 2, 95);

                ctx.textAlign = 'left'; // reset alignment to left

                // Patient Information Title
                ctx.fillStyle = '#0f172a';
                ctx.font = 'bold 16px Helvetica';
                ctx.fillText('PATIENT INFORMATION', 50, 145);

                ctx.strokeStyle = '#38bdf8';
                ctx.lineWidth = 2;
                ctx.beginPath(); ctx.moveTo(50, 155); ctx.lineTo(750, 155); ctx.stroke();
                ctx.lineWidth = 1;

                // Patient Info Inline key-value helper
                const drawInlineKV = (label, val, x, y) => {
                    ctx.fillStyle = '#475569';
                    ctx.font = '14px Helvetica';
                    ctx.fillText(label + ': ', x, y);
                    const labelWidth = ctx.measureText(label + ': ').width;
                    ctx.fillStyle = '#0f172a';
                    ctx.font = '14px Helvetica';
                    ctx.fillText(val || '-', x + labelWidth, y);
                };

                const timeIn = fmtTime(p.created_at);
                const timeOut = p.completed_at ? fmtTime(p.completed_at) : fmtTime(new Date());

                drawInlineKV('Name', p.name, 50, 180);
                drawInlineKV('Phone', p.phone, 50, 205);
                drawInlineKV('Age / Gender', `${p.age} / ${p.gender}`, 50, 230);
                drawInlineKV('Patient ID', p.patient_id || '-', 50, 255);

                drawInlineKV('Token', p.token, 420, 180);
                drawInlineKV('Doctor', formatDoctorName(p.doctor_name, p.doctor_type), 420, 205);
                drawInlineKV('Patient In', timeIn, 420, 230);
                drawInlineKV('Patient Out', timeOut, 420, 255);

                // Vitals
                const vitals = [];
                if (p.bp) vitals.push(`BP: ${p.bp}`);
                if (p.temp) vitals.push(`Temp: ${p.temp}`);
                if (p.pulse) vitals.push(`Pulse: ${p.pulse}`);
                if (p.weight) vitals.push(`Weight: ${p.weight}`);
                if (p.height) vitals.push(`Height: ${p.height}`);
                if (vitals.length > 0) {
                    ctx.fillStyle = '#475569';
                    ctx.font = 'bold 13px Helvetica';
                    ctx.fillText(vitals.join(' | '), 50, 285);
                }

                // Table Header
                let y = 310;
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(50, y, 700, 35);

                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 13px Helvetica';
                ctx.fillText('Medicine', 65, y + 22);
                ctx.textAlign = 'center';
                ctx.fillText('Qty', 510, y + 22);
                ctx.textAlign = 'right';
                ctx.fillText('Price', 650, y + 22);
                ctx.fillText('Amount', 735, y + 22);
                ctx.textAlign = 'left';

                y += 35;

                // Medicine Rows
                medicines.forEach((m, idx) => {
                    if (idx % 2 === 1) {
                        ctx.fillStyle = '#f8fafc';
                        ctx.fillRect(50, y, 700, 30);
                    }
                    ctx.fillStyle = '#334155';
                    ctx.font = '14px Helvetica';
                    ctx.fillText(m.name, 65, y + 20);

                    ctx.textAlign = 'center';
                    ctx.fillText(String(m.qty), 510, y + 20);

                    ctx.textAlign = 'right';
                    const uprice = parseFloat(m.unit_price) || (m.qty > 0 ? (parseFloat(m.amount) / m.qty) : 0);
                    ctx.fillText('₹' + uprice.toFixed(2), 650, y + 20);
                    ctx.fillText('₹' + (parseFloat(m.amount) || 0).toFixed(2), 735, y + 20);
                    ctx.textAlign = 'left';

                    y += 30;
                });

                ctx.strokeStyle = '#e2e8f0';
                ctx.beginPath(); ctx.moveTo(50, y); ctx.lineTo(750, y); ctx.stroke();
                y += 10;

                // Payment Summary Section
                y += 20;
                ctx.fillStyle = '#0f172a';
                ctx.font = 'bold 16px Helvetica';
                ctx.fillText('PAYMENT SUMMARY', 50, y);

                ctx.strokeStyle = '#e2e8f0';
                ctx.beginPath(); ctx.moveTo(50, y + 10); ctx.lineTo(750, y + 10); ctx.stroke();
                y += 35;

                const drawSummaryRow = (label, val, rowY, isBold = false, color = '#334155') => {
                    ctx.fillStyle = color;
                    ctx.font = isBold ? 'bold 15px Helvetica' : '14px Helvetica';
                    ctx.fillText(label, 65, rowY);
                    ctx.textAlign = 'right';
                    ctx.fillText('₹' + parseFloat(val).toFixed(2), 735, rowY);
                    ctx.textAlign = 'left';
                };

                activeFees.forEach(f => {
                    drawSummaryRow(f.label, f.val, y);
                    y += 25;
                });

                ctx.strokeStyle = '#e2e8f0';
                ctx.beginPath(); ctx.moveTo(50, y - 10); ctx.lineTo(750, y - 10); ctx.stroke();

                let gtotal = (parseFloat(p.total_amount) || 0) + (parseFloat(p.consultation_fee) || 0) + (parseFloat(p.scan_fee) || 0) + (parseFloat(p.injection_cost) || 0) + (parseFloat(p.iv_cost) || 0) + (parseFloat(p.upt_cost) || 0);
                const discountPercent = parseFloat(p.discount_percent) || 0;

                if (discountPercent > 0) {
                    drawSummaryRow('Subtotal:', gtotal, y);
                    y += 25;
                    const discountAmt = gtotal * (discountPercent / 100);
                    drawSummaryRow(`Discount (${discountPercent}%):`, -discountAmt, y, false, '#be123c');
                    y += 25;
                    gtotal -= discountAmt;
                }

                drawSummaryRow('Grand Total:', gtotal, y, true, '#0f172a');
                y += 15;

                ctx.strokeStyle = '#e2e8f0';
                ctx.beginPath(); ctx.moveTo(50, y - 5); ctx.lineTo(750, y - 5); ctx.stroke();
                y += 20;

                // Payment Breakdown Section
                drawSummaryRow('Paid via Cash:', p.cash_amount || 0, y);
                y += 25;
                drawSummaryRow('Paid via GPay:', p.gpay_amount || 0, y);
                y += 25;
                drawSummaryRow('Paid via PhonePe:', p.phonepe_amount || 0, y);
                y += 25;
                drawSummaryRow('Total Paid:', p.paid_amount || 0, y, true, '#0f172a');
                y += 15;

                ctx.strokeStyle = '#e2e8f0';
                ctx.beginPath(); ctx.moveTo(50, y - 5); ctx.lineTo(750, y - 5); ctx.stroke();
                y += 30;

                // Balance Status Section
                const bal = parseFloat(p.balance_amount) || 0;
                const paidAmt = parseFloat(p.paid_amount) || 0;

                if (bal > 0 || (paidAmt > 0 && paidAmt < gtotal)) {
                    const finalBal = bal > 0 ? bal : (gtotal - paidAmt);
                    ctx.fillStyle = '#be123c'; // Red-700
                    ctx.font = 'bold 18px Helvetica';
                    ctx.fillText('Balance Pending: ₹' + finalBal.toFixed(2), 65, y);
                    y += 25;
                    ctx.fillStyle = '#e11d48'; // Red-600
                    ctx.font = 'italic 14px Helvetica';
                    ctx.fillText('Pending Amount: ₹' + finalBal.toFixed(2) + ' to be paid later', 65, y);
                } else if (paidAmt > gtotal) {
                    const retAmt = paidAmt - gtotal;
                    ctx.fillStyle = '#ca8a04'; // Yellow-600
                    ctx.font = 'bold 18px Helvetica';
                    ctx.fillText('Balance Return: ₹' + retAmt.toFixed(2), 65, y);
                    y += 25;
                    ctx.fillStyle = '#eab308'; // Yellow-500
                    ctx.font = 'italic 14px Helvetica';
                    ctx.fillText('Return Amount: ₹' + retAmt.toFixed(2) + ' to be returned to patient', 65, y);
                } else {
                    ctx.fillStyle = '#16a34a'; // Green-600
                    ctx.font = 'bold 18px Helvetica';
                    ctx.fillText('Balance Pending: ₹0.00', 65, y);
                }

                if (p.prev_balance_info) {
                    y += 30;
                    ctx.strokeStyle = '#e2e8f0';
                    ctx.beginPath(); ctx.moveTo(50, y); ctx.lineTo(750, y); ctx.stroke();
                    y += 30;

                    ctx.fillStyle = '#0f172a';
                    ctx.font = 'bold 16px Helvetica';
                    ctx.fillText('PREVIOUS PENDING BALANCE', 50, y);
                    y += 25;

                    const pinfo = p.prev_balance_info;
                    if (pinfo.cleared > 0) {
                        ctx.fillStyle = '#475569';
                        ctx.font = '14px Helvetica';
                        ctx.fillText('Previous Visit Date: ' + (pinfo.date || '-'), 65, y);
                        y += 20;
                        drawSummaryRow('Original Pending Balance:', pinfo.original, y, false, '#475569');
                        y += 25;
                        drawSummaryRow('Amount Cleared:', pinfo.cleared, y, false, '#475569');
                        y += 25;
                        drawSummaryRow('Remaining Pending Balance:', pinfo.remaining, y, true, '#be123c');
                    } else if (pinfo.remaining > 0) {
                        drawSummaryRow('Previous Visit Pending Balance:', pinfo.remaining, y, true, '#be123c');
                        y += 25;
                        ctx.fillStyle = '#475569';
                        ctx.font = '14px Helvetica';
                        ctx.fillText('Previous Visit Date: ' + (pinfo.date || '-'), 65, y);
                        y += 25;
                        ctx.font = 'italic 13px Helvetica';
                        ctx.fillText(`You visited the clinic on ${pinfo.date || '-'} and an outstanding balance of ₹${pinfo.remaining.toFixed(2)} is still pending.`, 65, y);
                    }
                }

                // Footer
                ctx.fillStyle = '#94a3b8';
                ctx.font = 'italic 12px Helvetica';
                ctx.textAlign = 'center';
                ctx.fillText('This is a computer-generated medical prescription bill. No signature required.', canvas.width / 2, canvas.height - 40);

                resolve(canvas);
            } catch (err) {
                console.error('generateBillCanvas Error:', err);
                reject(err);
            }
        });
    };

    window.sendWhatsAppImage = async function (pArg = null, waWinArg = null) {
        let p = pArg;
        let waWin = waWinArg;

        // Try to get patient data from cache synchronously if not provided
        if (!p && currentPrescId) {
            p = (window.currentPatients || []).find(x => String(x.presc_id) === String(currentPrescId));
        }

        // If we have patient data, do everything synchronously to preserve user gesture
        if (p) {
            let clipboardDone = false;

            try {
                // generateBillCanvas Promise constructor runs synchronously — gesture stays valid
                const canvas = await generateBillCanvas(p);

                // Convert canvas to PNG Blob SYNCHRONOUSLY (toDataURL is sync, unlike toBlob)
                const dataUrl = canvas.toDataURL('image/png');
                const bin = atob(dataUrl.split(',')[1]);
                const bytes = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                const pngBlob = new Blob([bytes], { type: 'image/png' });

                // Write to clipboard — still within user gesture!
                await navigator.clipboard.write([new ClipboardItem({ 'image/png': pngBlob })]);
                clipboardDone = true;
                toast('✅ Bill image copied! Press Ctrl+V in WhatsApp chat to paste & Send.', 'success', 6000);
            } catch (e) {
                console.error('Clipboard copy failed:', e);
            }

            // Open WhatsApp to patient's chat
            let phone = (p.phone || '').replace(/[^0-9]/g, '');
            if (phone.length === 10) phone = '91' + phone;

            // Fetch the beautiful designed text message link from API
            let waUrl = '';
            try {
                const endpoint = (p.token && p.token.startsWith('DS-'))
                    ? `/api/whatsapp_link/direct/${p.id}`
                    : `/api/whatsapp_link/${p.presc_id || currentPrescId}`;
                const resLink = await api(endpoint);
                if (resLink && resLink.link) {
                    waUrl = resLink.link;
                }
            } catch (e) {
                console.error('Failed to fetch whatsapp link:', e);
            }

            if (!waUrl) {
                waUrl = phone ? 'https://wa.me/' + phone : '';
            }

            if (waUrl) {
                if (waWin) {
                    waWin.location.href = waUrl;
                } else {
                    window.open(waUrl, '_blank');
                }
            } else if (waWin) {
                waWin.close();
            }

            // Fallback: download file if clipboard failed
            if (!clipboardDone) {
                try {
                    const canvas2 = await generateBillCanvas(p);
                    const jpgDataUrl = canvas2.toDataURL('image/jpeg', 0.95);
                    const dlLink = document.createElement('a');
                    dlLink.href = jpgDataUrl;
                    dlLink.download = `Bill_${p.name}_${p.token}.jpg`;
                    document.body.appendChild(dlLink);
                    dlLink.click();
                    document.body.removeChild(dlLink);
                } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
                toast('Bill downloaded. Attach it in the WhatsApp chat using 📎 button.', 'warning', 6000);
            }
            return;
        }

        // Fallback: p was not available — need async API call (gesture already expired for clipboard)
        try {
            if (!currentPrescId) throw new Error("No prescription selected");
            const resLink = await api('/api/whatsapp_link/' + currentPrescId);
            if (resLink && resLink.data) {
                p = resLink.data;
            } else {
                throw new Error("Patient data not found");
            }

            // Generate and download the bill (clipboard won't work without gesture)
            const canvas = await generateBillCanvas(p);
            const jpgDataUrl = canvas.toDataURL('image/jpeg', 0.95);
            const dlLink = document.createElement('a');
            dlLink.href = jpgDataUrl;
            dlLink.download = `Bill_${p.name}_${p.token}.jpg`;
            document.body.appendChild(dlLink);
            dlLink.click();
            document.body.removeChild(dlLink);

            // Open WhatsApp chat
            let phone = (p.phone || '').replace(/[^0-9]/g, '');
            if (phone.length === 10) phone = '91' + phone;

            let waUrl = resLink.link;
            if (!waUrl) {
                waUrl = phone ? 'https://wa.me/' + phone : '';
            }

            if (waUrl) {
                if (waWin) waWin.location.href = waUrl;
                else window.open(waUrl, '_blank');
            } else if (waWin) {
                waWin.close();
            }
            toast('Bill downloaded. Attach it in the WhatsApp chat using 📎 button.', 'warning', 6000);
        } catch (err) {
            console.error('WhatsApp Flow Error:', err);
            toast('WhatsApp sharing encountered an error.', 'error');
            if (waWin) waWin.close();
        }
    };

    // ═══════════════════════════════════════════
    // SHARED: LOAD PATIENTS & REFRESH
    // ═══════════════════════════════════════════
    window.handleRefresh = async function (btn) {
        if (btn) {
            const svg = btn.querySelector('svg');
            if (svg) svg.classList.add('spin');
            btn.disabled = true;
        }
        await loadPatients();
        if (btn) {
            const svg = btn.querySelector('svg');
            if (svg) svg.classList.remove('spin');
            btn.disabled = false;
        }
        toast('Data refreshed successfully', 'success');
    };

    window.togglePrevBalanceUpi = function () {
        const methodEl = document.getElementById('clearPrevBalanceMethod');
        const upiGroup = document.getElementById('clearPrevBalanceUpiGroup');
        if (methodEl && upiGroup) {
            if (methodEl.value === 'UPI') {
                upiGroup.style.display = 'block';
            } else {
                upiGroup.style.display = 'none';
            }
        }
    };

    window.clearPrevBalance = async function () {
        if (!window.currentPatientPhone) return;
        const inputEl = document.getElementById('clearPrevBalanceInput');
        if (!inputEl) return;

        const amountToClear = parseFloat(inputEl.value);
        if (isNaN(amountToClear) || amountToClear <= 0) {
            toast('Please enter a valid positive amount to clear', 'error');
            return;
        }

        const methodEl = document.getElementById('clearPrevBalanceMethod');
        const method = methodEl ? methodEl.value : 'Cash';
        const upiAccountEl = document.getElementById('clearPrevBalanceUpiAccount');
        const upiAccount = (method === 'UPI' && upiAccountEl) ? upiAccountEl.value : null;

        if (method === 'UPI' && !upiAccount) {
            toast('Please select a UPI Account', 'error');
            return;
        }

        const balanceText = document.getElementById('prevBalanceAmount').textContent;
        const currentBalance = parseFloat(balanceText.replace('₹', '')) || 0;

        if (amountToClear > currentBalance) {
            toast(`Cannot clear more than the pending balance of ₹${currentBalance.toFixed(2)}`, 'error');
            return;
        }

        if (!confirm(`Are you sure you want to clear ₹${amountToClear.toFixed(2)}?`)) return;

        try {
            await api('/api/clear_balances/' + window.currentPatientPhone, {
                method: 'POST',
                body: {
                    amount: amountToClear,
                    current_presc_id: window.currentPrescId || null,
                    payment_method: method,
                    upi_account: upiAccount
                }
            });
            toast(`₹${amountToClear.toFixed(2)} cleared successfully!`);

            inputEl.value = '';
            const newBalance = Math.max(0, currentBalance - amountToClear);

            const container = document.getElementById('prevBalanceContainer');
            const formSec = document.getElementById('prevBalanceForm');
            const statusSec = document.getElementById('prevBalanceStatusSection');
            const statusMsg = document.getElementById('prevBalanceStatusMsg');
            const remainingMsg = document.getElementById('prevBalanceRemainingMsg');
            const statusLabel = document.getElementById('prevBalanceStatusLabel');
            const amountDisplay = document.getElementById('prevBalanceAmount');

            if (amountDisplay) amountDisplay.textContent = '₹' + newBalance.toFixed(2);

            if (statusSec && statusMsg && remainingMsg && statusLabel) {
                statusSec.style.display = 'block';
                if (newBalance === 0) {
                    if (container) {
                        container.style.background = '#f0fdf4';
                        container.style.borderColor = '#bbf7d0';
                    }
                    if (formSec) formSec.style.display = 'none';

                    statusMsg.textContent = 'Pending Fee Cleared Successfully';
                    statusMsg.style.color = '#15803d';

                    remainingMsg.textContent = 'Remaining Pending Fee: ₹0';
                    remainingMsg.style.color = '#166534';

                    statusLabel.textContent = 'Fully Cleared';
                    statusLabel.style.background = '#dcfce7';
                    statusLabel.style.color = '#15803d';
                } else {
                    if (container) {
                        container.style.background = '#fffbeb';
                        container.style.borderColor = '#fcd34d';
                    }

                    statusMsg.textContent = 'Pending Fee Updated Successfully';
                    statusMsg.style.color = '#b45309';

                    remainingMsg.textContent = `Remaining Pending Fee: ₹${newBalance.toFixed(2)}`;
                    remainingMsg.style.color = '#78350f';

                    statusLabel.textContent = 'Partially Cleared';
                    statusLabel.style.background = '#fef3c7';
                    statusLabel.style.color = '#b45309';
                }
            }
            await loadPatients();
        } catch (e) {
            toast('Failed to clear balance', 'error');
        }
    };

    window.loadPatients = async function () {
        try {
            const patients = await api(`/api/patients?as_role=${PAGE_ROLE}`);

            if (PAGE_ROLE === 'receptionist') {
                window.currentPatients = patients;
                renderReceptionistTable(patients);
                if (window.updateTokenGrid) window.updateTokenGrid();
            } else if (PAGE_ROLE === 'doctor') {
                const stats = await api('/api/doctor_stats');
                const st = $('#statTotal'); if (st) st.textContent = stats.total;
                const sw = $('#statWaiting'); if (sw) sw.textContent = stats.waiting;
                const sc = $('#statConsulted'); if (sc) sc.textContent = stats.consulted;
                renderDoctorQueue(patients);
            } else if (PAGE_ROLE === 'pharmacist') {
                window.currentPatients = patients;
                const stats = await api('/api/pharmacy_stats');
                const sr = $('#statRevenue'); if (sr) sr.textContent = '₹' + stats.total_revenue.toFixed(2);

                const statsContainer = $('#dynamicPharmacyStats');
                const navContainer = $('#dynamicPharmacyNav');

                if (statsContainer && stats.doctor_stats) {
                    let statsHtml = '';
                    let navHtml = `
                        <button class="nav-item active" onclick="showPharmacySection('Prescriptions', this)" id="navFilterAll">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            All Patients
                        </button>
                        <button class="nav-item" onclick="showPharmacySection('DirectSale', this)" style="color: var(--primary); font-weight: 600; margin-top: 8px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                            Direct Medicine
                        </button>
                        <hr style="border:none;border-top:1px solid var(--border);margin:10px 0;">
                    `;

                    const colors = ['blue', 'purple', 'green', 'orange', 'red'];
                    let colorIdx = 0;

                    for (const [did, data] of Object.entries(stats.doctor_stats)) {
                        const color = colors[colorIdx % colors.length];
                        colorIdx++;

                        const grandTotal = (data.fees + data.medicine_total + data.scans).toFixed(2);

                        statsHtml += `
                            <div class="stat-card" id="cardFilter${did}" style="cursor: pointer; transition: transform 0.2s;" onclick="setPharmacyFilter('${did}', this)" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
                                <div class="stat-icon ${color}"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a8.38 8.38 0 010-2c0-3 2.5-5.5 6.5-5.5s6.5 2.5 6.5 5.5a8.38 8.38 0 010 2"/></svg></div>
                                <div>
                                    <div class="stat-value">${data.patients}</div>
                                    <div class="stat-label">${formatDoctorName(data.doctor_name, data.doctor_type)}</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 8px; line-height: 1.6;">
                                        Medicine Fees: <span>₹${data.medicine_total.toFixed(2)}</span><br>
                                        Doctor Fees: <span>₹${data.fees.toFixed(2)}</span><br>
                                        Scan Fees: <span>₹${data.scans.toFixed(2)}</span>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 8px; font-weight: 500; border-top: 1px solid var(--border); padding-top: 8px;">
                                        Total: <span>₹${grandTotal}</span>
                                    </div>
                                </div>
                            </div>
                        `;

                        navHtml += `
                            <button class="nav-item" onclick="setPharmacyFilter('${did}', this)" id="navFilter${did}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                ${formatDoctorName(data.doctor_name, data.doctor_type)} (${data.patients})
                            </button>
                        `;
                    }

                    navHtml += `
                        <hr style="border:none;border-top:1px solid var(--border);margin:10px 0;">
                        <button class="nav-item" onclick="showPharmacySection('Inventory', this)" style="margin-top: 8px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                            Pharmacy Inventory
                        </button>
                        <button class="nav-item" onclick="showPharmacySection('Agency', this)" style="margin-top: 8px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                            Agency Inventory
                        </button>
                    `;

                    statsContainer.innerHTML = statsHtml;
                    if (navContainer) navContainer.innerHTML = navHtml;
                }

                renderPharmacyTable(patients);
            }
            return patients;
        } catch (err) {
            console.error('Load patients error:', err);
            return [];
        }
    };

    function renderReceptionistTable(patients) {
        const container = $('#dynamicPatientsContainer');
        const statsContainer = $('#dynamicStatsContainer');
        const statTotal = $('#statTotal');

        if (statTotal) statTotal.textContent = patients.length;
        if (!container) return;

        let doctors = window.allDoctors || [];
        // Extract unique doctors from patients if allDoctors isn't loaded yet
        if (doctors.length === 0) {
            const uniqueIds = [...new Set(patients.map(p => p.doctor_id))].filter(Boolean);
            doctors = uniqueIds.map(id => {
                const p = patients.find(px => px.doctor_id === id);
                return { id: id, doctor_type: p.doctor_type, display_name: p.doctor_name };
            });
        }

        let html = '';
        let statsHtml = '';

        const colors = ['purple', 'green', 'blue', 'orange', 'red'];

        doctors.forEach((doc, idx) => {
            const did = doc.id;
            const docPatients = patients.filter(p => String(p.doctor_id) === String(did));

            const color = colors[idx % colors.length];

            if (statsContainer) {
                statsHtml += `
                    <div class="stat-card">
                        <div class="stat-icon ${color}"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="7" r="4" />
                                <path
                                    d="M5.5 21a8.38 8.38 0 010-2c0-3 2.5-5.5 6.5-5.5s6.5 2.5 6.5 5.5a8.38 8.38 0 010 2" />
                            </svg></div>
                        <div>
                            <div class="stat-value">${docPatients.length}</div>
                            <div class="stat-label">${formatDoctorName(doc.display_name, doc.doctor_type)} Queue</div>
                        </div>
                    </div>
                `;
            }

            html += `
                <div class="content-card" style="margin-bottom: 32px;">
                    <div class="card-header">
                        <h2 style="flex: 1;">${formatDoctorName(doc.display_name, doc.doctor_type)}</h2>
                        <button class="btn btn-outline btn-sm" onclick="handleRefresh(this)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="23 4 23 10 17 10" />
                                <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10" />
                            </svg>
                            Refresh
                        </button>
                    </div>
                    <div class="card-body-np">
            `;

            if (docPatients.length === 0) {
                html += `
                        <div class="empty-state">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2" />
                                <circle cx="9" cy="7" r="4" />
                            </svg>
                            <p>No patients yet</p>
                        </div>
                `;
            } else {
                html += `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Token</th>
                                    <th>Patient ID</th>
                                    <th>Name</th>
                                    <th>Age/Gender</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                html += docPatients.map(p => {
                    const statusClass = p.status === 'waiting' ? 'badge-waiting'
                        : p.status === 'prescribed' ? 'badge-consulted' : 'badge-completed';
                    const displayStatus = p.status === 'prescribed' ? 'Consulted' : p.status;
                    return `<tr>
                        <td><strong>${p.token}</strong></td>
                        <td><span style="font-size:0.8rem; color:var(--text-secondary); font-weight:600;">${p.patient_id || '-'}</span></td>
                        <td>${p.name}</td>
                        <td>${p.age} / ${p.gender}</td>
                        <td>${p.phone}</td>
                        <td><span class="badge ${statusClass}" style="text-transform: capitalize;">${displayStatus}</span></td>
                    </tr>`;
                }).join('');
                html += `
                            </tbody>
                        </table>
                `;
            }
            html += `
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
        if (statsContainer) statsContainer.innerHTML = statsHtml;
    }

    // Prevent mouse wheel from changing number inputs
    document.addEventListener('wheel', function (event) {
        if (document.activeElement.type === 'number') {
            document.activeElement.blur();
        }
    }, { passive: true });

    // ─── PENDING PAYMENT MODAL LOGIC ───
    window.togglePpInputs = async function () {
        if ($('#ppCashCheck') && $('#ppCashGroup')) $('#ppCashGroup').style.display = $('#ppCashCheck').checked ? 'block' : 'none';
        if ($('#ppGPayCheck') && $('#ppGPayGroup')) {
            $('#ppGPayGroup').style.display = $('#ppGPayCheck').checked ? 'block' : 'none';
            if ($('#ppGPayCheck').checked) {
                try { await window.loadGlobalUpiAccounts(); } catch (e) { }
                if ($('#ppUpiAccount')) $('#ppUpiAccount').focus();
            }
        }
        if ($('#ppPhonePeCheck') && $('#ppPhonePeGroup')) $('#ppPhonePeGroup').style.display = $('#ppPhonePeCheck').checked ? 'block' : 'none';
        if ($('#ppBankCheck') && $('#ppBankGroup')) $('#ppBankGroup').style.display = $('#ppBankCheck').checked ? 'block' : 'none';
        if ($('#ppCashCheck') && !$('#ppCashCheck').checked && $('#ppCashAmount')) $('#ppCashAmount').value = 0;
        if ($('#ppGPayCheck') && !$('#ppGPayCheck').checked && $('#ppGPayAmount')) $('#ppGPayAmount').value = 0;
        if ($('#ppPhonePeCheck') && !$('#ppPhonePeCheck').checked && $('#ppPhonePeAmount')) $('#ppPhonePeAmount').value = 0;
        if ($('#ppBankCheck') && !$('#ppBankCheck').checked && $('#ppBankAmount')) $('#ppBankAmount').value = 0;
        updatePpBalance();
    };

    window.updatePpBalance = function () {
        const due = parseFloat($('#ppRemainingBalance') ? $('#ppRemainingBalance').getAttribute('data-due') : 0) || 0;
        const paid = (parseFloat($('#ppCashAmount') ? $('#ppCashAmount').value : 0) || 0) +
            (parseFloat($('#ppGPayAmount') ? $('#ppGPayAmount').value : 0) || 0) +
            (parseFloat($('#ppPhonePeAmount') ? $('#ppPhonePeAmount').value : 0) || 0) +
            (parseFloat($('#ppBankAmount') ? $('#ppBankAmount').value : 0) || 0);
        const rem = Math.max(0, due - paid);
        if ($('#ppRemainingBalance')) $('#ppRemainingBalance').textContent = '₹' + rem.toFixed(2);
    };

    window.openPendingPaymentModal = function (saleId, balAmt) {
        if ($('#ppSaleId')) $('#ppSaleId').value = saleId;
        if ($('#ppAmountDue')) $('#ppAmountDue').textContent = '₹' + parseFloat(balAmt).toFixed(2);
        if ($('#ppRemainingBalance')) {
            $('#ppRemainingBalance').setAttribute('data-due', balAmt);
            $('#ppRemainingBalance').textContent = '₹' + parseFloat(balAmt).toFixed(2);
        }

        if ($('#ppCashCheck')) $('#ppCashCheck').checked = false;
        if ($('#ppGPayCheck')) $('#ppGPayCheck').checked = false;
        if ($('#ppPhonePeCheck')) $('#ppPhonePeCheck').checked = false;
        if ($('#ppBankCheck')) $('#ppBankCheck').checked = false;

        togglePpInputs();

        closeModal('detailModal');
        const dsDetailModal = document.getElementById('dsSaleDetailModal');
        if (dsDetailModal) dsDetailModal.classList.remove('active');
        const medBreakdownModal = document.getElementById('medicineBreakdownModal');
        if (medBreakdownModal) medBreakdownModal.classList.remove('active');
        const docBreakdownModal = document.getElementById('doctorBreakdownModal');
        if (docBreakdownModal) docBreakdownModal.classList.remove('active');

        openModal('pendingPaymentModal');
    };

    window.submitPendingPayment = async function () {
        const payload = {
            sale_id: $('#ppSaleId').value,
            cash_amount: parseFloat($('#ppCashAmount').value) || 0,
            gpay_amount: parseFloat($('#ppGPayAmount').value) || 0,
            phonepe_amount: 0,
            bank_amount: parseFloat($('#ppBankAmount').value) || 0,
            upi_account: $('#ppGPayCheck').checked ? $('#ppUpiAccount').value : null
        };
        if ($('#ppGPayCheck').checked && !payload.upi_account && payload.gpay_amount > 0) {
            return toast('Please select a Bank Account.', 'error');
        }
        const total = payload.cash_amount + payload.gpay_amount + payload.phonepe_amount + payload.bank_amount;
        if (total <= 0) return toast('Enter payment amount', 'error');

        const due = parseFloat($('#ppRemainingBalance').getAttribute('data-due')) || 0;
        if (total > due) return toast('Payment amount cannot exceed remaining balance', 'error');

        try {
            const res = await api('/api/direct_sales/pay_pending', { method: 'POST', body: payload });
            if (res.success) {
                toast('Payment successful!');
                closeModal('pendingPaymentModal');
                if (window.loadDirectSales) window.loadDirectSales();
                if (typeof window.loadDirectSalesAdmin === 'function') window.loadDirectSalesAdmin();
            } else {
                toast(res.error || 'Payment failed', 'error');
            }
        } catch (e) { toast(e.message || 'Payment failed', 'error'); }
    };

    // Return Medicine Logic
    window.openReturnModal = async function (visitOrId, type) {
        let ctx = null;
        if (typeof visitOrId === 'object' && visitOrId !== null) {
            ctx = {
                sale_type: type,
                sale_id: visitOrId.presc_id || visitOrId.id,
                patient_name: visitOrId.customer_name || visitOrId.name || 'Patient',
                bill_number: (type === 'prescription' ? 'PR-' : 'DS-') + (visitOrId.presc_id || visitOrId.id),
                medicines: visitOrId.medicines || [],
                balance_amount: parseFloat(visitOrId.balance_amount) || 0,
                paid_amount: parseFloat(visitOrId.paid_amount) || 0,
                total_amount: parseFloat(visitOrId.total_amount) || 0,
                discount_percent: parseFloat(visitOrId.discount_percent) || 0
            };
        } else if (window.currentReturnContext) {
            ctx = window.currentReturnContext;
        }

        if (!ctx || !ctx.sale_id || !ctx.medicines.length) {
            return toast('No medicines available for return in this bill.', 'error');
        }

        // Fetch TPS bulk
        let names = ctx.medicines.map(m => m.name);
        try {
            const res = await api('/api/inventory/bulk_tps', { method: 'POST', body: { names } });
            ctx.medicines.forEach(m => { m.tps = res[m.name] || 1; });
        } catch (e) {
            ctx.medicines.forEach(m => { m.tps = 1; });
        }

        document.getElementById('rmSaleId').value = ctx.sale_id;
        document.getElementById('rmSaleType').value = ctx.sale_type;
        document.getElementById('rmPatientName').textContent = ctx.patient_name;
        document.getElementById('rmBillNumber').textContent = ctx.bill_number;

        const tbody = document.getElementById('rmBody');
        tbody.innerHTML = '';
        let hasReturnable = false;

        ctx.medicines.forEach((m, idx) => {
            const soldQty = parseFloat(m.qty) || 0;
            const alreadyReturned = parseFloat(m.returned_qty) || 0;
            const available = Math.max(0, soldQty - alreadyReturned);
            const amount = parseFloat(m.amount) || 0;
            const unitPrice = soldQty > 0 ? (amount / soldQty) : 0;

            if (available > 0) hasReturnable = true;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${m.name}</td>
                <td>${soldQty}</td>
                <td style="color:var(--danger);">${alreadyReturned}</td>
                <td style="font-weight:bold;">${available}</td>
                <td>₹${unitPrice.toFixed(2)}</td>
                <td>
                    <select class="rm-type-select" data-tps="${m.tps}" onchange="calculateTotalRefund()" ${available === 0 ? 'disabled' : ''} style="width:110px; padding:4px;">
                        <option value="Single Tablet">Single Tablet</option>
                        <option value="Strip">Strip (x${m.tps})</option>
                    </select>
                </td>
                <td>
                    <input type="number" class="rm-qty-input" data-name="${m.name.replace(/"/g, '&quot;')}" data-max="${available}" data-price="${unitPrice}" min="0" value="0" ${available === 0 ? 'disabled' : ''} oninput="calculateTotalRefund()" style="width:80px;">
                </td>
            `;
            tbody.appendChild(tr);
        });

        window.calculateTotalRefund = function () {
            let total = 0;
            document.querySelectorAll('#rmBody tr').forEach(tr => {
                const inp = tr.querySelector('.rm-qty-input');
                const sel = tr.querySelector('.rm-type-select');
                if (!inp || !sel) return;

                let qty = parseFloat(inp.value) || 0;
                const price = parseFloat(inp.getAttribute('data-price')) || 0;
                const max = parseFloat(inp.getAttribute('data-max')) || 0;
                const tps = parseInt(sel.getAttribute('data-tps')) || 1;

                const returnType = sel.value;
                let equiv = qty;
                if (returnType === 'Strip') {
                    equiv = qty * tps;
                }

                if (equiv > max) {
                    inp.style.borderColor = 'red';
                } else {
                    inp.style.borderColor = '';
                }

                total += equiv * price;
            });

            const disc = parseFloat(ctx ? ctx.discount_percent : 0) || 0;
            const postDiscountTotal = total - (total * (disc / 100));

            const bal = parseFloat(ctx ? ctx.balance_amount : 0) || 0;
            const adjusted = Math.min(postDiscountTotal, bal);
            const netRefund = Math.max(0, postDiscountTotal - adjusted);

            const totalReturnValEl = document.getElementById('rmTotalReturnVal');
            if (totalReturnValEl) totalReturnValEl.textContent = '₹' + total.toFixed(2);

            const outstandingBalanceEl = document.getElementById('rmOutstandingBalance');
            if (outstandingBalanceEl) outstandingBalanceEl.textContent = '₹' + bal.toFixed(2);

            const balanceAdjustedEl = document.getElementById('rmBalanceAdjusted');
            if (balanceAdjustedEl) balanceAdjustedEl.textContent = '₹' + adjusted.toFixed(2);

            const totalRefundEl = document.getElementById('rmTotalRefund');
            if (totalRefundEl) totalRefundEl.textContent = '₹' + netRefund.toFixed(2);

            const refundModeEl = document.getElementById('rmRefundMode');
            if (refundModeEl) {
                if (bal > 0 && total > 0) {
                    if (netRefund === 0) {
                        refundModeEl.value = 'Adjusted in Balance';
                    } else if (refundModeEl.value === 'Adjusted in Balance') {
                        refundModeEl.value = 'Cash';
                    }
                }
            }
        };
        calculateTotalRefund(); // Initialize to 0.00

        if (!hasReturnable) {
            return toast('All medicines from this bill have already been returned.', 'error');
        }

        document.getElementById('rmReason').value = '';
        openModal('returnMedicineModal');
    };

    window.submitReturn = async function () {
        const sale_id = document.getElementById('rmSaleId').value;
        const sale_type = document.getElementById('rmSaleType').value;
        const reason = document.getElementById('rmReason').value;

        const returns = [];
        const inputs = document.querySelectorAll('.rm-qty-input');

        for (let inp of inputs) {
            const val = parseFloat(inp.value) || 0;
            const max = parseFloat(inp.getAttribute('data-max')) || 0;
            const name = inp.getAttribute('data-name');
            const sel = inp.closest('tr').querySelector('.rm-type-select');
            const tps = parseInt(sel.getAttribute('data-tps')) || 1;
            const returnType = sel.value;

            let equiv = val;
            if (returnType === 'Strip') {
                equiv = val * tps;
            }

            if (val > 0) {
                if (equiv > max) {
                    return toast(`Cannot return more than ${max} tablets for ${name}`, 'error');
                }
                returns.push({ name, qty: val, reason, return_type: returnType, equivalent_tablets: equiv });
            }
        }

        if (returns.length === 0) {
            return toast('Please enter a quantity to return for at least one medicine.', 'error');
        }

        if (!confirm(`Are you sure you want to return ${returns.length} item(s)? This will increase inventory stock and adjust the bill totals.`)) {
            return;
        }

        const totalReturnStr = (document.getElementById('rmTotalReturnVal') ? document.getElementById('rmTotalReturnVal').textContent : '0').replace('₹', '');
        const total_return_amount = parseFloat(totalReturnStr) || 0;

        const balanceAdjustedStr = (document.getElementById('rmBalanceAdjusted') ? document.getElementById('rmBalanceAdjusted').textContent : '0').replace('₹', '');
        const balance_adjusted = parseFloat(balanceAdjustedStr) || 0;

        const totalRefundStr = document.getElementById('rmTotalRefund').textContent.replace('₹', '');
        const total_refund_amount = parseFloat(totalRefundStr) || 0;
        const refund_payment_mode = document.getElementById('rmRefundMode').value;

        try {
            const payload = { sale_id, sale_type, returns, total_return_amount, total_refund_amount, refund_payment_mode, balance_adjusted };
            const res = await api('/api/return_medicines', { method: 'POST', body: payload });

            if (res.success) {
                toast('Return processed successfully!', 'success');
                closeModal('returnMedicineModal');

                // Refresh views depending on where we are
                if (sale_type === 'prescription') {
                    if (window.loadPatientsList) loadPatientsList(); // Admin
                    if (window.loadPatients) window.loadPatients(); // Pharmacy
                    if (document.getElementById('patientSearch')) {
                        // If we are in detailModal, close it to force user to click View again to see updated data
                        closeModal('detailModal');
                    }
                } else {
                    if (window.loadDirectSalesAdmin) loadDirectSalesAdmin();
                    if (window.loadDirectSales) window.loadDirectSales();
                    closeModal('dsSaleDetailModal');
                    closeModal('detailModal');
                }
            } else {
                toast(res.error || 'Return failed', 'error');
            }
        } catch (err) {
            toast(err.message || 'Error processing return', 'error');
        }
    };

})();



// Global Date Formatter removed to allow free manual typing/editing of slashes and hyphens.

window.renderReturnTimeline = function (container, returns, currentGrandTotal = 0, currentPaid = 0) {
    // Group by date and time
    const groups = [];
    const groupsMap = {};
    returns.forEach(r => {
        const key = r.return_date + ' ' + r.return_time;
        if (!groupsMap[key]) {
            groupsMap[key] = {
                key: key,
                datetime: key,
                items: [],
                refund_payment_mode: r.refund_payment_mode,
                total_refund_amount: parseFloat(r.total_refund_amount) || 0,
                id: r.id
            };
            groups.push(groupsMap[key]);
        }
        groupsMap[key].items.push(r);
    });

    // Sort groups by ID ascending to compute running balances chronologically
    groups.sort((a, b) => a.id - b.id);

    // Compute original state
    const totalReturnsValSum = returns.reduce((sum, r) => sum + (parseFloat(r.return_amount) || 0), 0);
    const totalRefundsSum = groups.reduce((sum, g) => sum + g.total_refund_amount, 0);

    const G0 = currentGrandTotal + totalReturnsValSum;
    const P0 = currentPaid + totalRefundsSum;
    const B0 = Math.max(0.0, G0 - P0);

    let previousBalance = B0;
    groups.forEach(g => {
        g.previousBalance = previousBalance;
        g.groupReturnVal = g.items.reduce((sum, item) => sum + (parseFloat(item.return_amount) || 0), 0);
        g.groupAdjusted = Math.min(g.groupReturnVal, previousBalance);
        g.nextBalance = Math.max(0, previousBalance - g.groupAdjusted);
        previousBalance = g.nextBalance;
    });

    // Reverse to render newest first
    groups.reverse();

    let html = '<h4 style="margin-top:20px; margin-bottom:12px; font-size:0.95rem; text-transform:uppercase; color:#be123c; border-bottom:1px solid #fda4af; padding-bottom:4px;">Return Timeline</h4>';
    html += '<div style="display:flex; flex-direction:column; gap:16px; position:relative; padding-left:16px; border-left:2px solid #fda4af; margin-left:8px;">';

    groups.forEach(g => {
        const first = g.items[0];
        const reason = first.reason ? `<div style="font-size:0.8rem; color:var(--text-muted); font-style:italic;">Reason: ${first.reason}</div>` : '';
        const processedBy = first.processed_by ? `<div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Processed by: ${first.processed_by}</div>` : '';

        let medRows = '';
        g.items.forEach(i => {
            const qty = parseFloat(i.returned_qty) || 0;
            const price = parseFloat(i.unit_price) || 0;
            const amt = parseFloat(i.return_amount) || (qty * price);
            const rType = i.return_type && i.return_type !== 'Single Tablet' ? ` <span style="font-size:0.7rem; background:#fee2e2; padding:1px 4px; border-radius:4px; margin-left:4px;">${i.return_type}</span>` : '';
            medRows += `<div style="display:flex; justify-content:space-between; font-size:0.85rem; padding:4px 0; border-bottom:1px dashed #fecdd3;">
                    <span>${i.medicine_name} (x${qty})${rType}</span>
                    <span>₹${amt.toFixed(2)}</span>
                </div>`;
        });

        const refundMode = g.refund_payment_mode || 'Cash';
        const refundAmt = g.total_refund_amount;
        const balanceStatusText = g.nextBalance > 0
            ? `<span style="background:#fee2e2; color:#b91c1c; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:700;">Balance: ₹${g.nextBalance.toFixed(2)} (Pending)</span>`
            : `<span style="background:#dcfce7; color:#15803d; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:700;">Balance: ₹0.00 (Fully Paid)</span>`;

        html += `
                <div style="position:relative;">
                    <div style="position:absolute; width:10px; height:10px; background:#e11d48; border-radius:50%; left:-22px; top:4px; box-shadow:0 0 0 3px #fff1f2;"></div>
                    <div style="background:#fff1f2; border:1px solid #fecdd3; border-radius:8px; padding:12px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center; flex-wrap:wrap; gap:6px;">
                            <span style="font-weight:600; color:#9f1239; font-size:0.85rem;">Returned on ${g.datetime}</span>
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <span style="background:#fee2e2; color:#be123c; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:700;">Return Value: ₹${g.groupReturnVal.toFixed(2)}</span>
                                ${g.groupAdjusted > 0 ? `<span style="background:#fef3c7; color:#d97706; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:700;">Adjusted: -₹${g.groupAdjusted.toFixed(2)}</span>` : ''}
                                <span style="background:#fecdd3; color:#9f1239; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:700;">Refund: ₹${refundAmt.toFixed(2)} (${refundMode})</span>
                                ${balanceStatusText}
                            </div>
                        </div>
                        ${reason}
                        <div style="margin-top:8px;">${medRows}</div>
                        ${processedBy}
                    </div>
                </div>
            `;
    });

    if (currentGrandTotal !== null) {
        html += `<div style="margin-top:16px; margin-left:24px; padding:12px; background:var(--bg-secondary); border-radius:8px; border: 1px dashed var(--border); border-left:4px solid var(--primary); display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:600; font-size:0.9rem; color:var(--text-secondary);">Balance Retained After Returns:</span>
                <span style="font-weight:800; font-size:1.05rem; color:var(--primary);">₹${currentGrandTotal.toFixed(2)}</span>
            </div>`;
    }

    html += '</div>';
    container.innerHTML = html;
};

window.searchGenericNames = async function (inputEl) {
    const q = inputEl.value.trim();
    let suggBox = inputEl.nextElementSibling;
    if (!suggBox || !suggBox.classList.contains('generic-suggestions')) {
        suggBox = document.createElement('div');
        suggBox.className = 'generic-suggestions';
        suggBox.style.cssText = "display:none; position:absolute; top:100%; left:0; width:100%; z-index:1000; background:var(--bg-card); border:1px solid var(--border); border-radius:4px; max-height:200px; overflow-y:auto; box-shadow:0 8px 16px rgba(0,0,0,0.5);";
        inputEl.parentNode.insertBefore(suggBox, inputEl.nextSibling);
        inputEl.parentNode.style.position = 'relative';
    }

    if (q.length < 2) {
        suggBox.style.display = 'none';
        return;
    }

    try {
        const results = await api('/api/generics/search?q=' + encodeURIComponent(q));
        if (results && results.length > 0) {
            suggBox.innerHTML = results.map(name => `
                <div class="generic-sugg-item" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid var(--border); transition: background 0.15s; background: var(--bg-card); color: var(--text-primary);"
                     onmouseenter="this.style.background='var(--bg-hover)'"
                     onmouseleave="this.style.background='var(--bg-card)'"
                     onclick="selectGenericName(this, '${name.replace(/'/g, "\\'")}')">
                    ${name}
                </div>
            `).join('');
            suggBox.style.display = 'block';
        } else {
            suggBox.style.display = 'none';
        }
    } catch (e) {
        console.error(e);
    }
};

window.selectGenericName = function (itemEl, name) {
    const suggBox = itemEl.closest('.generic-suggestions');
    const inputEl = suggBox.previousElementSibling;
    inputEl.value = name;
    suggBox.style.display = 'none';
};

document.addEventListener('click', function (e) {
    if (!e.target.closest('.generic-suggestions') && !e.target.classList.contains('purc-generic') && e.target.id !== 'agItemGeneric' && e.target.id !== 'invGenericName') {
        document.querySelectorAll('.generic-suggestions').forEach(box => {
            box.style.display = 'none';
        });
    }
});
