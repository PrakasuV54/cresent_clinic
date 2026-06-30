        async function loadInventory() {
            const q = document.getElementById('invSearch').value;
            const inc = document.getElementById('invShowEmpty').checked ? '1' : '0';
            try {
                const res = await api(`/api/inventory/search?q=${encodeURIComponent(q)}&all=${inc}`);
                const tbody = document.getElementById('inventoryBody');
                tbody.innerHTML = res.map(i => {
                    let stockVal = i.stock;
                    let displayStock = stockVal;
                    const catLower = (i.category || '').toLowerCase();
                    const isTablet = catLower === 'tablet' || catLower === 'tab' || catLower === 'tablets';
                    if (isTablet && i.tablets_per_strip > 0) {
                        const strips = Math.floor(i.stock / i.tablets_per_strip);
                        displayStock = `${strips} Strips <br><small style="color:var(--text-muted)">(${i.stock} Tablets)</small>`;
                    }
                    let stockCol = i.stock <= (i.min_stock || 0) ? `<span style="color:var(--danger);font-weight:bold;">${displayStock} (Low)</span>` : displayStock;
                    return `<tr>
                        <td>${i.item_code || '-'}</td>
                        <td>
                            <strong>${i.name}</strong><br>
                            <span style="font-size:0.8em; color:var(--text-secondary);">${i.agency_name || 'No Agency'}</span><br>
                            ${i.generic_name ? `<span style="font-size:0.8em; color:#6366f1; font-style:italic;">Generic: ${i.generic_name}</span>` : ''}
                        </td>
                        <td>${i.category || '-'}</td>
                        <td><span class="badge" style="background:var(--bg-hover);color:var(--text-primary);">${i.batch_number}</span></td>
                        <td>${i.mfg_date || '-'}</td>
                        <td>${i.expiry_date || '-'}</td>
                        <td>₹${(i.mrp || 0).toFixed(2)}</td>
                        <td>₹${(i.purchase_price || 0).toFixed(2)}</td>
                        <td>₹${(i.selling_price || 0).toFixed(2)}</td>
                        <td>${stockCol}</td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick='editMedModal(${JSON.stringify(i).replace(/'/g, "&apos;")})'>Edit</button>
                              <button class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger);" onclick="deleteInventory(${i.id})">Delete</button>
                        </td>
                    </tr>`;
                }).join('');
            } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
        }

        function toggleInventoryFields() {
            const cat = document.getElementById('invCat').value;
            const otherCat = document.getElementById('invCatOther');
            const groupBatch = document.getElementById('invGroupBatch');
            const groupCodes = document.getElementById('invGroupCodes');
            const divider = document.getElementById('invDivider');
            const labelStock = document.getElementById('labelStock');
            const invBatchInput = document.getElementById('invBatch');
            const groupTPS = document.getElementById('groupTabletsPerStrip');
            const groupNS = document.getElementById('groupNumStrips');

            if (cat === 'Other') {
                otherCat.style.display = 'block';
                otherCat.required = true;
            } else {
                otherCat.style.display = 'none';
                otherCat.required = false;
            }

            // Reset defaults
            groupBatch.style.display = 'grid';
            groupCodes.style.display = 'grid';
            divider.style.display = 'block';
            invBatchInput.required = false;
            if (groupTPS) groupTPS.style.display = 'none';
            if (groupNS) groupNS.style.display = 'none';
            const labelStockUnit = document.getElementById('labelStockUnit');
            const groupTotal = document.getElementById('groupTotalTablets');

            if (groupTotal) groupTotal.style.display = 'none';
            if (labelStockUnit) labelStockUnit.style.display = 'none';
            document.getElementById('invPricePreviewGroup').style.display = 'none';

            let actualCat = cat;
            if (cat === 'Other') {
                actualCat = document.getElementById('invCatOther').value.trim();
            }
            const catLower = (actualCat || '').toLowerCase();
            const isTablet = catLower === 'tablet' || catLower === 'tab' || catLower === 'tablets';

            if (isTablet) {
                labelStock.textContent = 'Current Stock';
                if (labelStockUnit) labelStockUnit.style.display = 'block';
                if (groupTPS) groupTPS.style.display = 'block';
                if (groupTotal) groupTotal.style.display = 'block';
                document.getElementById('invPricePreviewGroup').style.display = 'block';
                calcTotalTablets();
                calcTabletPricePreview();
            } else if (actualCat === 'Syrup' || catLower === 'syp') {
                labelStock.textContent = 'Current Stock (Total Bottles)';
            } else if (actualCat === 'Injection' || catLower === 'inj') {
                labelStock.textContent = 'Current Stock (Total Vials/Amps)';
            } else if (actualCat === 'Drops' || catLower === 'drop' || catLower === 'drp') {
                labelStock.textContent = 'Current Stock (Total Bottles)';
            } else if (actualCat === 'IV') {
                labelStock.textContent = 'Current Stock (Total Bags)';
            } else if (actualCat === 'UPT Card') {
                labelStock.textContent = 'Current Stock (Total Units)';
                groupBatch.style.display = 'none';
                divider.style.display = 'none';
                invBatchInput.required = false;
            } else {
                labelStock.textContent = 'Current Stock';
            }
        }

        function calcTotalTablets() {
            let cat = document.getElementById('invCat').value;
            if (cat === 'Other') cat = document.getElementById('invCatOther').value;
            const catLower = (cat || '').toLowerCase();
            const isTablet = catLower === 'tablet' || catLower === 'tab' || catLower === 'tablets';
            if (!isTablet) return;
            const tps = parseInt(document.getElementById('invTabletsPerStrip').value) || 0;
            const strips = parseInt(document.getElementById('invStock').value) || 0;
            const total = document.getElementById('invTotalTablets');
            if (total) total.value = tps * strips;
        }

        function calcTabletPricePreview() {
            let cat = document.getElementById('invCat').value;
            if (cat === 'Other') cat = document.getElementById('invCatOther').value;
            const catLower = (cat || '').toLowerCase();
            const isTablet = catLower === 'tablet' || catLower === 'tab' || catLower === 'tablets';
            if (!isTablet) return;

            const tps = parseInt(document.getElementById('invTabletsPerStrip').value) || 1;
            const actualTps = tps > 0 ? tps : 1;

            let purchase = parseFloat(document.getElementById('invPurchase').value) || 0;
            let sell = parseFloat(document.getElementById('invSell').value) || 0;
            let mrp = parseFloat(document.getElementById('invMrp').value) || 0;

            document.getElementById('prevPurch').textContent = (purchase / actualTps).toFixed(2);
            document.getElementById('prevSell').textContent = (sell / actualTps).toFixed(2);
            document.getElementById('prevMrp').textContent = (mrp / actualTps).toFixed(2);
        }

        async function suggestInvMedicine(input) {
            const q = input.value.trim();
            const suggBox = document.getElementById('invSuggestions');
            if (q.length < 2) {
                suggBox.style.display = 'none';
                return;
            }
            try {
                const res = await api(`/api/inventory/search?q=${encodeURIComponent(q)}&all=1`);
                // Filter to get unique combinations of name/category/mrp
                const unique = [];
                const seen = new Set();
                res.forEach(item => {
                    const key = `${item.name}-${item.category}-${item.mrp}`;
                    if (!seen.has(key)) {
                        unique.push(item);
                        seen.add(key);
                    }
                });

                if (unique.length > 0) {
                    suggBox.innerHTML = unique.map(item => `
                        <div class="med-sugg-item" style="padding:10px 12px; cursor:pointer; border-bottom:1px solid var(--border); transition: background 0.15s;" 
                             onmouseenter="this.style.background='var(--bg-hover)'"
                             onmouseleave="this.style.background=''"
                             onclick="selectInvSuggestion('${item.name.replace(/'/g, "\\'")}', '${item.category}', ${item.mrp}, ${item.selling_price}, ${item.purchase_price}, ${item.tablets_per_strip || 0})">
                            <div style="font-weight:600; color:var(--text-primary);">${item.name}</div>
                            <div style="font-size:0.75rem; color:var(--text-secondary);">Cat: ${item.category} | MRP: ₹${(item.mrp || 0).toFixed(2)}</div>
                        </div>
                    `).join('');
                    suggBox.style.display = 'block';
                } else {
                    suggBox.style.display = 'none';
                }
            } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
        }

        function selectInvSuggestion(name, cat, mrp, sell, purchase, tps) {
            document.getElementById('invName').value = name;
            document.getElementById('invCat').value = cat;
            document.getElementById('invMrp').value = mrp;
            document.getElementById('invSell').value = sell;
            document.getElementById('invPurchase').value = purchase;
            document.getElementById('invTabletsPerStrip').value = tps;
            document.getElementById('invSuggestions').style.display = 'none';
            toggleInventoryFields();
            calcTotalTablets();
        }

        // Hide suggestions when clicking outside
        document.addEventListener('click', function (e) {
            const suggBox = document.getElementById('invSuggestions');
            if (suggBox && e.target.id !== 'invName') {
                suggBox.style.display = 'none';
            }
        });

        async function populateInvCat() {
            const sel = document.getElementById('invCat');
            if (!sel) return;

            const standardCategories = ['TAB', 'CAP', 'SYP', 'INJ', 'CRM', 'GEL', 'DROP', 'POW', 'LOT', 'SPRAY', 'OINT', 'Surgical', 'Other'];
            let options = '';

            // Standard categories
            standardCategories.forEach(cat => {
                options += `<option value="${cat}">${cat}</option>`;
            });

            // Custom categories dynamically loaded from agency categories
            let agencyCats = [];
            try {
                agencyCats = await api('/api/agency/categories');
            } catch (e) {
                console.error("Failed to load agency categories", e);
            }

            if (Array.isArray(agencyCats)) {
                agencyCats.forEach(c => {
                    if (!standardCategories.includes(c.name)) {
                        options += `<option value="${c.name}">${c.name}</option>`;
                    }
                });
            }

            // Keep compatibility with existing medicines' categories by appending any unique category present in inventoryData to the list
            if (typeof inventoryData !== 'undefined' && Array.isArray(inventoryData)) {
                const existingCats = [...new Set(inventoryData.map(i => i.category))].filter(c => c && !standardCategories.includes(c) && !agencyCats.some(ac => ac.name === c));
                existingCats.forEach(cat => {
                    options += `<option value="${cat}">${cat}</option>`;
                });
            }

            sel.innerHTML = options;
        }

        async function editMedModal(item) {
            await populateInvCat();
            document.getElementById('invForm').reset();
            document.getElementById('invModalTitle').textContent = 'Edit Medicine / Batch';
            document.getElementById('invId').value = item.id;
            document.getElementById('invName').value = item.name;

            // Set category logic
            const sel = document.getElementById('invCat');
            const otherCat = document.getElementById('invCatOther');

            let isStandardOrKnown = false;
            for (let i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === item.category && item.category !== 'Other' && item.category !== '') {
                    isStandardOrKnown = true;
                    break;
                }
            }

            if (isStandardOrKnown) {
                sel.value = item.category;
                otherCat.style.display = 'none';
                otherCat.value = '';
            } else {
                sel.value = 'Other';
                otherCat.style.display = 'block';
                otherCat.value = item.category || '';
            }

            document.getElementById('invCode').value = item.item_code || '';
            document.getElementById('invHsn').value = item.hsn_code || '';
            document.getElementById('invBatch').value = item.batch_number || '';
            document.getElementById('invMfg').value = item.mfg_date || '';
            document.getElementById('invExpiry').value = item.expiry_date || '';
            document.getElementById('invPurchase').value = item.purchase_price || 0;
            document.getElementById('invSell').value = item.selling_price || 0;
            document.getElementById('invMrp').value = item.mrp || 0;
            let stockVal = item.stock || 0;
            let tpsVal = item.tablets_per_strip || '';
            const catLower = (item.category || '').toLowerCase();
            const isTablet = catLower === 'tablet' || catLower === 'tab' || catLower === 'tablets';
            if (isTablet && tpsVal > 0) {
                stockVal = Math.floor(item.stock / tpsVal);
            }
            document.getElementById('invStock').value = stockVal;
            document.getElementById('invTabletsPerStrip').value = tpsVal;
            document.getElementById('invMinStock').value = item.min_stock || 0;
            document.getElementById('invRow').value = (item.row_location || '').toUpperCase();
            document.getElementById('invCol').value = (item.col_location || '').toUpperCase();
            document.getElementById('invAgencyName').value = item.agency_name || '';
            document.getElementById('invGenericName').value = item.generic_name || '';
            document.getElementById('invBrandName').value = item.brand_name || '';
            toggleInventoryFields();
            openModal('invModal');
        }

        async function openMedModal() {
            await populateInvCat();
            document.getElementById('invForm').reset();
            document.getElementById('invId').value = '';
            document.getElementById('invMinStock').value = '0';
            document.getElementById('invRow').value = '';
            document.getElementById('invCol').value = '';
            document.getElementById('invAgencyName').value = '';
            document.getElementById('invGenericName').value = '';
            document.getElementById('invBrandName').value = '';
            document.getElementById('invCatOther').style.display = 'none';
            document.getElementById('invCatOther').required = false;
            document.getElementById('invModalTitle').textContent = 'Add New Medicine / Batch';
            toggleInventoryFields(); // Set initial state
            openModal('invModal');
        }

        async function saveInventory() {
            const invId = document.getElementById('invId').value;
            let catValue = document.getElementById('invCat').value;
            if (catValue === 'Other') {
                catValue = document.getElementById('invCatOther').value.trim();
            }
            const catLower = (catValue || '').toLowerCase();
            const isTablet = catLower === 'tablet' || catLower === 'tab' || catLower === 'tablets';
            const payload = {
                name: document.getElementById('invName').value.trim(),
                category: catValue,
                item_code: document.getElementById('invCode').value.trim(),
                hsn_code: document.getElementById('invHsn').value.trim(),
                batch_number: document.getElementById('invBatch').value.trim(),
                mfg_date: document.getElementById('invMfg').value.trim(),
                expiry_date: document.getElementById('invExpiry').value.trim(),
                purchase_price: parseFloat(document.getElementById('invPurchase').value) || 0,
                selling_price: parseFloat(document.getElementById('invSell').value) || 0,
                mrp: parseFloat(document.getElementById('invMrp').value) || 0,
                stock: (isTablet && parseInt(document.getElementById('invTabletsPerStrip').value) > 0) ? (parseInt(document.getElementById('invTotalTablets').value) || 0) : (parseInt(document.getElementById('invStock').value) || 0),
                tablets_per_strip: parseInt(document.getElementById('invTabletsPerStrip').value) || 0,
                min_stock: parseInt(document.getElementById('invMinStock').value) || 0,
                row_location: (document.getElementById('invRow').value || '').trim().toUpperCase(),
                col_location: (document.getElementById('invCol').value || '').trim().toUpperCase(),
                agency_name: (document.getElementById('invAgencyName').value || '').trim(),
                generic_name: (document.getElementById('invGenericName').value || '').trim(),
                brand_name: (document.getElementById('invBrandName').value || '').trim()
            };
            if (invId) { payload.id = invId; }
            try {
                const url = invId ? '/api/inventory/update' : '/api/inventory/add';
                const res = await api(url, { method: 'POST', body: payload });
                if (res.success) {
                    toast(invId ? 'Item updated successfully!' : 'Batch saved successfully!');
                    closeModal('invModal');
                    loadInventory();
                    const masterInv = document.getElementById('master-inventory');
                    if (masterInv && masterInv.style.display !== 'none') {
                        loadMasterInventory();
                    }
                    if (typeof gmSelectedGeneric !== 'undefined' && gmSelectedGeneric) {
                        gmViewBrands(gmSelectedGeneric);
                    }
                }
            } catch (e) { if (e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
        }

        async function deleteInventory(id) {
            if (!confirm('Are you sure you want to delete this batch?')) return;
            try {
                await api(`/api/inventory/delete/${id}`, { method: 'DELETE' });
                toast('Deleted successfully');
                loadInventory();
                const masterInv = document.getElementById('master-inventory');
                if (masterInv && masterInv.style.display !== 'none') {
                    loadMasterInventory();
                }
            } catch(e) { if(e.message) toast(e.message, 'error'); else toast('Operation failed', 'error'); }
        }
        // ─── DIRECT MEDICINE SALES – Admin Functions ───
        let _dsCurrentFilter = 'today';

