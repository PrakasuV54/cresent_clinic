// Agency Inventory System JavaScript

let agencyItemsData = [];
let agencyCategoriesData = [];
let agencySuppliersData = [];
let currentPurcRows = 0;

function switchAgencyTab(tab, btn) {
    document.querySelectorAll('.agency-tab-content').forEach(el => el.style.display = 'none');
    document.getElementById('agency-' + tab).style.display = 'block';

    document.querySelectorAll('#agencyTabs .btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    if (tab === 'dashboard') loadAgencyDashboard();
    if (tab === 'categories') loadAgencyCategories();
    if (tab === 'suppliers') loadAgencySuppliers();
    if (tab === 'items') loadAgencyItems();
    if (tab === 'purchases') { loadAgencySuppliersForSelect(); loadAgencyPurchases(); }
    if (tab === 'stock') loadAgencyItemsForSelect();
    if (tab === 'reports') loadAgencyReports();
    if (tab === 'generics') loadGenericMedicines();
}

async function loadAgencyDashboard() {
    try {
        const res = await api('/api/agency/dashboard');
        document.getElementById('agDashItems').textContent = res.total_items || 0;
        document.getElementById('agDashQty').textContent = res.total_stock_qty || 0;
        document.getElementById('agDashValue').textContent = parseFloat(res.total_stock_value || 0).toFixed(2);

        const itemsRes = await api('/api/agency/items');
        const lowCount = itemsRes.filter(i => i.stock > 0 && i.stock <= (i.min_stock || 0)).length;
        const outCount = itemsRes.filter(i => i.stock === 0).length;
        if (document.getElementById('agDashLow')) document.getElementById('agDashLow').textContent = lowCount;
        if (document.getElementById('agDashOut')) document.getElementById('agDashOut').textContent = outCount;
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

// â• â• â• â• â• â• â• â•  CATEGORIES â• â• â• â• â• â• â• â• 
async function loadAgencyCategories() {
    try {
        agencyCategoriesData = await api('/api/agency/categories');
        document.getElementById('agCatBody').innerHTML = agencyCategoriesData.map(c => `
            <tr>
                <td><strong>${c.name}</strong></td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick='viewItemsByCategory(${JSON.stringify(c.name).replace(/'/g, "\\'")})'>View Items</button>
                    <button class="btn btn-outline btn-sm" onclick='editAgencyCat(${JSON.stringify(c).replace(/'/g, "\\'")})'>Edit</button>
                    <button class="btn btn-outline btn-sm" style="color:var(--danger);" onclick="deleteAgencyCat(${c.id})">Delete</button>
                </td>
            </tr>
        `).join('');
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

function openAgencyCatModal() {
    document.getElementById('agCatId').value = '';
    document.getElementById('agCatName').value = '';
    document.getElementById('agCatNameOther').value = '';
    document.getElementById('agCatNameOther').style.display = 'none';
    document.getElementById('agCatNameOther').required = false;
    openModal('agCatModal');
}

function editAgencyCat(c) {
    document.getElementById('agCatId').value = c.id;

    const sel = document.getElementById('agCatName');
    const other = document.getElementById('agCatNameOther');

    // Check if category name is one of the standard options
    let isStandard = false;
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === c.name && c.name !== 'Other' && c.name !== '') {
            isStandard = true;
            break;
        }
    }

    if (isStandard) {
        sel.value = c.name;
        other.style.display = 'none';
        other.required = false;
        other.value = '';
    } else {
        sel.value = 'Other';
        other.style.display = 'block';
        other.required = true;
        other.value = c.name;
    }

    openModal('agCatModal');
}

async function saveAgencyCat() {
    try {
        let catName = document.getElementById('agCatName').value;
        if (catName === 'Other') {
            catName = document.getElementById('agCatNameOther').value.trim();
        }
        const payload = {
            id: document.getElementById('agCatId').value,
            name: catName
        };
        await api('/api/agency/categories/add', { method: 'POST', body: payload });
        toast('Category saved successfully');
        closeModal('agCatModal');
        loadAgencyCategories();
    } catch (e) {
        toast('Error saving category', 'error');
    }
}

async function deleteAgencyCat(id) {
    if (!confirm('Are you sure you want to delete this category?')) return;
    try {
        await api(`/api/agency/categories/delete/${id}`, { method: 'DELETE' });
        toast('Category deleted');
        loadAgencyCategories();
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

async function viewItemsByCategory(catName) {
    try {
        if (agencyItemsData.length === 0) {
            agencyItemsData = await api('/api/agency/items');
        }

        const filtered = agencyItemsData.filter(i => i.category === catName);

        const title = `Items in Category: ${catName}`;
        const head = `<tr><th>Item Code</th><th>Name</th><th>Batch Number</th><th>Stock</th></tr>`;
        let body = '';

        if (filtered.length === 0) {
            body = `<tr><td colspan="4" style="text-align:center;">No items found in this category.</td></tr>`;
        } else {
            body = filtered.map(i => `
                <tr>
                    <td>${i.item_code || '-'}</td>
                    <td><strong>${i.item_name}</strong><br><span style="font-size:0.8em; color:var(--text-secondary);">${i.agency_name || 'No Agency'}</span></td>
                    <td><span class="badge" style="background:var(--bg-hover);color:var(--text-primary);">${i.batch_number}</span></td>
                    <td><span style="${i.stock <= i.min_stock ? 'color:var(--danger);font-weight:bold;' : ''}">${i.stock}</span></td>
                </tr>
            `).join('');
        }

        document.getElementById('agStockDetailsTitle').textContent = title;
        document.getElementById('agStockDetailsHead').innerHTML = head;
        document.getElementById('agStockDetailsBody').innerHTML = body;
        openModal('agStockDetailsModal');
    } catch (e) {
        toast('Failed to load items', 'error');
    }
}

// â• â• â• â• â• â• â• â•  SUPPLIERS â• â• â• â• â• â• â• â• 
async function loadAgencySuppliers() {
    try {
        agencySuppliersData = await api('/api/agency/suppliers');
        document.getElementById('agSuppBody').innerHTML = agencySuppliersData.map(s => {
            let total_purchased = parseFloat(s.total_purchase || 0).toFixed(2);
            let total_paid = parseFloat(s.paid_amount || 0).toFixed(2);
            let total_pending = parseFloat(s.pending_balance || 0).toFixed(2);

            let paid_or_not_html = '';
            let pending_html = '';

            if (s.payment_status === 'Paid' || (total_pending <= 0 && total_purchased > 0)) {
                paid_or_not_html = `<span style="color:var(--emerald); font-weight:bold;">Paid</span>`;
                pending_html = `<span style="color:var(--text-secondary);">₹ 0.00</span>`;
            } else if (s.payment_status === 'Not Paid' && total_paid <= 0) {
                paid_or_not_html = `<span style="color:var(--danger); font-weight:bold;">Not Paid</span>`;
                pending_html = `<span style="color:var(--danger); font-weight:bold;">₹ ${total_pending}</span>`;
            } else {
                paid_or_not_html = `<span style="color:var(--primary); font-weight:bold;">₹ ${total_paid}</span>`;
                pending_html = `<span style="color:var(--danger); font-weight:bold;">₹ ${total_pending}</span>`;
            }

            return `
            <tr>
                <td><strong>${s.name}</strong></td>
                <td>${s.phone || '-'}</td>
                <td>${s.gst_number || '-'}</td>
                <td style="color:var(--primary); font-weight:bold;">₹ ${total_purchased}</td>
                <td>${paid_or_not_html}</td>
                <td>${pending_html}</td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick="openSupplierDetails(${s.id})">View Details</button>
                    <button class="btn btn-outline btn-sm" onclick="editAgencySupp(JSON.parse(decodeURIComponent('${encodeURIComponent(JSON.stringify(s))}')))">Edit</button>
                    <button class="btn btn-outline btn-sm" style="color:var(--danger);" onclick="deleteAgencySupp(${s.id})">Delete</button>
                </td>
            </tr>
        `}).join('');
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

async function loadAgencySuppliersForSelect() {
    try {
        if (agencySuppliersData.length === 0) agencySuppliersData = await api('/api/agency/suppliers');
        const options = agencySuppliersData.map(s => `<option value="${s.name}">${s.company_name ? s.company_name + ' - ' : ''}${s.name}</option>`).join('');
        const sel = document.getElementById('agPurcSuppList');
        if (sel) sel.innerHTML = options;
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

function openAgencySuppModal() {
    document.getElementById('agSuppId').value = '';
    if (document.getElementById('agSuppName')) document.getElementById('agSuppName').value = '';
    if (document.getElementById('agSuppComp')) document.getElementById('agSuppComp').value = '';
    if (document.getElementById('agSuppPhone')) document.getElementById('agSuppPhone').value = '';
    if (document.getElementById('agSuppWhatsapp')) document.getElementById('agSuppWhatsapp').value = '';
    if (document.getElementById('agSuppEmail')) document.getElementById('agSuppEmail').value = '';
    if (document.getElementById('agSuppGst')) document.getElementById('agSuppGst').value = '';
    if (document.getElementById('agSuppDl')) document.getElementById('agSuppDl').value = '';
    if (document.getElementById('agSuppPayment')) document.getElementById('agSuppPayment').value = '';
    if (document.getElementById('agSuppAddress')) document.getElementById('agSuppAddress').value = '';

    document.getElementById('agSuppTotalPurch').value = '0';
    document.querySelector('input[name="agSuppPayStatus"][value="Not Paid"]').checked = true;
    document.getElementById('agSuppPending').value = '0';
    document.getElementById('agSuppPaid').value = '0';
    document.getElementById('agSuppCash').value = '0';
    document.getElementById('agSuppGpay').value = '0';

    if (document.getElementById('agSuppCity')) document.getElementById('agSuppCity').value = '';
    if (document.getElementById('agSuppState')) document.getElementById('agSuppState').value = '';
    if (document.getElementById('agSuppPincode')) document.getElementById('agSuppPincode').value = '';
    if (document.getElementById('agSuppStatus')) document.getElementById('agSuppStatus').value = 'Active';
    if (document.getElementById('agSuppOutstanding')) document.getElementById('agSuppOutstanding').value = '0';

    openModal('agSuppModal');
}

function editAgencySupp(s) {
    document.getElementById('agSuppId').value = s.id;
    if (document.getElementById('agSuppName')) document.getElementById('agSuppName').value = s.name;
    if (document.getElementById('agSuppComp')) document.getElementById('agSuppComp').value = s.company_name || '';
    if (document.getElementById('agSuppPhone')) document.getElementById('agSuppPhone').value = s.phone || '';
    if (document.getElementById('agSuppWhatsapp')) document.getElementById('agSuppWhatsapp').value = s.whatsapp || '';
    if (document.getElementById('agSuppEmail')) document.getElementById('agSuppEmail').value = s.email || '';
    if (document.getElementById('agSuppGst')) document.getElementById('agSuppGst').value = s.gst_number || '';
    if (document.getElementById('agSuppDl')) document.getElementById('agSuppDl').value = s.dl_number || '';
    if (document.getElementById('agSuppPayment')) document.getElementById('agSuppPayment').value = s.payment_type || '';
    if (document.getElementById('agSuppAddress')) document.getElementById('agSuppAddress').value = s.address || '';

    document.getElementById('agSuppTotalPurch').value = s.total_purchase || 0;
    document.getElementById('agSuppPayStatus').value = s.payment_status || 'Not Paid';
    document.getElementById('agSuppPending').value = s.pending_balance || 0;
    document.getElementById('agSuppPaid').value = s.paid_amount || 0;
    document.getElementById('agSuppCash').value = s.cash_amount || 0;
    document.getElementById('agSuppGpay').value = s.gpay_amount || 0;

    if (document.getElementById('agSuppCity')) document.getElementById('agSuppCity').value = s.city || '';
    if (document.getElementById('agSuppState')) document.getElementById('agSuppState').value = s.state || '';
    if (document.getElementById('agSuppPincode')) document.getElementById('agSuppPincode').value = s.pincode || '';
    if (document.getElementById('agSuppStatus')) document.getElementById('agSuppStatus').value = s.status || 'Active';
    if (document.getElementById('agSuppOutstanding')) document.getElementById('agSuppOutstanding').value = s.outstanding_balance || 0;

    openModal('agSuppModal');
}

function calcSupplierPayment(source = 'status') {
    let totalPurch = parseFloat(document.getElementById('agSuppTotalPurch').value) || 0;
    let status = document.getElementById('agSuppPayStatus').value;

    let pendingInput = document.getElementById('agSuppPending');
    let paidInput = document.getElementById('agSuppPaid');

    if (status === 'Paid') {
        pendingInput.value = 0;
        paidInput.value = totalPurch.toFixed(2);
    } else {
        if (source === 'pending') {
            let pending = parseFloat(pendingInput.value) || 0;
            if (pending > totalPurch) {
                pendingInput.value = totalPurch;
                pending = totalPurch;
            }
            paidInput.value = (totalPurch - pending).toFixed(2);
        } else {
            pendingInput.value = totalPurch.toFixed(2);
            paidInput.value = 0;
        }
    }
}

async function saveAgencySupp() {
    try {
        const payload = {
            id: document.getElementById('agSuppId') ? document.getElementById('agSuppId').value : '',
            name: document.getElementById('agSuppName') ? document.getElementById('agSuppName').value : '',
            company_name: document.getElementById('agSuppComp') ? document.getElementById('agSuppComp').value : '',
            phone: document.getElementById('agSuppPhone') ? document.getElementById('agSuppPhone').value : '',
            whatsapp: document.getElementById('agSuppWhatsapp') ? document.getElementById('agSuppWhatsapp').value : '',
            email: document.getElementById('agSuppEmail') ? document.getElementById('agSuppEmail').value : '',
            gst_number: document.getElementById('agSuppGst') ? document.getElementById('agSuppGst').value : '',
            dl_number: document.getElementById('agSuppDl') ? document.getElementById('agSuppDl').value : '',
            payment_type: document.getElementById('agSuppPayment') ? document.getElementById('agSuppPayment').value : '',
            address: document.getElementById('agSuppAddress') ? document.getElementById('agSuppAddress').value : '',
            city: document.getElementById('agSuppCity') ? document.getElementById('agSuppCity').value : '',
            state: document.getElementById('agSuppState') ? document.getElementById('agSuppState').value : '',
            pincode: document.getElementById('agSuppPincode') ? document.getElementById('agSuppPincode').value : '',
            status: document.getElementById('agSuppStatus') ? document.getElementById('agSuppStatus').value : 'Active',
            outstanding_balance: parseFloat(document.getElementById('agSuppOutstanding') ? document.getElementById('agSuppOutstanding').value : 0) || 0,
            total_purchase: parseFloat(document.getElementById('agSuppTotalPurch').value) || 0,
            payment_status: document.getElementById('agSuppPayStatus').value,
            pending_balance: parseFloat(document.getElementById('agSuppPending').value) || 0,
            paid_amount: parseFloat(document.getElementById('agSuppPaid').value) || 0,
            cash_amount: parseFloat(document.getElementById('agSuppCash').value) || 0,
            gpay_amount: parseFloat(document.getElementById('agSuppGpay').value) || 0
        };
        await api('/api/agency/suppliers/add', { method: 'POST', body: payload });
        toast('Supplier saved successfully');
        closeModal('agSuppModal');
        loadAgencySuppliers();
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

async function deleteAgencySupp(id) {
    if (!confirm('Delete supplier?')) return;
    try {
        await api(`/api/agency/suppliers/delete/${id}`, { method: 'DELETE' });
        toast('Deleted successfully');
        loadAgencySuppliers();
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

// â• â• â• â• â• â• â• â•  ITEMS MASTER â• â• â• â• â• â• â• â• 
async function loadAgencyItems() {
    try {
        agencyItemsData = await api('/api/agency/items');
        const q = document.getElementById('agItemSearch').value.toLowerCase();
        let filtered = agencyItemsData;
        if (q) filtered = filtered.filter(i => i.item_name.toLowerCase().includes(q) || i.batch_number.toLowerCase().includes(q));

        document.getElementById('agItemBody').innerHTML = filtered.map(i => `
            <tr>
                <td>${i.item_code || '-'}</td>
                <td>
                    <strong>${i.item_name}</strong><br>
                    <span style="font-size:0.8em; color:var(--text-secondary);">${i.agency_name || 'No Agency'}</span><br>
                    ${i.generic_name ? `<span style="font-size:0.8em; color:#6366f1; font-style:italic;">Generic: ${i.generic_name}</span>` : ''}
                </td>
                <td><span class="badge" style="background:var(--bg-hover);color:var(--text-primary);">${i.batch_number}</span></td>
                <td>${i.category || '-'}</td>
                <td>₹${parseFloat(i.purchase_price || 0).toFixed(2)}</td>
                <td>₹${parseFloat(i.selling_price || 0).toFixed(2)}</td>
                <td><span style="${i.stock <= i.min_stock ? 'color:var(--danger);font-weight:bold;' : ''}">${i.stock}</span></td>
                <td style="white-space: nowrap;">
                    <input type="number" min="0" value="${i.min_stock || 0}" style="width:70px; display:inline-block; text-align:center; padding: 4px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-primary); color: var(--text-primary);" class="form-control" id="ag-item-min-stock-${i.id}">
                    <button class="btn btn-primary btn-sm" style="padding: 4px 8px; font-size: 11px; margin-left: 4px;" onclick="saveLowStockAlert(${i.id}, this)">Save</button>
                </td>
                <td>
                    <button class="btn btn-outline btn-sm" onclick='editAgencyItem(${JSON.stringify(i).replace(/'/g, "\\'")})'>Edit</button>
                    <button class="btn btn-outline btn-sm" style="color:var(--danger);" onclick="deleteAgencyItem(${i.id})">Delete</button>
                </td>
            </tr>
        `).join('');
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

async function loadAgencyItemsForSelect() {
    try {
        if (agencyItemsData.length === 0) agencyItemsData = await api('/api/agency/items');
        const options = '<option value="">Select Item...</option>' + agencyItemsData.map(i => `<option value="${i.id}">${i.item_name} (Agency: ${i.agency_name || 'N/A'}) (Batch: ${i.batch_number}) - Stock: ${i.stock}</option>`).join('');
        document.getElementById('agTransItem').innerHTML = options;
        document.getElementById('agAdjItem').innerHTML = options;
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

async function openAgencyItemModal() {
    if (agencyCategoriesData.length === 0) agencyCategoriesData = await api('/api/agency/categories');
    const catSelect = document.getElementById('agItemCat');

    const standardCategories = ['TAB', 'CAP', 'SYP', 'INJ', 'CRM', 'GEL', 'DROP', 'POW', 'LOT', 'SPRAY', 'OINT', 'Surgical', 'Other'];
    let options = '<option value="">Select...</option>';
    standardCategories.forEach(cat => {
        options += `<option value="${cat}">${cat}</option>`;
    });

    agencyCategoriesData.forEach(c => {
        if (!standardCategories.includes(c.name)) {
            options += `<option value="${c.name}">${c.name}</option>`;
        }
    });

    catSelect.innerHTML = options;

    if (!window.agencySuppliersData || agencySuppliersData.length === 0) {
        try { agencySuppliersData = await api('/api/agency/suppliers'); } catch (e) { agencySuppliersData = []; }
    }
    const suppSelect = document.getElementById('agItemSupplier');
    let suppOptions = '<option value="">Select Supplier...</option>';
    agencySuppliersData.forEach(s => { suppOptions += `<option value="${s.id}">${s.name}</option>`; });
    if (suppSelect) suppSelect.innerHTML = suppOptions;

    document.getElementById('agItemId').value = '';
    document.getElementById('agItemName').value = '';
    if (document.getElementById('agItemGeneric')) document.getElementById('agItemGeneric').value = '';
    if (document.getElementById('agItemBrand')) document.getElementById('agItemBrand').value = '';
    if (document.getElementById('agItemType')) document.getElementById('agItemType').value = '';
    document.getElementById('agItemBatch').value = '';
    document.getElementById('agItemCat').value = '';
    if (document.getElementById('agItemHsn')) document.getElementById('agItemHsn').value = '';
    if (document.getElementById('agItemMfg')) document.getElementById('agItemMfg').value = '';
    if (document.getElementById('agItemExp')) document.getElementById('agItemExp').value = '';
    document.getElementById('agItemPurch').value = '0';
    document.getElementById('agItemSell').value = '0';
    if (document.getElementById('agItemMrp')) document.getElementById('agItemMrp').value = '0';
    if (document.getElementById('agItemDisc')) document.getElementById('agItemDisc').value = '0';
    if (document.getElementById('agItemGst')) document.getElementById('agItemGst').value = '0';
    document.getElementById('agItemStock').value = '0';
    document.getElementById('agItemStock').readOnly = false;

    if (document.getElementById('agItemBarcode')) document.getElementById('agItemBarcode').value = '';
    if (document.getElementById('agItemQrCode')) document.getElementById('agItemQrCode').value = '';
    if (document.getElementById('agItemManufacturer')) document.getElementById('agItemManufacturer').value = '';
    if (document.getElementById('agItemSupplier')) document.getElementById('agItemSupplier').value = '';
    if (document.getElementById('agItemReorderLevel')) document.getElementById('agItemReorderLevel').value = '0';
    if (document.getElementById('agItemMinStock')) document.getElementById('agItemMinStock').value = '0';
    if (document.getElementById('agItemRackLocation')) document.getElementById('agItemRackLocation').value = '';
    if (document.getElementById('agItemGstPercentage')) document.getElementById('agItemGstPercentage').value = '0';
    if (document.getElementById('agItemRow')) document.getElementById('agItemRow').value = '';
    if (document.getElementById('agItemCol')) document.getElementById('agItemCol').value = '';

    openModal('agItemModal');
}

async function editAgencyItem(i) {
    await openAgencyItemModal();
    document.getElementById('agItemId').value = i.id;
    if (document.getElementById('agItemCode')) document.getElementById('agItemCode').value = i.item_code || '';
    document.getElementById('agItemName').value = i.item_name;
    if (document.getElementById('agItemGeneric')) document.getElementById('agItemGeneric').value = i.generic_name || '';
    if (document.getElementById('agItemBrand')) document.getElementById('agItemBrand').value = i.brand_name || '';
    if (document.getElementById('agItemType')) document.getElementById('agItemType').value = i.medicine_type || '';
    document.getElementById('agItemBatch').value = i.batch_number;
    document.getElementById('agItemCat').value = i.category || '';
    if (document.getElementById('agItemHsn')) document.getElementById('agItemHsn').value = i.hsn_code || '';
    if (document.getElementById('agItemMfg')) document.getElementById('agItemMfg').value = i.mfg_date || '';
    if (document.getElementById('agItemExp')) document.getElementById('agItemExp').value = i.expiry_date || '';
    document.getElementById('agItemPurch').value = i.purchase_price;
    document.getElementById('agItemSell').value = i.selling_price;
    if (document.getElementById('agItemMrp')) document.getElementById('agItemMrp').value = i.mrp || 0;
    if (document.getElementById('agItemDisc')) document.getElementById('agItemDisc').value = i.discount || 0;
    if (document.getElementById('agItemGst')) document.getElementById('agItemGst').value = i.gst || 0;
    document.getElementById('agItemStock').value = i.stock;
    document.getElementById('agItemStock').readOnly = false;

    if (document.getElementById('agItemBarcode')) document.getElementById('agItemBarcode').value = i.barcode || '';
    if (document.getElementById('agItemQrCode')) document.getElementById('agItemQrCode').value = i.qr_code || '';
    if (document.getElementById('agItemManufacturer')) document.getElementById('agItemManufacturer').value = i.manufacturer || '';
    if (document.getElementById('agItemSupplier')) document.getElementById('agItemSupplier').value = i.supplier_id || '';
    if (document.getElementById('agItemReorderLevel')) document.getElementById('agItemReorderLevel').value = i.reorder_level || 0;
    if (document.getElementById('agItemMinStock')) document.getElementById('agItemMinStock').value = i.min_stock || 0;
    if (document.getElementById('agItemRackLocation')) document.getElementById('agItemRackLocation').value = i.rack_location || '';
    if (document.getElementById('agItemGstPercentage')) document.getElementById('agItemGstPercentage').value = i.gst_percentage || 0;
    if (document.getElementById('agItemRow')) document.getElementById('agItemRow').value = (i.row_location || '').toUpperCase();
    if (document.getElementById('agItemCol')) document.getElementById('agItemCol').value = (i.col_location || '').toUpperCase();
}

async function saveAgencyItem() {
    try {
        const payload = {
            id: document.getElementById('agItemId').value,
            item_code: document.getElementById('agItemCode') ? document.getElementById('agItemCode').value : '',
            item_name: document.getElementById('agItemName').value,
            generic_name: document.getElementById('agItemGeneric') ? document.getElementById('agItemGeneric').value : '',
            brand_name: document.getElementById('agItemBrand') ? document.getElementById('agItemBrand').value : '',
            medicine_type: document.getElementById('agItemType') ? document.getElementById('agItemType').value : '',
            batch_number: document.getElementById('agItemBatch').value,
            category: document.getElementById('agItemCat').value,
            hsn_code: document.getElementById('agItemHsn') ? document.getElementById('agItemHsn').value : '',
            mfg_date: document.getElementById('agItemMfg') ? document.getElementById('agItemMfg').value : '',
            expiry_date: document.getElementById('agItemExp') ? document.getElementById('agItemExp').value : '',
            purchase_price: parseFloat(document.getElementById('agItemPurch').value || 0),
            selling_price: parseFloat(document.getElementById('agItemSell').value || 0),
            mrp: document.getElementById('agItemMrp') ? parseFloat(document.getElementById('agItemMrp').value || 0) : 0,
            discount: document.getElementById('agItemDisc') ? parseFloat(document.getElementById('agItemDisc').value || 0) : 0,
            gst: document.getElementById('agItemGst') ? parseFloat(document.getElementById('agItemGst').value || 0) : 0,
            opening_stock: parseInt(document.getElementById('agItemStock').value || 0),
            barcode: document.getElementById('agItemBarcode') ? document.getElementById('agItemBarcode').value : '',
            qr_code: document.getElementById('agItemQrCode') ? document.getElementById('agItemQrCode').value : '',
            manufacturer: document.getElementById('agItemManufacturer') ? document.getElementById('agItemManufacturer').value : '',
            supplier_id: document.getElementById('agItemSupplier') ? document.getElementById('agItemSupplier').value : null,
            reorder_level: parseInt(document.getElementById('agItemReorderLevel') ? document.getElementById('agItemReorderLevel').value : 0),
            min_stock: parseInt(document.getElementById('agItemMinStock') ? document.getElementById('agItemMinStock').value : 0),
            rack_location: document.getElementById('agItemRackLocation') ? document.getElementById('agItemRackLocation').value : '',
            gst_percentage: parseFloat(document.getElementById('agItemGstPercentage') ? document.getElementById('agItemGstPercentage').value : 0),
            row_location: (document.getElementById('agItemRow') ? document.getElementById('agItemRow').value : '').trim().toUpperCase(),
            col_location: (document.getElementById('agItemCol') ? document.getElementById('agItemCol').value : '').trim().toUpperCase()
        };
        await api('/api/agency/items/add', { method: 'POST', body: payload });
        toast('Item saved');
        closeModal('agItemModal');
        loadAgencyItems();
        loadAgencyDashboard();
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

async function deleteAgencyItem(id) {
    if (!confirm('Delete item?')) return;
    try {
        await api(`/api/agency/items/delete/${id}`, { method: 'DELETE' });
        toast('Deleted successfully');
        loadAgencyItems();
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

// â• â• â• â• â• â• â• â•  PURCHASE ENTRY â• â• â• â• â• â• â• â• 
async function loadAgencyPurchases() {
    try {
        const res = await api('/api/agency/dashboard');
        document.getElementById('agPurcBody').innerHTML = res.recent_purchases.map(p => `
            <tr>
                <td>${p.purchase_date}</td>
                <td><strong>${p.invoice_number}</strong></td>
                <td>${p.supplier_name || 'Unknown'}</td>
                <td style="color:var(--emerald);font-weight:bold;">₹${parseFloat(p.grand_total).toFixed(2)}</td>
                <td><span class="badge" style="background:var(--bg-hover);color:var(--text-primary);">Complete</span></td>
                <td>
                    <button class="btn btn-outline btn-sm" onclick="viewAgencyPurchase(${p.id})">👁 View</button>
                    <button class="btn btn-outline btn-sm" onclick="editAgencyPurchase(${p.id})">✎ Edit</button>
                    <button class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger);" onclick="deleteAgencyPurchase(${p.id})">Delete</button>
                </td>
            </tr>
        `).join('');
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

async function viewAgencyPurchase(id) {
    try {
        const res = await api(`/api/agency/purchase/details/${id}`);
        if (res.success && res.purchase) {
            const p = res.purchase;
            let detailsHtml = `
                <div><strong>Supplier:</strong> ${p.supplier_name || 'N/A'}</div>
                <div><strong>Invoice No:</strong> ${p.invoice_number}</div>
                <div><strong>Date:</strong> ${p.purchase_date}</div>
                <div><strong>Payment Mode:</strong> ${p.payment_mode}</div>
                <div><strong>Sub Total:</strong> ₹${parseFloat(p.sub_total || 0).toFixed(2)}</div>
                <div><strong>Discount:</strong> ₹${parseFloat(p.discount_total || 0).toFixed(2)}</div>
                <div><strong>GST Total:</strong> ₹${parseFloat(p.gst_total || 0).toFixed(2)}</div>
                <div style="color:var(--emerald);font-weight:bold;font-size:1.1em;"><strong>Grand Total:</strong> ₹${parseFloat(p.grand_total || 0).toFixed(2)}</div>
            `;
            document.getElementById('agPurcViewDetails').innerHTML = detailsHtml;

            if (p.image_path) {
                document.getElementById('agPurcViewImageContainer').style.display = 'block';
                document.getElementById('agPurcViewImage').src = p.image_path;
            } else {
                document.getElementById('agPurcViewImageContainer').style.display = 'none';
                document.getElementById('agPurcViewImage').src = '';
            }

            let itemsHtml = (p.items || []).map(i => `
                <tr>
                    <td>${i.item_name}</td>
                    <td>${i.generic_name || '-'}</td>
                    <td>${i.batch_number || '-'}</td>
                    <td>${i.expiry_date || '-'}</td>
                    <td>${i.quantity || 0}</td>
                    <td>₹${parseFloat(i.purchase_rate || 0).toFixed(2)}</td>
                    <td>₹${parseFloat(i.total_amount || 0).toFixed(2)}</td>
                </tr>
            `).join('');
            document.getElementById('agPurcViewItems').innerHTML = itemsHtml;

            openModal('agPurcViewModal');
        }
    } catch (e) {
        toast('Failed to load purchase details', 'error');
    }
}

async function editAgencyPurchase(id) {
    try {
        const res = await api(`/api/agency/purchase/details/${id}`);
        if (res.success && res.purchase) {
            const p = res.purchase;
            document.getElementById('agPurcId').value = p.id;
            document.getElementById('agPurcImage').value = p.image_path || '';
            document.getElementById('agPurcTitle').textContent = 'Edit Purchase Entry';

            if (document.getElementById('agPurcSuppName')) document.getElementById('agPurcSuppName').value = p.supplier_name || '';
            if (document.getElementById('agPurcSupp')) document.getElementById('agPurcSupp').value = p.supplier_id || '';
            document.getElementById('agPurcInv').value = p.invoice_number || '';
            document.getElementById('agPurcDate').value = p.purchase_date || '';
            if (document.getElementById('agPurcPayment')) document.getElementById('agPurcPayment').value = p.payment_mode || 'Cash';
            if (document.getElementById('agPurcCreditDays')) document.getElementById('agPurcCreditDays').value = p.credit_days || 0;
            if (document.getElementById('agPurcDueDate')) document.getElementById('agPurcDueDate').value = p.due_date || '';
            if (document.getElementById('agPurcDocName')) document.getElementById('agPurcDocName').value = p.doctor_name || '';
            if (document.getElementById('agPurcDocReg')) document.getElementById('agPurcDocReg').value = p.doctor_reg_no || '';
            if (document.getElementById('agPurcClinic')) document.getElementById('agPurcClinic').value = p.clinic_name || '';
            if (document.getElementById('agPurcTransName')) document.getElementById('agPurcTransName').value = p.transport_name || '';
            if (document.getElementById('agPurcVehicle')) document.getElementById('agPurcVehicle').value = p.vehicle_number || '';
            if (document.getElementById('agPurcLr')) document.getElementById('agPurcLr').value = p.lr_number || '';

            if (document.getElementById('agPurcType')) document.getElementById('agPurcType').value = p.purchase_type || 'Regular';
            if (document.getElementById('agPurcPaymentDate')) document.getElementById('agPurcPaymentDate').value = p.payment_date || '';
            if (document.getElementById('agPurcTransactionId')) document.getElementById('agPurcTransactionId').value = p.transaction_id || '';
            if (document.getElementById('agPurcBankName')) document.getElementById('agPurcBankName').value = p.bank_name || '';

            document.getElementById('agPurcItemsBody').innerHTML = '';

            if (p.items && p.items.length > 0) {
                p.items.forEach(i => {
                    addAgPurcRow();
                    const row = document.getElementById('agPurcItemsBody').lastElementChild;
                    row.querySelector('.purc-name').value = i.item_name || '';
                    row.querySelector('.purc-generic').value = i.generic_name || '';
                    row.querySelector('.purc-hsn').value = i.hsn_code || '';
                    row.querySelector('.purc-batch').value = i.batch_number || '';
                    row.querySelector('.purc-mfg').value = i.mfg_date || '';
                    row.querySelector('.purc-exp').value = i.expiry_date || '';
                    row.querySelector('.purc-qty').value = i.quantity || 0;
                    row.querySelector('.purc-free').value = i.free_qty || 0;
                    row.querySelector('.purc-unit').value = i.unit || '';
                    row.querySelector('.purc-rate').value = i.purchase_rate || 0;
                    row.querySelector('.purc-mrp').value = i.mrp || 0;
                    row.querySelector('.purc-disc').value = i.discount_percentage || i.discount || 0;
                    row.querySelector('.purc-taxable').value = i.taxable_amount || 0;
                    if (row.querySelector('.purc-gst-perc')) row.querySelector('.purc-gst-perc').value = i.gst_percentage || i.gst || 0;
                    row.querySelector('.purc-gst').value = i.gst_percentage || i.gst || 0;
                    row.querySelector('.purc-cgst').value = i.cgst || 0;
                    row.querySelector('.purc-sgst').value = i.sgst || 0;
                    row.querySelector('.purc-total').value = i.total_amount || 0;
                });
            } else {
                addAgPurcRow();
            }

            calcAgPurcGlobalTotals();
            openModal('agPurcModal');
        }
    } catch (e) {
        toast('Failed to load purchase details for editing', 'error');
    }
}

function openAgencyPurcModal() {
    if (document.getElementById('agPurcId')) document.getElementById('agPurcId').value = '';
    if (document.getElementById('agPurcImage')) document.getElementById('agPurcImage').value = '';
    document.getElementById('agPurcTitle').textContent = 'Purchase Entry';
    if (document.getElementById('agPurcSuppName')) document.getElementById('agPurcSuppName').value = '';
    if (document.getElementById('agPurcSupp')) document.getElementById('agPurcSupp').value = '';
    document.getElementById('agPurcInv').value = '';
    document.getElementById('agPurcDate').value = new Date().toISOString().split('T')[0];
    if (document.getElementById('agPurcInvDate')) document.getElementById('agPurcInvDate').value = new Date().toISOString().split('T')[0];
    if (document.getElementById('agPurcPayment')) document.getElementById('agPurcPayment').value = 'Cash';
    if (document.getElementById('agPurcCreditDays')) document.getElementById('agPurcCreditDays').value = '0';
    if (document.getElementById('agPurcDueDate')) document.getElementById('agPurcDueDate').value = '';
    if (document.getElementById('agPurcDocName')) document.getElementById('agPurcDocName').value = '';
    if (document.getElementById('agPurcDocReg')) document.getElementById('agPurcDocReg').value = '';
    if (document.getElementById('agPurcClinic')) document.getElementById('agPurcClinic').value = '';
    if (document.getElementById('agPurcTransName')) document.getElementById('agPurcTransName').value = '';
    if (document.getElementById('agPurcVehicle')) document.getElementById('agPurcVehicle').value = '';
    if (document.getElementById('agPurcLr')) document.getElementById('agPurcLr').value = '';

    if (document.getElementById('agPurcType')) document.getElementById('agPurcType').value = 'Regular';
    if (document.getElementById('agPurcPaymentDate')) document.getElementById('agPurcPaymentDate').value = new Date().toISOString().split('T')[0];
    if (document.getElementById('agPurcTransactionId')) document.getElementById('agPurcTransactionId').value = '';
    if (document.getElementById('agPurcBankName')) document.getElementById('agPurcBankName').value = '';

    document.getElementById('agPurcItemsBody').innerHTML = '';
    document.getElementById('agPurcSub').value = '0';
    if (document.getElementById('agPurcDiscTotal')) document.getElementById('agPurcDiscTotal').value = '0';
    if (document.getElementById('agPurcTaxable')) document.getElementById('agPurcTaxable').value = '0';
    if (document.getElementById('agPurcCgstTotal')) document.getElementById('agPurcCgstTotal').value = '0';
    if (document.getElementById('agPurcSgstTotal')) document.getElementById('agPurcSgstTotal').value = '0';
    if (document.getElementById('agPurcGst')) document.getElementById('agPurcGst').value = '0';
    document.getElementById('agPurcGrand').value = '0';

    addAgPurcRow();
    openModal('agPurcModal');
}


function isRowEmpty(tr) {
    const nameVal = tr.querySelector('.purc-name') ? tr.querySelector('.purc-name').value.trim() : '';
    const batchVal = tr.querySelector('.purc-batch') ? tr.querySelector('.purc-batch').value.trim() : '';
    const qtyVal = tr.querySelector('.purc-qty') ? tr.querySelector('.purc-qty').value.trim() : '';
    const rateVal = tr.querySelector('.purc-rate') ? tr.querySelector('.purc-rate').value.trim() : '';
    return nameVal === '' && batchVal === '' && qtyVal === '' && rateVal === '';
}

function addAgPurcRow(item = {}, skipCalc = true) {
    const tbody = document.getElementById('agPurcItemsBody');
    const tr = document.createElement('tr');

    tr.innerHTML = `
        <td><input type="text" class="form-control purc-hsn" style="min-width:150px; box-sizing:border-box;" value="${item.hsn_code ?? ''}"></td>
        <td><input type="text" class="form-control purc-name" style="min-width:350px; box-sizing:border-box;" value="${item.item_name ?? ''}"></td>
        <td>
            <div style="position:relative;">
                <input type="text" class="form-control purc-generic" style="min-width:250px; box-sizing:border-box;" placeholder="Generic Medicine Name" value="${item.generic_name ?? ''}" autocomplete="off" oninput="searchGenericNames(this)">
            </div>
        </td>
        <td><input type="text" class="form-control purc-batch" style="min-width:120px; box-sizing:border-box;" value="${item.batch_number ?? ''}"></td>
        <td><input type="text" class="form-control purc-mfg" style="min-width:100px; box-sizing:border-box;" placeholder="MM/YY" value="${item.mfg_date ?? ''}"></td>
        <td><input type="text" class="form-control purc-exp" style="min-width:100px; box-sizing:border-box;" placeholder="MM/YY" value="${item.expiry_date ?? ''}"></td>
        <td><input type="text" class="form-control purc-unit" style="min-width:80px; box-sizing:border-box;" value="${item.unit ?? ''}"></td>
        <td><input type="number" class="form-control purc-qty" style="min-width:100px; box-sizing:border-box;" value="${item.quantity ?? ''}"></td>
        <td><input type="number" class="form-control purc-free" style="min-width:80px; box-sizing:border-box;" value="${item.free_qty ?? ''}"></td>
        <td><input type="number" step="0.01" class="form-control purc-rate" style="min-width:120px; box-sizing:border-box;" value="${item.purchase_rate ?? ''}"></td>
        <td><input type="number" step="0.01" class="form-control purc-mrp" style="min-width:120px; box-sizing:border-box;" value="${item.mrp ?? ''}"></td>
        <td><input type="number" step="0.01" class="form-control purc-sell" style="min-width:120px; box-sizing:border-box;" value="${item.selling_price ?? ''}"></td>
        <td><input type="number" step="0.01" class="form-control purc-disc" style="min-width:100px; box-sizing:border-box;" value="${item.discount_percentage ?? ''}"></td>
        <td><input type="number" step="0.01" class="form-control purc-taxable" style="min-width:120px; box-sizing:border-box;" value="${item.taxable_amount ?? 0}"></td>
        <td><input type="number" step="0.01" class="form-control purc-gst" style="min-width:100px; box-sizing:border-box;" value="${item.gst_percentage ?? item.gst ?? ''}"></td>
        <td><input type="number" step="0.01" class="form-control purc-cgst" style="min-width:100px; box-sizing:border-box;" value="${item.cgst ?? 0}"></td>
        <td><input type="number" step="0.01" class="form-control purc-sgst" style="min-width:100px; box-sizing:border-box;" value="${item.sgst ?? 0}"></td>
        <td><input type="number" step="0.01" class="form-control purc-total" style="min-width:150px; box-sizing:border-box;" value="${item.total_amount ?? ''}"></td>
        <td><button type="button" class="btn btn-outline btn-sm" style="color:var(--danger); border:none; padding: 2px 6px;" onclick="this.closest('tr').remove();"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button></td>
    `;
    tbody.appendChild(tr);
}

// Auto-calculation disabled — all fields are fully manual.
function calcAgPurcRowTotals(tr) {}


// Auto-calculation disabled — all fields are fully manual.
function calcAgPurcGlobalTotals() {}


// Auto-calculation disabled — all fields are fully manual.
function runFieldRecalculation() {}
function calcGlobalFromPct(type) {}
function calcGlobalFromAmt(type) {}
function calcGlobalTotals() {}


async function saveAgencyPurc() {
    const items = [];

    document.querySelectorAll('#agPurcItemsBody tr').forEach((tr, index) => {
        if (isRowEmpty(tr)) {
            return;
        }
        let name = tr.querySelector('.purc-name').value.trim();
        let qty = parseInt(tr.querySelector('.purc-qty').value) || 0;
        let rate = parseFloat(tr.querySelector('.purc-rate').value) || 0;

        items.push({
            hsn_code: tr.querySelector('.purc-hsn') ? tr.querySelector('.purc-hsn').value : '',
            item_name: name,
            generic_name: tr.querySelector('.purc-generic') ? tr.querySelector('.purc-generic').value.trim() : '',
            brand_name: name,
            batch_number: tr.querySelector('.purc-batch').value,
            mfg_date: tr.querySelector('.purc-mfg') ? tr.querySelector('.purc-mfg').value : '',
            expiry_date: tr.querySelector('.purc-exp') ? tr.querySelector('.purc-exp').value : '',
            unit: tr.querySelector('.purc-unit') ? tr.querySelector('.purc-unit').value : '',
            quantity: qty,
            free_qty: tr.querySelector('.purc-free') ? parseInt(tr.querySelector('.purc-free').value) || 0 : 0,
            purchase_rate: rate,
            mrp: tr.querySelector('.purc-mrp') ? parseFloat(tr.querySelector('.purc-mrp').value) || 0 : 0,
            selling_price: tr.querySelector('.purc-sell') ? parseFloat(tr.querySelector('.purc-sell').value) || 0 : 0,
            discount_percentage: tr.querySelector('.purc-disc') ? parseFloat(tr.querySelector('.purc-disc').value) || 0 : 0,
            discount: (rate * qty) * (tr.querySelector('.purc-disc') ? (parseFloat(tr.querySelector('.purc-disc').value) || 0) / 100 : 0),
            taxable_amount: tr.querySelector('.purc-taxable') ? parseFloat(tr.querySelector('.purc-taxable').value) || 0 : 0,
            gst_percentage: tr.querySelector('.purc-gst') ? parseFloat(tr.querySelector('.purc-gst').value) || 0 : 0,
            tax_amount: (tr.querySelector('.purc-cgst') ? parseFloat(tr.querySelector('.purc-cgst').value) || 0 : 0) + (tr.querySelector('.purc-sgst') ? parseFloat(tr.querySelector('.purc-sgst').value) || 0 : 0),
            gst: tr.querySelector('.purc-gst') ? parseFloat(tr.querySelector('.purc-gst').value) || 0 : 0, // Using same value for backwards compatibility
            cgst: tr.querySelector('.purc-cgst') ? parseFloat(tr.querySelector('.purc-cgst').value) || 0 : 0,
            sgst: tr.querySelector('.purc-sgst') ? parseFloat(tr.querySelector('.purc-sgst').value) || 0 : 0,
            total_amount: parseFloat(tr.querySelector('.purc-total').value) || 0
        });
    });

    if (items.length === 0) {
        toast('Please add at least one item', 'error');
        return;
    }

    let invNo = document.getElementById('agPurcInv').value.trim();
    let suppName = document.getElementById('agPurcSuppName') ? document.getElementById('agPurcSuppName').value.trim() : '';

    const payload = {
        id: document.getElementById('agPurcId') ? document.getElementById('agPurcId').value : '',
        image_path: document.getElementById('agPurcImage') ? document.getElementById('agPurcImage').value : '',
        supplier_id: document.getElementById('agPurcSupp') ? document.getElementById('agPurcSupp').value : '',
        supplier_name: suppName,
        invoice_number: invNo,
        purchase_date: document.getElementById('agPurcDate').value,
        payment_mode: document.getElementById('agPurcPayment') ? document.getElementById('agPurcPayment').value : 'Cash',
        credit_days: document.getElementById('agPurcCreditDays') ? parseInt(document.getElementById('agPurcCreditDays').value) || 0 : 0,
        due_date: document.getElementById('agPurcDueDate') ? document.getElementById('agPurcDueDate').value : '',
        doctor_name: document.getElementById('agPurcDocName') ? document.getElementById('agPurcDocName').value : '',
        doctor_reg_no: document.getElementById('agPurcDocReg') ? document.getElementById('agPurcDocReg').value : '',
        clinic_name: document.getElementById('agPurcClinic') ? document.getElementById('agPurcClinic').value : '',
        transport_name: document.getElementById('agPurcTransName') ? document.getElementById('agPurcTransName').value : '',
        vehicle_number: document.getElementById('agPurcVehicle') ? document.getElementById('agPurcVehicle').value : '',
        lr_number: document.getElementById('agPurcLr') ? document.getElementById('agPurcLr').value : '',
        sub_total: parseFloat(document.getElementById('agPurcSub').value) || 0,
        discount_total: document.getElementById('agPurcDiscTotal') ? parseFloat(document.getElementById('agPurcDiscTotal').value) || 0 : 0,
        cgst_total: document.getElementById('agPurcCgstTotal') ? parseFloat(document.getElementById('agPurcCgstTotal').value) || 0 : 0,
        sgst_total: document.getElementById('agPurcSgstTotal') ? parseFloat(document.getElementById('agPurcSgstTotal').value) || 0 : 0,
        gst_total: document.getElementById('agPurcGst') ? parseFloat(document.getElementById('agPurcGst').value) || 0 : 0,
        grand_total: parseFloat(document.getElementById('agPurcGrand').value) || 0,
        upi_reference: document.getElementById('agPurcTransactionId') ? document.getElementById('agPurcTransactionId').value : '',
        transaction_id: document.getElementById('agPurcTransactionId') ? document.getElementById('agPurcTransactionId').value : '',
        payment_date: document.getElementById('agPurcPaymentDate') ? document.getElementById('agPurcPaymentDate').value : '',
        bank_name: document.getElementById('agPurcBankName') ? document.getElementById('agPurcBankName').value : '',
        purchase_type: document.getElementById('agPurcType') ? document.getElementById('agPurcType').value : 'Regular',
        items: items
    };

    try {
        const res = await api('/api/agency/purchase/add', { method: 'POST', body: payload });
        toast('Purchase Entry successful! Stock increased.');
        closeModal('agPurcModal');
        loadAgencyPurchases();
        loadAgencyDashboard();
    } catch (e) {
        toast('Failed to save purchase: ' + (e.message || e), 'error');
    }
}

function handleOcrScanPrompt() {
    document.getElementById('agOcrInput').click();
}

async function handleOcrScan(input) {
    if (!input.files || input.files.length === 0) return;

    if (document.getElementById('ocrLoader')) document.getElementById('ocrLoader').style.display = 'block';

    const formData = new FormData();
    formData.append('bill_image', input.files[0]);

    try {
        const res = await api('/api/agency/ocr_scan', { method: 'POST', body: formData });
        if (document.getElementById('ocrLoader')) document.getElementById('ocrLoader').style.display = 'none';

        if (res.success) {
            toast('AI Successfully parsed the bill!');

            if (document.getElementById('agPurcId')) document.getElementById('agPurcId').value = '';
            if (document.getElementById('agPurcImage')) document.getElementById('agPurcImage').value = res.image_url || '';

            document.getElementById('agPurcTitle').innerHTML = '🤖 AI Extracted Purchase Preview <span class="badge badge-success" style="font-size:0.5em; vertical-align:middle;">Confidence 98%</span>';

            if (res.supplier) {
                let suppId = '';
                if (agencySuppliersData.length === 0) await loadAgencySuppliersForSelect();
                const matchedSupp = agencySuppliersData.find(s =>
                    (s.gst_number && res.supplier.gst && s.gst_number.includes(res.supplier.gst)) ||
                    (s.name && res.supplier.name && s.name.toLowerCase() === res.supplier.name.toLowerCase()) ||
                    (s.company_name && res.supplier.name && s.company_name.toLowerCase() === res.supplier.name.toLowerCase())
                );

                if (matchedSupp) {
                    suppId = matchedSupp.id;
                    if (document.getElementById('agPurcSuppName')) document.getElementById('agPurcSuppName').value = matchedSupp.name;
                } else if (res.supplier.name) {
                    if (document.getElementById('agPurcSuppName')) document.getElementById('agPurcSuppName').value = res.supplier.name || '';
                    toast('Supplier not found. A new one will be created.', 'warning');
                }

                if (document.getElementById('agPurcSupp')) document.getElementById('agPurcSupp').value = suppId;
                if (document.getElementById('agPurcInv')) document.getElementById('agPurcInv').value = res.supplier.invoice_number || '';
                if (document.getElementById('agPurcDate')) document.getElementById('agPurcDate').value = res.supplier.date || new Date().toISOString().split('T')[0];
                if (document.getElementById('agPurcInvDate')) document.getElementById('agPurcInvDate').value = res.supplier.date || new Date().toISOString().split('T')[0];
            }

            if (document.getElementById('agPurcDocName') && res.doctor_name) document.getElementById('agPurcDocName').value = res.doctor_name;
            if (document.getElementById('agPurcDocReg') && res.doctor_reg_no) document.getElementById('agPurcDocReg').value = res.doctor_reg_no;
            if (document.getElementById('agPurcClinic') && res.clinic_name) document.getElementById('agPurcClinic').value = res.clinic_name;
            if (document.getElementById('agPurcTransName') && res.transport_name) document.getElementById('agPurcTransName').value = res.transport_name;
            if (document.getElementById('agPurcVehicle') && res.vehicle_number) document.getElementById('agPurcVehicle').value = res.vehicle_number;
            if (document.getElementById('agPurcLr') && res.lr_number) document.getElementById('agPurcLr').value = res.lr_number;

            const detailsSection = document.getElementById('agPurcDetailsSection');
            if (detailsSection) {
                if (res.doctor_name || res.doctor_reg_no || res.clinic_name || res.transport_name || res.vehicle_number || res.lr_number) {
                    detailsSection.open = true;
                } else {
                    detailsSection.open = false;
                }
            }

            document.getElementById('agPurcItemsBody').innerHTML = '';
            if (res.items && res.items.length) {
                res.items.forEach(item => {
                    item.generic_name = '';
                    addAgPurcRow(item, true);
                });
            }

            // Populate exactly what OCR found without auto-calculating
            if (document.getElementById('agPurcSub')) document.getElementById('agPurcSub').value = res.sub_total ?? '';
            if (document.getElementById('agPurcGst')) document.getElementById('agPurcGst').value = res.gst_total ?? '';
            if (document.getElementById('agPurcCgstTotal')) document.getElementById('agPurcCgstTotal').value = res.cgst_total ?? (res.gst_total !== null && res.gst_total !== undefined ? (res.gst_total / 2).toFixed(2) : '');
            if (document.getElementById('agPurcSgstTotal')) document.getElementById('agPurcSgstTotal').value = res.sgst_total ?? (res.gst_total !== null && res.gst_total !== undefined ? (res.gst_total / 2).toFixed(2) : '');
            if (document.getElementById('agPurcCgstPct')) document.getElementById('agPurcCgstPct').value = res.gst_percentage_global ? (res.gst_percentage_global / 2).toFixed(2) : '';
            if (document.getElementById('agPurcSgstPct')) document.getElementById('agPurcSgstPct').value = res.gst_percentage_global ? (res.gst_percentage_global / 2).toFixed(2) : '';
            if (document.getElementById('agPurcDiscTotal')) document.getElementById('agPurcDiscTotal').value = res.discount_total ?? '';
            if (document.getElementById('agPurcGrand')) document.getElementById('agPurcGrand').value = res.grand_total ?? '';

            openModal('agPurcModal');
        }
    } catch (e) {
        if (document.getElementById('ocrLoader')) document.getElementById('ocrLoader').style.display = 'none';
        toast(e.message || 'AI Scan failed', 'error');
    }

    input.value = ''; // Reset
}

// â• â• â• â• â• â• â• â•  TRANSFER & ADJUSTMENTS â• â• â• â• â• â• â• â• 
async function submitAgencyTransfer() {
    const item_id = document.getElementById('agTransItem').value;
    const qty = parseInt(document.getElementById('agTransQty').value) || 0;

    if (!item_id || qty <= 0) {
        toast('Enter valid quantity', 'error');
        return;
    }

    try {
        await api('/api/agency/stock/transfer', {
            method: 'POST',
            body: { item_id, quantity: qty }
        });
        toast('Stock transferred to Pharmacy!');
        document.getElementById('agTransQty').value = '';
        loadAgencyItemsForSelect();
    } catch (e) {
        toast(e.message || 'Transfer failed', 'error');
    }
}

async function submitAgencyAdjust() {
    const item_id = document.getElementById('agAdjItem').value;
    const qty = parseInt(document.getElementById('agAdjQty').value) || 0;
    const reason = document.getElementById('agAdjReason').value;

    if (!item_id || qty === 0) {
        toast('Please select an item and enter quantity', 'error');
        return;
    }

    try {
        await api('/api/agency/stock/adjust', {
            method: 'POST',
            body: { item_id, quantity: qty, reason }
        });
        toast('Stock Adjusted successfully');
        document.getElementById('agAdjQty').value = '';
        document.getElementById('agAdjReason').value = '';
        loadAgencyItemsForSelect();
    } catch (e) {
        toast('Adjustment failed', 'error');
    }
}

// â• â• â• â• â• â• â• â•  REPORTS â• â• â• â• â• â• â• â• 
async function loadAgencyReports() {
    try {
        const res = await api('/api/agency/reports');
        document.getElementById('agRepExpBody').innerHTML = res.expiry_report.map(i => `
            <tr>
                <td><strong>${i.item_name}</strong><br><span style="font-size:0.8em; color:var(--text-secondary);">${i.agency_name || 'No Agency'}</span></td>
                <td>${i.batch_number}</td>
                <td><span style="${i.stock <= i.min_stock ? 'color:var(--danger);' : ''}">${i.stock}</span></td>
                <td><span class="badge" style="background:var(--danger);color:white;">${i.expiry_date}</span></td>
            </tr>
        `).join('');
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

// Auto-load dash when clicking Agency tab is handled by the wrapper function


let currentAgencyStockData = [];
let currentAgencyStockType = '';

async function openAgencyStockModal(type) {
    try {
        const res = await api('/api/agency/items');
        currentAgencyStockData = res;
        currentAgencyStockType = type;

        if (document.getElementById('agStockDateFilter')) {
            document.getElementById('agStockDateFilter').value = 'all';
            document.getElementById('agStockCustomDateGroup').style.display = 'none';
        }

        renderAgencyStockModal(res, type);
        openModal('agStockDetailsModal');
    } catch (e) {
        toast('Failed to fetch details', 'error');
    }
}

function renderAgencyStockModal(data, type) {
    let filtered = [];
    let title = '';
    let head = '';
    let body = '';

    const container = document.getElementById('agStockDetailsContainer');
    if (!container) return;

    if (type === 'all') {
        title = 'Total Items & Quantity';
        filtered = data;
        head = '<th>Item Code</th><th>Item Name</th><th>Batch</th><th>Stock</th><th>Low Stock Alert</th><th>Created Date</th><th>Actions</th>';
        body = filtered.map(i => {
            let dt = i.created_at ? i.created_at.split(' ')[0] : '-';
            return `<tr>
                <td>${i.item_code || '-'}</td>
                <td><strong>${i.item_name || ''}</strong></td>
                <td>${i.batch_number || '-'}</td>
                <td>${i.stock || 0}</td>
                <td>
                    <input type="number" min="0" value="${i.min_stock || 0}" style="width:75px; text-align:center; padding: 4px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-primary); color: var(--text-primary);" class="form-control" id="ag-min-stock-${i.id}">
                </td>
                <td>${dt}</td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick="saveLowStockAlert(${i.id}, this)">Save</button>
                </td>
            </tr>`;
        }).join('');

        if (filtered.length === 0) {
            body = `<tr><td colspan="7" style="text-align:center;">No records found.</td></tr>`;
        }

        container.innerHTML = `
            <table class="data-table" id="agStockDetailsTable">
                <thead>
                    <tr id="agStockDetailsHead">${head}</tr>
                </thead>
                <tbody id="agStockDetailsBody">${body}</tbody>
            </table>
        `;
    } else if (type === 'value') {
        title = 'Total Stock Value Breakdown';
        filtered = data.filter(i => i.stock > 0);
        head = '<th>Item Code</th><th>Item Name</th><th>Batch</th><th>Stock</th><th>Purchase Price</th><th>Total Value</th><th>Created Date</th>';
        body = filtered.map(i => {
            let val = (i.stock * (i.purchase_price || 0)).toFixed(2);
            let dt = i.created_at ? i.created_at.split(' ')[0] : '-';
            return `<tr><td>${i.item_code || '-'}</td><td><strong>${i.item_name || ''}</strong></td><td>${i.batch_number || '-'}</td><td>${i.stock || 0}</td><td>₹ ${parseFloat(i.purchase_price || 0).toFixed(2)}</td><td style="color:var(--emerald);font-weight:bold;">₹ ${val}</td><td>${dt}</td></tr>`;
        }).join('');

        if (filtered.length === 0) {
            body = `<tr><td colspan="7" style="text-align:center;">No records found.</td></tr>`;
        }

        container.innerHTML = `
            <table class="data-table" id="agStockDetailsTable">
                <thead>
                    <tr id="agStockDetailsHead">${head}</tr>
                </thead>
                <tbody id="agStockDetailsBody">${body}</tbody>
            </table>
        `;
    } else if (type === 'low') {
        title = 'Low / Out of Stock Alerts';

        const lowStockItems = data.filter(i => i.stock > 0 && i.stock <= (i.min_stock || 0));
        const outOfStockItems = data.filter(i => i.stock === 0);

        const makeTable = (items, statusText, color) => {
            let tableHead = '<th>S.No</th><th>Item Code</th><th>Item Name</th><th>Current Stock</th><th>Threshold</th><th>Status</th>';
            let tableBody = '';
            if (items.length === 0) {
                tableBody = `<tr><td colspan="6" style="text-align:center;">No records found.</td></tr>`;
            } else {
                tableBody = items.map((i, index) => {
                    let status = `<span style="color:${color};font-weight:bold;">${statusText}</span>`;
                    return `<tr><td>${index + 1}</td><td>${i.item_code || '-'}</td><td><strong>${i.item_name || ''}</strong></td><td style="color:var(--danger);font-weight:bold;">${i.stock || 0}</td><td>${i.min_stock || 0}</td><td>${status}</td></tr>`;
                }).join('');
            }
            return `
                <table class="data-table">
                    <thead><tr>${tableHead}</tr></thead>
                    <tbody>${tableBody}</tbody>
                </table>
            `;
        };

        container.innerHTML = `
            <div style="margin-top: 10px; margin-bottom: 20px;">
                <h4 style="margin: 10px 0 10px 0; padding-bottom: 8px; border-bottom: 2px solid var(--warning); color: var(--warning); letter-spacing: 0.5px; font-weight: 700; font-size: 1.1rem;">LOW STOCK</h4>
                ${makeTable(lowStockItems, 'LOW STOCK', 'orange')}
            </div>
            <div style="margin-top: 25px; margin-bottom: 10px;">
                <h4 style="margin: 10px 0 10px 0; padding-bottom: 8px; border-bottom: 2px solid var(--danger); color: var(--danger); letter-spacing: 0.5px; font-weight: 700; font-size: 1.1rem;">OUT OF STOCK</h4>
                ${makeTable(outOfStockItems, 'OUT OF STOCK', 'red')}
            </div>
        `;
    }

    if (document.getElementById('agStockDetailsTitle')) document.getElementById('agStockDetailsTitle').textContent = title;
}

function applyAgencyStockDateFilter() {
    let filter = document.getElementById('agStockDateFilter').value;
    const customGroup = document.getElementById('agStockCustomDateGroup');

    if (filter === 'custom') {
        customGroup.style.display = 'flex';
    } else {
        customGroup.style.display = 'none';
    }

    let filteredData = currentAgencyStockData;

    if (filter !== 'all') {
        let today = new Date();
        today.setHours(0, 0, 0, 0);

        filteredData = currentAgencyStockData.filter(i => {
            if (!i.created_at) return false;
            let itemDateStr = i.created_at.split(' ')[0]; // YYYY-MM-DD
            let itemDate = new Date(itemDateStr);
            itemDate.setHours(0, 0, 0, 0);

            if (filter === 'today') {
                return itemDate.getTime() === today.getTime();
            } else if (filter === 'yesterday') {
                let yest = new Date(today);
                yest.setDate(yest.getDate() - 1);
                return itemDate.getTime() === yest.getTime();
            } else if (filter === 'week') {
                let startOfWeek = new Date(today);
                startOfWeek.setDate(today.getDate() - today.getDay());
                return itemDate >= startOfWeek && itemDate <= today;
            } else if (filter === 'month') {
                return itemDate.getMonth() === today.getMonth() && itemDate.getFullYear() === today.getFullYear();
            } else if (filter === 'custom') {
                let start = document.getElementById('agStockDateStart').value;
                let end = document.getElementById('agStockDateEnd').value;
                if (!start || !end) return true;
                let startDate = new Date(start);
                startDate.setHours(0, 0, 0, 0);
                let endDate = new Date(end);
                endDate.setHours(23, 59, 59, 999);
                return itemDate >= startDate && itemDate <= endDate;
            }
            return true;
        });
    }

    renderAgencyStockModal(filteredData, currentAgencyStockType);
}

function printAgencyStock() {
    let title = document.getElementById('agStockDetailsTitle').textContent;
    let tableHtml = document.getElementById('agStockDetailsTable')
        ? document.getElementById('agStockDetailsTable').outerHTML
        : document.getElementById('agStockDetailsContainer').innerHTML;
    let printWin = window.open('', '', 'width=900,height=600');
    printWin.document.write('<html><head><title>Print ' + title + '</title>');
    printWin.document.write('<style>body{font-family:sans-serif;} table { width:100%; border-collapse:collapse; margin-top:20px; } th, td { border:1px solid #ddd; padding:8px; text-align:left; } th { background-color:#f2f2f2; }</style>');
    printWin.document.write('</head><body>');
    printWin.document.write('<h2>' + title + '</h2>');
    printWin.document.write(tableHtml);
    printWin.document.write('</body></html>');
    printWin.document.close();
    setTimeout(() => {
        printWin.print();
    }, 500);
}

function exportAgencyStock() {
    generateAgencyStockPDF('download');
}

function shareAgencyStockWhatsApp() {
    generateAgencyStockPDF('share');
}



async function deleteAgencyPurchase(id) {
    if (!confirm('Are you sure you want to delete this purchase? This will remove the items from stock as well.')) return;
    try {
        const res = await api('/api/agency/purchase/delete/' + id, { method: 'DELETE' });
        if (res.success) {
            toast('Purchase deleted successfully');
            loadAgencyPurchases();
            loadAgencyDashboard();
        } else {
            toast(res.error || 'Failed to delete purchase', 'error');
        }
    } catch (e) {
        toast('Failed to delete purchase', 'error');
    }
}

async function openSupplierDetails(supplierId) {
    try {
        const res = await api(`/api/agency/supplier/details/${supplierId}`);
        const supp = res.supplier;
        const purcs = res.purchases;

        const formatAmount = (val) => {
            let num = parseFloat(val || 0);
            return num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        };

        document.getElementById('agSuppDetailsTitle').textContent = `Purchases from ${supp.name}`;

        let html = '';
        if (purcs.length === 0) {
            html = '<p>No purchases found for this supplier.</p>';
        } else {
            purcs.forEach(p => {
                const pendingVal = parseFloat(p.balance_amount || 0);
                const paidVal = parseFloat(p.paid_amount || 0);
                const isFullyPaid = pendingVal <= 0;
                const statusColor = isFullyPaid ? 'var(--emerald)' : (pendingVal > 0 ? 'var(--danger)' : 'var(--primary)');

                let itemsHtml = `<div id="supp-purc-items-${p.id}" style="display:none; margin-top:10px; background:var(--bg-primary); padding:10px; border-radius:4px; border:1px solid var(--border);">
                    <table class="data-table" style="font-size:0.9em;">
                        <thead><tr><th>Item</th><th>Batch</th><th>Qty</th><th>Rate</th><th>GST</th><th>Total</th></tr></thead>
                        <tbody>
                            ${p.items.map(i => `<tr>
                                <td>${i.item_name}</td>
                                <td>${i.batch_number}</td>
                                <td>${i.quantity} ${i.unit || ''}</td>
                                <td>₹ ${formatAmount(i.purchase_rate)}</td>
                                <td>₹ ${formatAmount(i.gst)}</td>
                                <td>₹ ${formatAmount(i.total_amount)}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>`;

                let paymentDisplayHtml = '';
                if (!isFullyPaid) {
                    paymentDisplayHtml = `
                        <div style="font-size:0.9em; margin-top: 5px; line-height: 1.4;">
                            <span style="color:var(--emerald); font-weight: bold;">Paid: ₹ ${formatAmount(paidVal)}</span> | 
                            <span style="color:var(--danger); font-weight: bold;">Pending: ₹ ${formatAmount(pendingVal)}</span>
                        </div>
                    `;
                } else {
                    let parts = [];
                    if (parseFloat(p.cash_amount || 0) > 0) parts.push(`Cash : ₹ ${formatAmount(p.cash_amount)}`);
                    if (parseFloat(p.gpay_amount || 0) > 0) parts.push(`UPI : ₹ ${formatAmount(p.gpay_amount)}`);
                    if (parseFloat(p.phonepe_amount || 0) > 0) parts.push(`PhonePe : ₹ ${formatAmount(p.phonepe_amount)}`);
                    if (parseFloat(p.bank_amount || 0) > 0) parts.push(`Bank Transfer : ₹ ${formatAmount(p.bank_amount)}`);

                    let breakdown = parts.length > 0 ? parts.join('<br>') : `${p.payment_mode || 'Cash'} : ₹ ${formatAmount(p.grand_total)}`;

                    paymentDisplayHtml = `
                        <div style="font-size:0.9em; margin-top: 5px; line-height: 1.5;">
                            <div style="color:var(--emerald); font-weight: bold;">Paid: ₹ ${formatAmount(paidVal)}</div>
                            <div><strong>Payment Date:</strong> ${p.payment_date || p.purchase_date || '-'}</div>
                            <div style="margin-top: 4px;"><strong>Payment Mode:</strong><br><span style="color:var(--text-secondary); display:inline-block; margin-top: 2px;">${breakdown}</span></div>
                        </div>
                    `;
                }

                const actionButtonHtml = !isFullyPaid ?
                    `<button class="btn btn-primary btn-sm" onclick="markPurchaseAsPaid(${p.id}, ${p.supplier_id}, ${pendingVal})">Mark as Full Payment</button>` : '';

                html += `
                <div style="border:1px solid var(--border); border-radius:var(--radius); padding:15px; margin-bottom:15px; background:var(--bg-card);">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <h4 style="margin:0; color:var(--text-primary);">Invoice: ${p.invoice_number}</h4>
                            <small style="color:var(--text-secondary);">Date: ${p.purchase_date}</small>
                        </div>
                        <div style="text-align:right;">
                            <h4 style="margin:0; color:var(--primary);">₹ ${formatAmount(p.grand_total)}</h4>
                            <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:0.8em; font-weight:bold; background:${statusColor}; color:#fff;">${p.payment_status || 'Pending'}</span>
                        </div>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-top:10px; padding-top:10px; border-top:1px dashed var(--border);">
                        <div>
                            ${paymentDisplayHtml}
                        </div>
                        <div style="display:flex; gap: 5px; align-items: center;">
                            <button class="btn btn-outline btn-sm" onclick="document.getElementById('supp-purc-items-${p.id}').style.display = document.getElementById('supp-purc-items-${p.id}').style.display === 'none' ? 'block' : 'none'">View Items</button>
                            ${actionButtonHtml}
                        </div>
                    </div>
                    ${itemsHtml}
                </div>`;
            });
        }

        // Add the supplier overall payment summary at the bottom
        html += `
        <div style="margin-top: 20px; padding: 15px; border: 2px dashed var(--primary); border-radius: var(--radius); background: var(--bg-hover);">
            <h4 style="margin-top:0; color:var(--primary); text-align:center;">Overall Supplier Summary</h4>
            <div style="display:flex; justify-content:space-around; align-items:center; text-align:center; flex-wrap:wrap; gap:15px;">
                <div><small style="color:var(--text-secondary);">Total Purchase</small><br><strong style="font-size:1.2em; color:var(--text-primary);">₹ ${formatAmount(supp.total_purchase)}</strong></div>
                <div><small style="color:var(--text-secondary);">Purchase Count</small><br><strong style="font-size:1.2em; color:var(--text-primary);">${purcs.length}</strong></div>
                <div><small style="color:var(--text-secondary);">Cash Paid</small><br><strong style="font-size:1.2em; color:var(--emerald);">₹ ${formatAmount(supp.cash_amount)}</strong></div>
                <div><small style="color:var(--text-secondary);">UPI Paid</small><br><strong style="font-size:1.2em; color:var(--emerald);">₹ ${formatAmount(supp.gpay_amount)}</strong></div>
                <div><small style="color:var(--text-secondary);">PhonePe Paid</small><br><strong style="font-size:1.2em; color:var(--emerald);">₹ ${formatAmount(supp.phonepe_amount)}</strong></div>
                <div><small style="color:var(--text-secondary);">Bank Transfer</small><br><strong style="font-size:1.2em; color:var(--emerald);">₹ ${formatAmount(supp.bank_amount)}</strong></div>
                <div><small style="color:var(--text-secondary);">Total Paid</small><br><strong style="font-size:1.2em; color:var(--emerald);">₹ ${formatAmount(supp.paid_amount)}</strong></div>
                <div><small style="color:var(--text-secondary);">Pending Balance</small><br><strong style="font-size:1.2em; color:var(--danger);">₹ ${formatAmount(supp.pending_balance)}</strong></div>
            </div>
            <div style="text-align:center; margin-top:15px;">
                <button class="btn btn-outline" onclick="closeModal('agSuppDetailsModal'); editAgencySupp(JSON.parse(decodeURIComponent('${encodeURIComponent(JSON.stringify(supp))}')));">Edit Supplier Payment Details</button>
            </div>
        </div>`;

        document.getElementById('agSuppDetailsContent').innerHTML = html;
        openModal('agSuppDetailsModal');
    } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
}

let currentInvoiceAmount = 0;
let paymentAmounts = { Cash: 0, UPI: 0, 'Bank Transfer': 0 };

function markPurchaseAsPaid(purc_id, supp_id, pending_amount) {
    currentInvoiceAmount = parseFloat(pending_amount || 0);

    const formatAmount = (val) => {
        let num = parseFloat(val || 0);
        return num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    };

    document.getElementById('agPaymentPurcId').value = purc_id;
    document.getElementById('agPaymentSuppId').value = supp_id;
    document.getElementById('agPaymentInvoiceAmt').textContent = `₹ ${formatAmount(currentInvoiceAmount)}`;

    // Reset payment amounts
    paymentAmounts = { Cash: 0, UPI: 0, 'Bank Transfer': 0 };

    // Check Cash by default
    const checks = document.querySelectorAll('.pay-method-check');
    checks.forEach(cb => {
        if (cb.value === 'Cash') {
            cb.checked = true;
            paymentAmounts.Cash = currentInvoiceAmount;
        } else {
            cb.checked = false;
        }
    });

    openModal('agPaymentModal');
    onPaymentMethodToggle();
}

function onPaymentMethodToggle() {
    // Save current values first
    const inputs = document.querySelectorAll('.payment-amount-input');
    inputs.forEach(input => {
        const method = input.getAttribute('data-method');
        paymentAmounts[method] = parseFloat(input.value) || 0;
    });

    const checkedBoxes = Array.from(document.querySelectorAll('.pay-method-check:checked'));
    const container = document.getElementById('agPaymentInputsContainer');

    if (checkedBoxes.length === 0) {
        container.innerHTML = '<p style="color:var(--text-secondary); text-align:center; margin: 10px 0;">Select at least one payment method above.</p>';
        recalculateRemainingAmounts();
        return;
    }

    // Smart pre-filling: if only one is checked and it's currently 0, pre-fill with full amount
    if (checkedBoxes.length === 1) {
        const singleMethod = checkedBoxes[0].value;
        if ((paymentAmounts[singleMethod] || 0) === 0) {
            paymentAmounts[singleMethod] = currentInvoiceAmount;
        }
    }

    // Render the inputs for checked checkboxes
    let html = '';
    checkedBoxes.forEach((cb) => {
        const method = cb.value;
        const amt = paymentAmounts[method] || 0;

        html += `
            <div style="background: var(--bg-hover); padding: 12px; border-radius: var(--radius); border: 1px solid var(--border);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 6px;">
                    <label style="font-weight: bold; margin: 0;">${method} Amount (₹)</label>
                </div>
                <input type="number" step="0.01" min="0" class="payment-amount-input form-control" data-method="${method}" value="${amt}" oninput="recalculateRemainingAmounts()" style="width: 100%;" onfocus="if(this.value=='0') this.value='';" onblur="if(this.value=='') this.value='0';">
                <div style="margin-top: 6px; font-size: 0.85em; color: var(--text-secondary); display: flex; justify-content: space-between;">
                    <span>Remaining Balance:</span>
                    <span class="remaining-val" data-method="${method}">₹ 0</span>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;

    // Show/Hide UPI Dropdown
    const hasUpi = checkedBoxes.some(cb => cb.value === 'UPI');
    const upiContainer = document.getElementById('agPaymentUpiAccountContainer');
    if (upiContainer) {
        upiContainer.style.display = hasUpi ? 'block' : 'none';
        if (hasUpi) {
            const upiSelect = document.getElementById('agPayUpiAccount');
            if (upiSelect.options.length <= 1) {
                upiSelect.innerHTML = '<option value="">Select Account</option>' +
                    (window.globalUpiAccounts || []).map(a => `<option value="${a.short_name || a.account_name}">${a.account_name} ${a.short_name ? `(${a.short_name})` : ''}</option>`).join('');
            }
        }
    }

    recalculateRemainingAmounts();
}

function recalculateRemainingAmounts() {
    const inputs = Array.from(document.querySelectorAll('.payment-amount-input'));
    let isValid = true;
    let totalPaid = 0;

    const formatAmount = (val) => {
        let num = parseFloat(val || 0);
        return num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    };

    inputs.forEach(input => {
        const val = parseFloat(input.value) || 0;
        if (val < 0) {
            isValid = false;
        }
        totalPaid += val;
    });

    let remaining = currentInvoiceAmount - totalPaid;

    // Set the same remaining balance for every row!
    inputs.forEach(input => {
        const method = input.getAttribute('data-method');
        const remainingEl = document.querySelector(`.remaining-val[data-method="${method}"]`);
        if (remainingEl) {
            remainingEl.textContent = `₹ ${formatAmount(remaining)}`;
        }
    });

    const finalRemainingEl = document.getElementById('agPaymentFinalRemaining');
    if (finalRemainingEl) {
        finalRemainingEl.textContent = `₹ ${formatAmount(remaining)}`;
    }

    const confirmBtn = document.getElementById('agConfirmPaymentBtn');
    if (confirmBtn) {
        payload.upi_account = payload.gpay_amount > 0 ? document.getElementById('agPayUpiAccount').value : null;
        if (payload.gpay_amount > 0 && !payload.upi_account) {
            toast('Please select a UPI Account', 'error');
            return;
        }

        const diff = Math.abs(totalPaid - currentInvoiceAmount);
        const isMatched = diff < 0.01;
        confirmBtn.disabled = !(isMatched && isValid && inputs.length > 0);
    }
}

async function submitFullPayment() {
    const purcId = document.getElementById('agPaymentPurcId').value;
    const suppId = document.getElementById('agPaymentSuppId').value;
    const inputs = document.querySelectorAll('.payment-amount-input');

    const payload = {
        cash_amount: 0,
        gpay_amount: 0,
        phonepe_amount: 0,
        bank_amount: 0
    };

    let totalPaid = 0;
    inputs.forEach(input => {
        const method = input.getAttribute('data-method');
        const val = parseFloat(input.value) || 0;
        totalPaid += val;

        if (method === 'Cash') payload.cash_amount = val;
        else if (method === 'UPI') payload.gpay_amount = val;
        else if (method === 'Bank Transfer') payload.bank_amount = val;
    });

    payload.upi_account = payload.gpay_amount > 0 ? document.getElementById('agPayUpiAccount').value : null;
    if (payload.gpay_amount > 0 && !payload.upi_account) {
        toast('Please select a UPI Account', 'error');
        return;
    }

    const diff = Math.abs(totalPaid - currentInvoiceAmount);
    if (diff >= 0.01) {
        toast('Total entered amount must equal the invoice pending amount', 'error');
        return;
    }

    try {
        const res = await api(`/api/agency/purchase/mark_paid/${purcId}`, {
            method: 'POST',
            body: payload
        });

        if (res.success) {
            toast('Payment updated successfully');
            closeModal('agPaymentModal');
            openSupplierDetails(suppId);
            loadAgencySuppliers();
        } else {
            toast(res.error || 'Failed to update payment', 'error');
        }
    } catch (e) {
        toast('Failed to update payment', 'error');
    }
}

// ════════ RETURNS ════════
async function openAgencyReturnModal() {
    if (!window.agencySuppliersData || agencySuppliersData.length === 0) {
        try { agencySuppliersData = await api('/api/agency/suppliers'); } catch (e) { agencySuppliersData = []; }
    }
    let suppOptions = '<option value="">Select Supplier...</option>';
    agencySuppliersData.forEach(s => { suppOptions += `<option value="${s.id}">${s.name}</option>`; });
    document.getElementById('agReturnSupplier').innerHTML = suppOptions;

    document.getElementById('agReturnDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('agReturnRef').value = '';
    document.getElementById('agReturnReason').value = '';
    document.getElementById('agReturnItemsBody').innerHTML = '';
    document.getElementById('agReturnSub').value = '0';
    document.getElementById('agReturnTax').value = '0';
    document.getElementById('agReturnGrand').value = '0';

    addAgReturnRow();
    openModal('agReturnModal');
}

function addAgReturnRow() {
    const tbody = document.getElementById('agReturnItemsBody');
    const tr = document.createElement('tr');

    let itemOptions = '<option value="">Select Item...</option>';
    if (window.agencyItemsData) {
        agencyItemsData.forEach(i => { itemOptions += `<option value="${i.id}">${i.item_name} (Agency: ${i.agency_name || 'N/A'}) (Stk: ${i.stock})</option>`; });
    }

    tr.innerHTML = `
        <td><select class="form-control ret-item" required onchange="calcAgReturnTotals()">${itemOptions}</select></td>
        <td><input type="text" class="form-control ret-batch" style="min-width:100px;"></td>
        <td><input type="number" class="form-control ret-qty" required style="min-width:80px;" value="1" oninput="calcAgReturnTotals()"></td>
        <td><input type="number" step="0.01" class="form-control ret-rate" required style="min-width:80px;" value="0" oninput="calcAgReturnTotals()"></td>
        <td><input type="number" step="0.01" class="form-control ret-tax" style="min-width:80px;" value="0" oninput="calcAgReturnTotals()"></td>
        <td><input type="number" step="0.01" class="form-control ret-total" readonly style="min-width:100px;" value="0"></td>
        <td><button type="button" class="btn btn-outline btn-sm" style="color:var(--danger); border:none;" onclick="this.closest('tr').remove(); calcAgReturnTotals();">X</button></td>
    `;
    tbody.appendChild(tr);
}

function calcAgReturnTotals() {
    let subTotal = 0;
    let taxTotal = 0;

    document.querySelectorAll('#agReturnItemsBody tr').forEach(tr => {
        let qty = parseFloat(tr.querySelector('.ret-qty').value) || 0;
        let rate = parseFloat(tr.querySelector('.ret-rate').value) || 0;
        let tax = parseFloat(tr.querySelector('.ret-tax').value) || 0;

        let lineSub = qty * rate;
        let lineTot = lineSub + tax;

        tr.querySelector('.ret-total').value = lineTot.toFixed(2);

        subTotal += lineSub;
        taxTotal += tax;
    });

    document.getElementById('agReturnSub').value = subTotal.toFixed(2);
    document.getElementById('agReturnTax').value = taxTotal.toFixed(2);
    document.getElementById('agReturnGrand').value = (subTotal + taxTotal).toFixed(2);
}

async function saveAgencyReturn() {
    const items = [];
    document.querySelectorAll('#agReturnItemsBody tr').forEach(tr => {
        let itemId = tr.querySelector('.ret-item').value;
        if (itemId) {
            items.push({
                item_id: itemId,
                batch_number: tr.querySelector('.ret-batch').value,
                return_quantity: parseInt(tr.querySelector('.ret-qty').value) || 0,
                unit_price: parseFloat(tr.querySelector('.ret-rate').value) || 0,
                tax_amount: parseFloat(tr.querySelector('.ret-tax').value) || 0,
                total_amount: parseFloat(tr.querySelector('.ret-total').value) || 0
            });
        }
    });

    if (items.length === 0) return toast('Add at least one item', 'error');

    const payload = {
        return_date: document.getElementById('agReturnDate').value,
        supplier_id: document.getElementById('agReturnSupplier').value,
        reference_number: document.getElementById('agReturnRef').value,
        reason: document.getElementById('agReturnReason').value,
        sub_total: parseFloat(document.getElementById('agReturnSub').value) || 0,
        tax_total: parseFloat(document.getElementById('agReturnTax').value) || 0,
        grand_total: parseFloat(document.getElementById('agReturnGrand').value) || 0,
        items: items
    };

    try {
        await api('/api/agency/returns/add', { method: 'POST', body: payload });
        toast('Return processed successfully');
        closeModal('agReturnModal');
        loadAgencyDashboard();
    } catch (e) {
        toast('Failed to process return', 'error');
    }
}

// Client-side auto-detection of medicine category from item name
function detectMedicineCategoryJS(itemName) {
    const name = itemName.toUpperCase().trim();
    const mapping = {
        'SPRAY': ['SPRAY', 'SPRAYS', 'SPRARY'],
        'OINT': ['OINT', 'OINTMENT', 'OINTMENTS'],
        'DROP': ['DROP', 'DROPS', 'DRP', 'DP'],
        'TAB': ['TAB', 'TABLET', 'TABLETS'],
        'CAP': ['CAP', 'CAPSULE', 'CAPSULES'],
        'SYP': ['SYP', 'SYRUP', 'SYRUPS'],
        'INJ': ['INJ', 'INJECTION', 'INJECTIONS'],
        'CRM': ['CRM', 'CREAM', 'CREAMS'],
        'GEL': ['GEL', 'GELS'],
        'POW': ['POW', 'POWDER', 'POWDERS'],
        'LOT': ['LOT', 'LOTION', 'LOTIONS']
    };
    for (const [category, patterns] of Object.entries(mapping)) {
        for (const pattern of patterns) {
            const regex = new RegExp('\\b' + pattern + '\\b');
            if (regex.test(name)) {
                return category;
            }
        }
    }
    return '';
}

document.addEventListener('DOMContentLoaded', () => {
    const itemNameInput = document.getElementById('agItemName');
    if (itemNameInput) {
        itemNameInput.addEventListener('input', function () {
            const detected = detectMedicineCategoryJS(this.value);
            if (detected) {
                const catSelect = document.getElementById('agItemCat');
                if (catSelect) {
                    catSelect.value = detected;
                    if (typeof catSelect.onchange === 'function') {
                        catSelect.onchange();
                    } else {
                        const otherInput = document.getElementById('agItemCatOther');
                        if (otherInput) otherInput.style.display = 'none';
                    }
                }
            }
        });
    }
});

async function saveLowStockAlert(id, buttonEl) {
    let inputEl = document.getElementById(`ag-min-stock-${id}`);
    if (buttonEl && buttonEl.closest('#agItemBody')) {
        const itemInput = document.getElementById(`ag-item-min-stock-${id}`);
        if (itemInput) inputEl = itemInput;
    }
    if (!inputEl) {
        inputEl = document.getElementById(`ag-item-min-stock-${id}`);
    }
    if (!inputEl) return;
    const val = parseInt(inputEl.value) || 0;

    inputEl.disabled = true;
    buttonEl.disabled = true;

    try {
        const res = await api('/api/agency/items/update-min-stock', {
            method: 'POST',
            body: { id: id, min_stock: val }
        });
        if (res.success) {
            toast('Low Stock Alert updated successfully');
            // Update in-memory data
            const item = currentAgencyStockData.find(item => item.id === id);
            if (item) {
                item.min_stock = val;
            }
            const masterItem = agencyItemsData.find(item => item.id === id);
            if (masterItem) {
                masterItem.min_stock = val;
            }

            // Re-render both locations to remain synchronized
            if (typeof currentAgencyStockType !== 'undefined' && currentAgencyStockType) {
                renderAgencyStockModal(currentAgencyStockData, currentAgencyStockType);
            }
            loadAgencyItems();
            loadAgencyDashboard();
        } else {
            toast(res.error || 'Failed to update alert', 'error');
        }
    } catch (e) {
        toast('Error updating alert threshold', 'error');
    } finally {
        if (inputEl) inputEl.disabled = false;
        if (buttonEl) buttonEl.disabled = false;
    }
}


function generateAgencyStockPDF(action) {
    if (typeof html2pdf === 'undefined') {
        toast("PDF library is loading, please try again.", "error");
        return;
    }

    Swal.fire({ title: 'Generating PDF...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let title = document.getElementById('agStockDetailsTitle').textContent;
    let tableHtml = document.getElementById('agStockDetailsTable')
        ? document.getElementById('agStockDetailsTable').outerHTML
        : document.getElementById('agStockDetailsContainer').innerHTML;

    let container = document.createElement('div');
    container.style.padding = '20px';
    container.style.fontFamily = 'sans-serif';
    container.innerHTML = `
        <style>
            table { width:100%; border-collapse:collapse; margin-top:20px; font-size: 12px; } 
            th, td { border:1px solid #ddd; padding:8px; text-align:left; } 
            th { background-color:#f2f2f2; }
        </style>
        <h2>${title}</h2>
        ${tableHtml}
    `;

    const opt = {
        margin: 0.3,
        filename: `${title.replace(/\s+/g, '_')}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'in', format: 'letter', orientation: 'landscape' }
    };

    if (action === 'download') {
        html2pdf().set(opt).from(container).save().then(() => {
            Swal.close();
            toast("Exported successfully!");
        });
    } else if (action === 'share') {
        html2pdf().set(opt).from(container).outputPdf('blob').then(blob => {
            Swal.close();
            const file = new File([blob], opt.filename, { type: 'application/pdf' });
            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share({
                    title: title,
                    files: [file]
                }).catch(err => {
                    console.log("Share failed:", err);
                    fallbackShare(blob, opt.filename);
                });
            } else {
                fallbackShare(blob, opt.filename);
            }
        });
    }
}

function fallbackShare(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    toast('Direct WhatsApp PDF sharing not supported on this browser. The PDF has been downloaded. Please attach it manually in WhatsApp.', 'info', 6000);
}

// ═════════════════════════════════════════════════════════════════════════════
// GENERIC MEDICINE MANAGEMENT MODULE JAVASCRIPT
// ═════════════════════════════════════════════════════════════════════════════
let gmAllData = []; // Store raw generics list
let gmFilteredData = []; // Store currently filtered list
let gmCurrentPage = 1;
const gmPageSize = 10;
let gmSelectedGeneric = ''; // Track currently active generic in detail view



// Load list of all unique generics with counts from the server
async function loadGenericMedicines() {
    try {
        // Reset to list view when tab is selected
        document.getElementById('gmListView').style.display = 'block';
        document.getElementById('gmDetailView').style.display = 'none';
        document.getElementById('gmSearchInput').value = '';
        
        const data = await api('/api/generics/list');
        gmAllData = data || [];
        gmFilteredData = [...gmAllData];
        gmCurrentPage = 1;

        // Update total stats
        document.getElementById('gmStatTotal').textContent = gmAllData.length;
        const totalBrands = gmAllData.reduce((acc, row) => acc + parseInt(row.brand_count || 0), 0);
        document.getElementById('gmStatBrands').textContent = totalBrands;

        renderGmList();
    } catch (e) {
        toast(e.message || 'Failed to load generic medicines', 'error');
    }
}

async function gmDeleteGeneric(genericName) {
    if (!confirm(`Are you sure you want to delete this Generic Medicine?\n\n"${genericName}"\n\nThis action cannot be undone.`)) {
        return;
    }
    try {
        const res = await api('/api/generics/delete-generic', {
            method: 'POST',
            body: { generic_name: genericName }
        });
        if (res.success) {
            toast(res.message || 'Generic medicine deleted successfully.', 'success');
            loadGenericMedicines();
        } else {
            toast(res.error || 'Failed to delete generic medicine.', 'error');
        }
    } catch (e) {
        toast(e.message || 'Failed to delete generic medicine.', 'error');
    }
}

window.gmDeleteBrandMapping = async function(brandName, batchNumber) {
    if (!confirm(`Are you sure you want to delete this Brand Medicine mapping?\n\n"${brandName}" (Batch: ${batchNumber})\n\nThis will clear its generic category mapping, but keep the medicine row in database.`)) {
        return;
    }
    try {
        const res = await api('/api/generics/delete-brand-mapping', {
            method: 'POST',
            body: { brand_name: brandName, batch_number: batchNumber }
        });
        if (res.success) {
            toast(res.message || 'Brand mapping deleted successfully.', 'success');
            if (gmSelectedGeneric) {
                gmViewBrands(gmSelectedGeneric);
            }
        } else {
            toast(res.error || 'Failed to delete brand mapping.', 'error');
        }
    } catch (e) {
        toast(e.message || 'Failed to delete brand mapping.', 'error');
    }
}

window.gmDeleteBrandAllMappings = async function(brandName) {
    if (!confirm(`Are you sure you want to delete ALL mappings for Brand "${brandName}"?\n\nThis will clear generic name mapping for all batches of this brand, but keep the medicine rows in the database.`)) {
        return;
    }
    try {
        const res = await api('/api/generics/delete-brand-all-mappings', {
            method: 'POST',
            body: { brand_name: brandName }
        });
        if (res.success) {
            toast(res.message || 'All brand mappings deleted successfully.', 'success');
            if (gmSelectedGeneric) {
                gmViewBrands(gmSelectedGeneric);
            }
        } else {
            toast(res.error || 'Failed to delete brand mappings.', 'error');
        }
    } catch (e) {
        toast(e.message || 'Failed to delete brand mappings.', 'error');
    }
}

// Filter the list based on search query
function gmFilterList(query) {
    const q = query.toLowerCase().trim();
    if (!q) {
        gmFilteredData = [...gmAllData];
    } else {
        gmFilteredData = gmAllData.filter(item => 
            (item.generic_name || '').toLowerCase().includes(q)
        );
    }
    gmCurrentPage = 1;
    renderGmList();
}

// Render the paginated list table
function renderGmList() {
    const start = (gmCurrentPage - 1) * gmPageSize;
    const end = start + gmPageSize;
    const paginatedItems = gmFilteredData.slice(start, end);
    const tbody = document.getElementById('gmListBody');

    // Update filtered stat
    document.getElementById('gmStatFiltered').textContent = `${gmFilteredData.length} of ${gmAllData.length}`;

    if (paginatedItems.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:30px; color:var(--text-secondary);">No generic medicines found.</td></tr>`;
        document.getElementById('gmPagination').innerHTML = '';
        return;
    }

    tbody.innerHTML = paginatedItems.map((item, index) => {
        const globalIdx = start + index + 1;
        const genericEscaped = (item.generic_name || '').replace(/'/g, "\\'");
        return `
            <tr>
                <td>${globalIdx}</td>
                <td><strong><a href="javascript:void(0)" onclick="gmViewBrands('${genericEscaped}')" style="color:var(--primary); font-weight:600; text-decoration:underline;">${item.generic_name}</a></strong></td>
                <td style="text-align:center;"><span class="badge" style="background:var(--primary-light); color:var(--primary); font-weight:600; font-size:0.9em; padding:4px 10px;">${item.brand_count}</span></td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick="gmViewBrands('${genericEscaped}')">View Brands</button>
                    <button class="btn btn-outline btn-sm" style="margin-left:6px;" onclick="gmEditGenericName('${genericEscaped}')">Edit</button>
                    <button class="btn btn-outline btn-sm" style="color:var(--danger); margin-left:6px;" onclick="gmDeleteGeneric('${genericEscaped}')">Delete</button>
                </td>
            </tr>
        `;
    }).join('');

    renderGmPagination();
}

// Render pagination controls
function renderGmPagination() {
    const totalPages = Math.ceil(gmFilteredData.length / gmPageSize);
    const container = document.getElementById('gmPagination');
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';
    // Previous button
    html += `<button class="btn btn-outline btn-sm" ${gmCurrentPage === 1 ? 'disabled style="opacity:0.5;"' : ''} onclick="gmGoToPage(${gmCurrentPage - 1})">Prev</button>`;

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= gmCurrentPage - 2 && i <= gmCurrentPage + 2)) {
            html += `<button class="btn btn-sm ${gmCurrentPage === i ? 'btn-primary' : 'btn-outline'}" onclick="gmGoToPage(${i})">${i}</button>`;
        } else if (i === gmCurrentPage - 3 || i === gmCurrentPage + 3) {
            html += `<span style="padding:0 4px; color:var(--text-light);">...</span>`;
        }
    }

    // Next button
    html += `<button class="btn btn-outline btn-sm" ${gmCurrentPage === totalPages ? 'disabled style="opacity:0.5;"' : ''} onclick="gmGoToPage(${gmCurrentPage + 1})">Next</button>`;

    container.innerHTML = html;
}

function gmGoToPage(page) {
    gmCurrentPage = page;
    renderGmList();
}

// View details for a selected generic medicine
async function gmViewBrands(genericName) {
    try {
        gmSelectedGeneric = genericName;
        document.getElementById('gmListView').style.display = 'none';
        document.getElementById('gmDetailView').style.display = 'block';
        document.getElementById('gmDetailGenericName').textContent = genericName;
        
        const tbody = document.getElementById('gmDetailBody');
        tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:30px; color:var(--text-secondary);">Loading brands…</td></tr>`;

        const brands = await api(`/api/generics/brands?generic=${encodeURIComponent(genericName)}`);
        
        document.getElementById('gmDetailBrandCount').textContent = `${brands.length} brand medicines mapped`;

        if (!brands || brands.length === 0) {
            tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:30px; color:var(--text-secondary);">No brands currently mapped to this generic.</td></tr>`;
            return;
        }

        tbody.innerHTML = brands.map((brand, idx) => {
            const brandJsonEsc = encodeURIComponent(JSON.stringify(brand));
            
            // Compute status badge
            const stock = parseInt(brand.stock || 0);
            const minStock = parseInt(brand.min_stock || 0);
            let statusBadge = '';
            if (stock <= 0) {
                statusBadge = `<span class="badge" style="background:var(--danger-light); color:var(--danger); font-weight:600; padding:4px 8px;">Out of Stock</span>`;
            } else if (stock <= minStock) {
                statusBadge = `<span class="badge" style="background:var(--warning-light); color:var(--warning); font-weight:600; padding:4px 8px;">Low Stock</span>`;
            } else {
                statusBadge = `<span class="badge" style="background:var(--emerald-light); color:var(--emerald); font-weight:600; padding:4px 8px;">In Stock</span>`;
            }

            return `
                <tr>
                    <td>${idx + 1}</td>
                    <td><strong>${brand.brand_name}</strong></td>
                    <td>${brand.supplier_name || 'Direct / Unknown'}</td>
                    <td><span style="font-weight:600; ${stock <= 0 ? 'color:var(--danger);' : 'color:var(--emerald);'}">${stock}</span></td>
                    <td><span class="badge" style="background:var(--bg-secondary); color:var(--text-primary); font-family:monospace;">${brand.batch_number || '-'}</span></td>
                    <td>₹${parseFloat(brand.mrp || 0).toFixed(2)}</td>
                    <td>${brand.expiry_date || '-'}</td>
                    <td>${brand.pack_size || '-'}</td>
                    <td>${brand.row_location || '-'}</td>
                    <td>${brand.col_location || '-'}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick="gmOpenEditModal('${brandJsonEsc}')">Edit Details</button>
                        <button class="btn btn-outline btn-sm" style="color:var(--danger); margin-left:6px;" onclick="gmDeleteBrandAllMappings('${brand.brand_name.replace(/'/g, "\\'")}')">Delete Mapping</button>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        toast(e.message || 'Failed to load brands mapping', 'error');
    }
}

// Return from detail view to main paginated list
function gmBackToList() {
    document.getElementById('gmListView').style.display = 'block';
    document.getElementById('gmDetailView').style.display = 'none';
    gmSelectedGeneric = '';
}

// Open Edit Mapping modal
// Open Edit Mapping modal using standard editMedModal
window.gmOpenEditModal = function(encodedBrand) {
    const brand = JSON.parse(decodeURIComponent(encodedBrand));
    const item = {
        id: brand.inventory_id || '',
        name: brand.brand_name || '',
        category: brand.category || 'Tablet',
        item_code: brand.item_code || '',
        hsn_code: brand.hsn_code || '',
        batch_number: brand.batch_number || '',
        mfg_date: brand.mfg_date || '',
        expiry_date: brand.expiry_date || '',
        purchase_price: brand.purchase_price || 0,
        selling_price: brand.selling_price || 0,
        mrp: brand.mrp || 0,
        stock: brand.stock || 0,
        tablets_per_strip: brand.pack_size || '',
        min_stock: brand.min_stock || 0,
        row_location: brand.row_location || '',
        col_location: brand.col_location || '',
        agency_name: brand.supplier_name || '',
        generic_name: brand.generic_name || '',
        brand_name: brand.brand_name || ''
    };
    if (typeof editMedModal === 'function') {
        editMedModal(item);
    } else {
        toast('Edit form is not available.', 'error');
    }
};

window.gmEditGenericName = function(genericName) {
    document.getElementById('gmEditGenericOldName').value = genericName;
    document.getElementById('gmEditGenericNewName').value = genericName;
    openModal('gmEditGenericModal');
};

window.gmSaveGenericName = async function() {
    const oldName = document.getElementById('gmEditGenericOldName').value.trim();
    const newName = document.getElementById('gmEditGenericNewName').value.trim();
    if (!newName) {
        toast('Generic name cannot be empty', 'error');
        return;
    }
    try {
        const res = await api('/api/generics/rename-generic', {
            method: 'POST',
            body: { old_name: oldName, new_name: newName }
        });
        if (res.success) {
            toast('Generic medicine renamed successfully!', 'success');
            closeModal('gmEditGenericModal');
            loadGenericMedicines();
        } else {
            toast(res.error || 'Failed to rename generic medicine', 'error');
        }
    } catch (e) {
        toast(e.message || 'Error renaming generic medicine', 'error');
    }
};

window.gmOpenAddBrandModal = function() {
    document.getElementById('invId').value = '';
    document.getElementById('invName').value = '';
    document.getElementById('invCat').value = 'Tablet';
    document.getElementById('invAgencyName').value = '';
    document.getElementById('invGenericName').value = gmSelectedGeneric;
    document.getElementById('invBrandName').value = '';
    document.getElementById('invCode').value = '';
    document.getElementById('invHsn').value = '';
    document.getElementById('invBatch').value = '';
    document.getElementById('invMfg').value = '';
    document.getElementById('invExpiry').value = '';
    document.getElementById('invPurchase').value = '0';
    document.getElementById('invSell').value = '0';
    document.getElementById('invMrp').value = '0';
    document.getElementById('invTabletsPerStrip').value = '';
    document.getElementById('invStock').value = '0';
    document.getElementById('invMinStock').value = '0';
    document.getElementById('invRow').value = '';
    document.getElementById('invCol').value = '';
    
    document.getElementById('invModalTitle').textContent = `Add Brand for: ${gmSelectedGeneric}`;
    
    if (typeof toggleInventoryFields === 'function') toggleInventoryFields();
    openModal('invModal');
};

// Handle client-side file upload & parsing for Excel (.xlsx, .xls), CSV, and PDF
// Handle client-side Excel/CSV upload & parsing for Generic Name import only
window.handleGenericImportExcel = function(input) {
    const file = input.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = async function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const json = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
            
            let genericColIdx = 0;
            if (json.length > 0) {
                const headers = json[0].map(h => String(h || '').toLowerCase().trim());
                let gIdx = headers.findIndex(h => h === 'generic name' || h === 'generic medicine' || h === 'medicine name' || h === 'item name' || h === 'generic');
                if (gIdx === -1) {
                    gIdx = headers.findIndex(h => 
                        (h.includes('generic') || h.includes('formula') || h.includes('medicine') || h.includes('name')) && 
                        !h.includes('brand') && !h.includes('company') && !h.includes('supplier') && !h.includes('first') && !h.includes('last')
                    );
                }
                
                if (gIdx !== -1) {
                    genericColIdx = gIdx;
                } else if (headers.length > 1 && json.length > 1) {
                    // Fallback: guess by looking at the first data row
                    for(let c = 0; c < json[1].length; c++) {
                        const val = String(json[1][c] || '').trim();
                        if (val && isNaN(Number(val)) && val.length > 2 && val.toLowerCase() !== 's.no' && val.toLowerCase() !== 'sno') {
                            genericColIdx = c;
                            break;
                        }
                    }
                }
            }
            
            const mappings = [];
            for (let i = 1; i < json.length; i++) {
                const row = json[i];
                if (!row) continue;
                const generic = row[genericColIdx] ? String(row[genericColIdx]).trim() : '';
                if (generic) {
                    mappings.push({
                        brand_name: '',
                        generic_name: generic
                    });
                }
            }
            
            if (mappings.length === 0) {
                toast('No valid generic medicines found in the Excel file.', 'error');
                return;
            }
            
            await submitImportedMappings(mappings);
        } catch (err) {
            toast('Error reading Excel: ' + err.message, 'error');
        }
    };
    reader.readAsArrayBuffer(file);
    input.value = '';
};

// Handle client-side PDF upload & parsing for Generic Name import only
window.handleGenericImportPdf = function(input) {
    const file = input.files[0];
    if (!file) return;
    
    toast('Extracting generic medicines from PDF...', 'info');
    const reader = new FileReader();
    reader.onload = async function(e) {
        try {
            const pdfjsLib = window['pdfjs-dist/build/pdf'];
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
            
            const typedarray = new Uint8Array(e.target.result);
            const pdf = await pdfjsLib.getDocument({ data: typedarray }).promise;
            
            let textLines = [];
            for (let i = 1; i <= pdf.numPages; i++) {
                const page = await pdf.getPage(i);
                const textContent = await page.getTextContent();
                let pageLines = textContent.items.map(item => item.str);
                textLines = textLines.concat(pageLines);
            }
            
            const mappings = [];
            textLines.forEach(line => {
                const cleanLine = line.trim();
                if (cleanLine && cleanLine.length < 80) {
                    mappings.push({
                        brand_name: '',
                        generic_name: cleanLine
                    });
                }
            });
            
            if (mappings.length === 0) {
                toast('No valid text lines found in PDF.', 'warning');
                return;
            }
            
            await submitImportedMappings(mappings);
        } catch (err) {
            toast('Error reading PDF: ' + err.message, 'error');
        }
    };
    reader.readAsArrayBuffer(file);
    input.value = '';
};

// Fallback for cached HTML imports
window.handleGenericImport = function(input) {
    const file = input.files[0];
    if (!file) return;
    const extension = file.name.split('.').pop().toLowerCase();
    if (extension === 'pdf') {
        window.handleGenericImportPdf(input);
    } else {
        window.handleGenericImportExcel(input);
    }
};

// Send mappings to backend
async function submitImportedMappings(mappings) {
    try {
        const response = await api('/api/generics/import', {
            method: 'POST',
            body: { mappings: mappings }
        });
        
        if (response.success) {
            Swal.fire({
                title: 'Import Mappings Result',
                text: response.message,
                icon: 'success',
                confirmButtonColor: 'var(--primary)'
            });
            loadGenericMedicines();
        } else {
            Swal.fire({
                title: 'Import Failed',
                text: response.error || 'Failed to import mappings',
                icon: 'error',
                confirmButtonColor: 'var(--primary)'
            });
        }
    } catch (e) {
        Swal.fire({
            title: 'Import Failed',
            text: e.message || 'Failed to import mappings',
            icon: 'error',
            confirmButtonColor: 'var(--primary)'
        });
    }
}



