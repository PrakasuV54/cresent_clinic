// ═══════════════════════════════════════════
// REPORT DASHBOARD LOGIC
// ═══════════════════════════════════════════

let reportData = null;

async function loadReportDashboard(period = null, force = false) {
    const reportSection = document.getElementById('sectionReportDashboard');
    // Proceed with fetching data even if hidden, so it's ready when viewed

    if (!period) {
        period = document.getElementById('reportPeriod').value;
    }

    let start = '';
    let end = '';
    if (period === 'custom') {
        start = document.getElementById('reportDateStart').value;
        end = document.getElementById('reportDateEnd').value;
        if (!start || !end) return; // Wait for both dates
    }

    try {
        const url = `/reports_api.php?action=get_reports&period=${period}&start=${start}&end=${end}&_=${new Date().getTime()}`;
        const res = await fetch(url).then(r => r.json());

        reportData = res;
        renderReportDashboard(res);
    } catch (e) {
        console.error("Error loading reports", e);
    }
}

function renderReportDashboard(data) {
    if (!data.executive) return;

    if (data.backup_settings) {
        syncBackupSettingsInputs(data.backup_settings);
    }

    // Executive Summary
    document.getElementById('repTotalPatients').textContent = data.executive.total_patients;
    if (document.getElementById('repDirectCustomers')) {
        document.getElementById('repDirectCustomers').textContent = data.executive.direct_sales_count || 0;
    }
    document.getElementById('repTotalRevenue').textContent = '₹' + data.executive.total_revenue.toFixed(2);
    document.getElementById('repNetProfit').textContent = '₹' + data.executive.net_profit.toFixed(2);

    // Secondary Summaries
    if (document.getElementById('repGentsFee')) document.getElementById('repGentsFee').textContent = '₹' + (data.executive.gents_doctor_revenue || 0).toFixed(2);
    if (document.getElementById('repLadiesFee')) document.getElementById('repLadiesFee').textContent = '₹' + (data.executive.ladies_doctor_revenue || 0).toFixed(2);
    if (document.getElementById('repPendingFees')) document.getElementById('repPendingFees').textContent = '₹' + (data.executive.pending_amount || 0).toFixed(2);
    if (document.getElementById('repTotalReturns')) document.getElementById('repTotalReturns').textContent = '₹' + (data.executive.total_returns || 0).toFixed(2);

    // Doctor Report Table
    const docTbody = document.getElementById('repDoctorTable');
    if (docTbody) {
        docTbody.innerHTML = '';
        if (data.doctors && data.doctors.length > 0) {
            data.doctors.forEach(d => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${d.doctor_name}</td>
                    <td>${d.doctor_type}</td>
                    <td>${d.p_count}</td>
                    <td>₹${(d.doc_rev || 0).toFixed(2)}</td>
                    <td>₹${(d.med_rev || 0).toFixed(2)}</td>
                    <td>₹${((d.doc_rev || 0) + (d.med_rev || 0)).toFixed(2)}</td>
                `;
                docTbody.appendChild(tr);
            });
        } else {
            docTbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No data available</td></tr>';
        }
    }

    // Bind datasets to window context for index-based details retrieval
    window.currentReportPatients = data.patients || [];
    window.currentReportDirectSales = data.direct_sales || [];

    // Patient Report Table
    const patTbody = document.getElementById('repPatientTable');
    if (patTbody) {
        patTbody.innerHTML = '';
        if (window.currentReportPatients.length > 0) {
            window.currentReportPatients.forEach((p, idx) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${p.name}</td>
                    <td>${p.phone}</td>
                    <td>${p.doctor_name}</td>
                    <td>${(p.created_at || '').split(' ')[0]}</td>
                    <td>₹${(p.consultation_fee || 0).toFixed(2)}</td>
                    <td>₹${(p.total_amount || 0).toFixed(2)}</td>
                    <td>
                        <span class="status-badge status-${p.balance_amount > 0 ? 'pending' : 'completed'}">
                            ${p.balance_amount > 0 ? 'Pending (₹' + p.balance_amount + ')' : 'Paid'}
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick="showPatientReportDetailsByIndex(${idx})">Details</button>
                    </td>
                `;
                patTbody.appendChild(tr);
            });
        } else {
            patTbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">No patients found</td></tr>';
        }
    }

    // Top Medicines
    const medTbody = document.getElementById('repMedicineTable');
    if (medTbody) {
        medTbody.innerHTML = '';
        if (data.top_medicines && data.top_medicines.length > 0) {
            data.top_medicines.forEach(m => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${m.name}</td>
                    <td>${m.qty}</td>
                    <td>₹${(m.revenue || 0).toFixed(2)}</td>
                `;
                medTbody.appendChild(tr);
            });
        } else {
            medTbody.innerHTML = '<tr><td colspan="3" style="text-align:center;">No medicine sales</td></tr>';
        }
    }

    // Returns Report Table
    const retTbody = document.getElementById('repReturnsTable');
    if (retTbody) {
        retTbody.innerHTML = '';
        if (data.returns && data.returns.length > 0) {
            data.returns.forEach(r => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${r.return_date} ${r.return_time}</td>
                    <td>${r.patient_name || '-'}</td>
                    <td>${r.bill_number || '-'}</td>
                    <td>${r.medicine_name}</td>
                    <td>${r.returned_qty}</td>
                    <td style="color:var(--danger); font-weight:600;">₹${parseFloat(r.return_amount || 0).toFixed(2)}</td>
                    <td>${r.refund_payment_mode || 'Cash'}</td>
                    <td>${r.processed_by || '-'}</td>
                `;
                retTbody.appendChild(tr);
            });
        } else {
            retTbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">No returns found</td></tr>';
        }
    }

    // Direct Sales Report Table
    const dsTbody = document.getElementById('repDirectSalesTable');
    if (dsTbody) {
        dsTbody.innerHTML = '';
        if (window.currentReportDirectSales.length > 0) {
            window.currentReportDirectSales.forEach((s, idx) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${s.customer_name}</td>
                    <td>${s.mobile_number}</td>
                    <td>${s.parsed_medicines || ''}</td>
                    <td style="font-weight:bold;">₹${parseFloat(s.total_amount || 0).toFixed(2)}</td>
                    <td>₹${parseFloat(s.paid_amount || 0).toFixed(2)}</td>
                    <td>
                        <span class="status-badge status-${s.balance_amount > 0 ? 'pending' : 'completed'}">
                            ${s.balance_amount > 0 ? 'Pending (₹' + s.balance_amount + ')' : 'Paid'}
                        </span>
                    </td>
                    <td>${(s.created_at || '').split(' ')[0]}</td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick="showDirectSaleReportDetailsByIndex(${idx})">Details</button>
                    </td>
                `;
                dsTbody.appendChild(tr);
            });
        } else {
            dsTbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">No direct sales found</td></tr>';
        }
    }

    // Agency Purchases Table
    const apTbody = document.getElementById('repAgencyPurchasesTable');
    if (apTbody) {
        // Dynamically update the table headers to match Supplier Payment columns
        const parentTable = apTbody.closest('table');
        if (parentTable) {
            const thead = parentTable.querySelector('thead');
            if (thead) {
                thead.innerHTML = `
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>GST</th>
                        <th>Total Purchases</th>
                        <th>Paid or Not</th>
                        <th>Payment Pending</th>
                        <th>Actions</th>
                    </tr>
                `;
            }
        }

        apTbody.innerHTML = '';
        const suppliersList = data.agency_suppliers || [];
        if (suppliersList.length > 0) {
            suppliersList.forEach(s => {
                const total_purchased = parseFloat(s.total_purchase || 0).toFixed(2);
                const total_paid = parseFloat(s.paid_amount || 0).toFixed(2);
                const total_pending = parseFloat(s.pending_balance || 0).toFixed(2);

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

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><strong>${s.name}</strong></td>
                    <td>${s.phone || '-'}</td>
                    <td>${s.gst_number || '-'}</td>
                    <td style="color:var(--primary); font-weight:bold;">₹ ${total_purchased}</td>
                    <td>${paid_or_not_html}</td>
                    <td>${pending_html}</td>
                    <td>
                        <button class="btn btn-primary btn-sm" onclick="openReportSupplierDetails(${s.id})">View Details</button>
                    </td>
                `;
                apTbody.appendChild(tr);
            });
        } else {
            apTbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">No supplier purchases found</td></tr>';
        }
    }

    // Inventory Summary Table
    const invTbody = document.getElementById('repInventoryTable');
    if (invTbody) {
        invTbody.innerHTML = '';
        if (data.inventory && data.inventory.length > 0) {
            data.inventory.forEach(i => {
                const tr = document.createElement('tr');
                const isLow = i.stock <= (i.min_stock || 0);
                const statusBadge = isLow ? `<span class="badge" style="background:var(--danger-light); color:var(--danger); font-weight:600; padding:2px 6px; border-radius:4px; font-size:0.85em;">Low Stock</span>` : `<span class="badge" style="background:var(--emerald-light); color:var(--emerald); font-weight:600; padding:2px 6px; border-radius:4px; font-size:0.85em;">Normal</span>`;
                tr.innerHTML = `
                    <td><strong>${i.name}</strong></td>
                    <td>${i.category || '-'}</td>
                    <td>${i.batch_number}</td>
                    <td>${i.stock}</td>
                    <td>${i.min_stock || 0}</td>
                    <td>₹${(i.mrp || 0).toFixed(2)}</td>
                    <td>${statusBadge}</td>
                `;
                invTbody.appendChild(tr);
            });
        } else {
            invTbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">No inventory items found</td></tr>';
        }
    }

    // Daily Statistics Table
    const dailyTbody = document.getElementById('repDailyStatsTable');
    if (dailyTbody) {
        dailyTbody.innerHTML = '';
        if (data.daily_stats && data.daily_stats.length > 0) {
            data.daily_stats.forEach(d => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${d.date}</td>
                    <td>₹${d.doc_fee.toFixed(2)}</td>
                    <td>₹${d.med_fee.toFixed(2)}</td>
                    <td>₹${d.ds_fee.toFixed(2)}</td>
                    <td>₹${d.scan_fee.toFixed(2)}</td>
                    <td style="font-weight:bold; color:var(--emerald);">₹${d.total.toFixed(2)}</td>
                `;
                dailyTbody.appendChild(tr);
            });
        } else {
            dailyTbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No daily statistics available</td></tr>';
        }
    }
}

function switchReportTab(tabId) {
    document.querySelectorAll('.report-tab-btn').forEach(b => {
        b.classList.remove('active');
        b.style.borderBottom = 'none';
        b.style.color = 'var(--text-muted)';
        if (b.innerText.trim() === 'Returns') b.style.color = 'var(--danger)'; // Default for Returns
    });
    document.querySelectorAll('.report-tab-content').forEach(c => c.style.display = 'none');

    const activeBtn = document.querySelector(`[onclick="switchReportTab('${tabId}')"]`);
    if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.style.borderBottom = '3px solid var(--primary)';
        activeBtn.style.color = 'var(--primary)';
        if (tabId === 'rtReturns') {
            activeBtn.style.borderBottom = '3px solid var(--danger)';
            activeBtn.style.color = 'var(--danger)';
        }
    }
    document.getElementById(tabId).style.display = 'block';
}

async function exportReport(format) {
    const period = document.getElementById('reportPeriod').value;
    let start = document.getElementById('reportDateStart').value;
    let end = document.getElementById('reportDateEnd').value;

    try {
        const btn = document.querySelector(`[onclick="exportReport('${format}')"]`);
        const oldText = btn ? btn.innerHTML : 'Export';
        if (btn) { btn.innerHTML = 'Generating...'; btn.disabled = true; }

        const url = `/reports_api.php?action=get_print_report&period=${period}&start=${start}&end=${end}&_=${new Date().getTime()}`;
        const data = await fetch(url).then(r => r.json());

        if (!data || !data.executive) {
            throw new Error("Invalid or empty report payload received from server.");
        }

        if (format === 'csv' || format === 'excel') {
            generateExcelReport(data, period, start, end);
        } else if (format === 'print' || format === 'pdf' || format === 'whatsapp') {
            generatePrintableReport(data, period, start, end, format);
        }

        if (btn) { btn.innerHTML = oldText; btn.disabled = false; }
    } catch (e) {
        console.error("Error generating report", e);
        alert("Failed to generate report.");
    }
}

function generateExcelReport(data, period, start, end) {
    if (typeof XLSX === 'undefined') {
        alert("Excel export library is loading. Please try again in a few seconds.");
        return;
    }

    let periodText = period.charAt(0).toUpperCase() + period.slice(1);
    if (period === 'custom') periodText = `${start} to ${end}`;

    const wb = XLSX.utils.book_new();

    // 1. Executive Summary
    const execData = [
        ["Metric", "Value"],
        ["Total Revenue", data.executive.total_revenue || 0],
        ["Total Expenses", data.executive.total_expenses || 0],
        ["Net Profit", data.executive.net_profit || 0],
        ["Gents Doctor Revenue", data.executive.gents_doctor_revenue || 0],
        ["Ladies Doctor Revenue", data.executive.ladies_doctor_revenue || 0],
        ["Pending Collections", data.executive.pending_amount || 0],
        ["Cleared Pending", data.executive.cleared_pending || 0],
        ["Total Patients", data.executive.total_patients || 0],
        ["Total Returns", data.executive.total_returns || 0],
    ];
    if (data.financial) {
        Object.keys(data.financial).forEach(key => {
            execData.push([key + " Collected", data.financial[key] || 0]);
        });
    }
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(execData), "Executive Summary");

    // 2. Patient History
    const patientData = [["Patient Name", "Phone", "Doctor", "Visit Date", "Consultation Fee", "Medicines Purchased", "Medicine Bill", "Injection Cost", "IV Cost", "Total Amount", "Paid", "Pending", "Payment Mode"]];
    if (data.patients) {
        data.patients.forEach(p => {
            patientData.push([
                p.name, p.phone, p.doctor_name, (p.created_at || '').split(' ')[0],
                p.consultation_fee || 0, p.parsed_medicines || '', p.cost_amount || 0,
                p.injection_cost || 0, p.iv_cost || 0, p.total_amount || 0,
                p.paid_amount || 0, p.balance_amount || 0,
                (p.cash_amount > 0 ? 'Cash ' : '') + (p.gpay_amount > 0 ? (p.upi_account ? p.upi_account + ' ' : 'UPI ') : '') + (p.bank_amount > 0 ? 'Bank ' : '')
            ]);
        });
    }
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(patientData), "Patients");

    // 3. Direct Sales
    const salesData = [["Customer", "Phone", "Date", "Medicines", "Total Amount", "Paid Amount", "Pending Amount"]];
    if (data.direct_sales) {
        data.direct_sales.forEach(s => {
            salesData.push([s.customer_name, s.mobile_number, (s.created_at || '').split(' ')[0], s.parsed_medicines || '', s.total_amount || 0, s.paid_amount || 0, s.balance_amount || 0]);
        });
    }
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(salesData), "Direct Sales");

    // 4. Returns
    const retData = [["Patient / Customer", "Return Date", "Medicine", "Qty", "Refund Amount"]];
    if (data.returns) data.returns.forEach(r => retData.push([r.patient_name || 'Direct Sale', r.return_date || '', r.medicine_name || '', r.returned_qty || 0, r.return_amount || 0]));
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(retData), "Returns");

    // 5. Pending
    const pendingData = [["Patient / Customer", "Phone", "Date", "Pending Amount"]];
    if (data.patients) data.patients.filter(p => p.balance_amount > 0).forEach(p => pendingData.push([p.name, p.phone, p.created_at || '', p.balance_amount || 0]));
    if (data.direct_sales) data.direct_sales.filter(p => p.balance_amount > 0).forEach(p => pendingData.push([p.customer_name, p.mobile_number, p.created_at || '', p.balance_amount || 0]));
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(pendingData), "Pending");

    // 6. Inventory
    const invData = [["Medicine Name", "Current Stock", "MRP"]];
    if (data.inventory) data.inventory.forEach(i => invData.push([i.name, i.stock, i.mrp || 0]));
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(invData), "Inventory");

    // 7. Agency Purchases
    const agencyData = [["Supplier", "Invoice", "Purchase Date", "Grand Total", "Payment Mode"]];
    if (data.agency_purchases) data.agency_purchases.forEach(a => agencyData.push([a.supplier_name, a.invoice_number, a.purchase_date, a.grand_total || 0, a.payment_mode]));
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(agencyData), "Supplier Purchases");

    XLSX.writeFile(wb, `Hospital_Complete_Report_${new Date().getTime()}.xlsx`);
}

function generatePrintableReport(data, period, start, end, format = 'print') {
    const dateStr = new Date().toLocaleString();
    let periodText = '';
    if (period === 'today') periodText = 'Today';
    else if (period === 'yesterday') periodText = 'Yesterday';
    else if (period === 'weekly') periodText = 'Last 7 Days';
    else if (period === 'thirty_days') periodText = 'Last 30 Days';
    else if (period === 'monthly') periodText = 'This Month';
    else if (period === 'last_month') periodText = 'Last Month';
    else if (period === 'yearly') periodText = 'This Year';
    else if (period === 'custom') periodText = `Custom (${start} to ${end})`;
    else periodText = period.charAt(0).toUpperCase() + period.slice(1);

    let titleText = 'Comprehensive Master Report';
    if (format === 'pdf' && period === 'custom' && start === '2000-01-01') {
        titleText = 'System Full Backup';
    }

    const groupByDate = (arr, dateField) => {
        if (!arr || !arr.length) return [];
        const grouped = {};
        arr.forEach(item => {
            const d = (item[dateField] || '').split(' ')[0] || 'Unknown Date';
            if (!grouped[d]) grouped[d] = [];
            grouped[d].push(item);
        });
        return Object.keys(grouped).sort((a, b) => b.localeCompare(a)).map(date => ({ date, items: grouped[date] }));
    };

    const groupedPatients = groupByDate(data.patients, 'created_at');
    const groupedDirectSales = groupByDate(data.direct_sales, 'created_at');
    const groupedPurchases = groupByDate(data.agency_purchases, 'purchase_date');
    const groupedReturns = groupByDate(data.returns, 'return_date');

    let html = `
    <!DOCTYPE html>
    <html>
    <head>
        <title>Hospital System Report</title>
        <style>
            @media print {
                @page { margin: 15mm; size: auto; }
                body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .no-break { page-break-inside: avoid; }
            }
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.5; color: #222; margin: 0; padding: 20px; background: #fff; }
            .header { text-align: center; border-bottom: 2px solid #222; padding-bottom: 10px; margin-bottom: 20px; }
            .header h1 { margin: 0; font-size: 24px; text-transform: uppercase; letter-spacing: 1px; }
            .header h2 { margin: 5px 0; font-size: 18px; color: #444; }
            .meta-info { display: flex; justify-content: space-between; font-size: 12px; color: #555; margin-bottom: 20px; }
            .section { margin-bottom: 30px; }
            .section-title { font-size: 16px; background: #f0f0f0; padding: 8px 12px; border-left: 4px solid #2563eb; margin-bottom: 15px; font-weight: 600; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 12px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f8fafc; font-weight: 600; color: #333; }
            .grid-stats { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
            .stat-box { flex: 1; min-width: 22%; border: 1px solid #e2e8f0; padding: 15px; border-radius: 4px; text-align: center; box-sizing: border-box; }
            .stat-box .title { font-size: 11px; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
            .stat-box .val { font-size: 18px; font-weight: bold; color: #0f172a; }
            .footer { margin-top: 50px; padding-top: 10px; border-top: 1px solid #ddd; text-align: center; font-size: 11px; color: #777; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
        </style>
    </head>
    <body>
        <div class="print-wrapper" style="background:#fff; padding:30px;">
            <style>
                .print-wrapper { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.5; color: #222; background: #fff; }
                .print-wrapper .header { text-align: center; border-bottom: 2px solid #222; padding-bottom: 10px; margin-bottom: 20px; }
                .print-wrapper .header h1 { margin: 0; font-size: 26px; text-transform: uppercase; letter-spacing: 1.5px; }
                .print-wrapper .header h2 { margin: 5px 0; font-size: 18px; color: #444; }
                .print-wrapper .meta-info { display: flex; justify-content: space-between; font-size: 12px; color: #555; margin-bottom: 20px; }
                .print-wrapper .section { display: block !important; margin-bottom: 30px; }
                .print-wrapper .section-title { font-size: 16px; background: #f0f0f0; padding: 8px 12px; border-left: 4px solid #2563eb; margin-bottom: 15px; font-weight: 600; }
                .print-wrapper table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 12px; }
                .print-wrapper th, .print-wrapper td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .print-wrapper th { background-color: #f8fafc; font-weight: 600; color: #333; }
                .print-wrapper .grid-stats { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
                .print-wrapper .stat-box { flex: 1; min-width: 22%; border: 1px solid #e2e8f0; padding: 15px; border-radius: 4px; text-align: center; box-sizing: border-box; }
                .print-wrapper .stat-box .title { font-size: 11px; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
                .print-wrapper .stat-box .val { font-size: 18px; font-weight: bold; color: #0f172a; }
                .print-wrapper .footer { margin-top: 50px; padding-top: 10px; border-top: 1px solid #ddd; text-align: center; font-size: 11px; color: #777; }
                .print-wrapper .text-right { text-align: right; }
                .print-wrapper .text-center { text-align: center; }
                @media print {
                    @page { margin: 15mm; size: auto; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .no-break { page-break-inside: avoid; }
                }
            </style>
            <div class="header">
                <h1 style="margin: 0; font-size: 26px; text-transform: uppercase; letter-spacing: 1.5px;">Crescent Clinic & Scans</h1>
                <h2 style="margin: 5px 0; font-size: 18px; color: #444;">${titleText}</h2>
            </div>
        
        <div class="meta-info">
            <div><strong>Date Filter:</strong> ${periodText}</div>
            <div><strong>Generated At:</strong> ${dateStr}</div>
            <div><strong>Generated By:</strong> Administrator</div>
        </div>

        <div class="section no-break">
            <div class="section-title">1. EXECUTIVE SUMMARY</div>
            <div class="grid-stats">
                <div class="stat-box">
                    <div class="title">Total Revenue</div>
                    <div class="val">₹${(data.executive.total_revenue || 0).toFixed(2)}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Total Expenses</div>
                    <div class="val">₹${(data.executive.total_expenses || 0).toFixed(2)}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Net Profit</div>
                    <div class="val">₹${(data.executive.net_profit || 0).toFixed(2)}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Gents Doctor Fee</div>
                    <div class="val">₹${(data.executive.gents_doctor_revenue || 0).toFixed(2)}</div>
                </div>
            </div>
            <div class="grid-stats">
                <div class="stat-box">
                    <div class="title">Ladies Doctor Fee</div>
                    <div class="val">₹${(data.executive.ladies_doctor_revenue || 0).toFixed(2)}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Pending Collections</div>
                    <div class="val">₹${(data.executive.pending_amount || 0).toFixed(2)}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Cleared Pending</div>
                    <div class="val">₹${(data.executive.cleared_pending || 0).toFixed(2)}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Total Patients</div>
                    <div class="val">${data.executive.total_patients || 0}</div>
                </div>
            </div>
            <div class="grid-stats">
                <div class="stat-box">
                    <div class="title">Direct Med Sales</div>
                    <div class="val">${data.executive.direct_sales_count || 0}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Total Returns</div>
                    <div class="val">₹${(data.executive.total_returns || 0).toFixed(2)}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Consultations</div>
                    <div class="val">${data.executive.total_consultations || 0}</div>
                </div>
                <div class="stat-box">
                    <div class="title">Total Suppliers</div>
                    <div class="val">${data.executive.total_suppliers || 0}</div>
                </div>
            </div>
        </div>

        <!-- DAILY BREAKDOWN -->
        ${data.daily_stats && data.daily_stats.length > 0 ? `
        <div class="section no-break">
            <div class="section-title">DAILY BREAKDOWN</div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-right">Revenue</th>
                        <th class="text-right">Expense</th>
                        <th class="text-right">Net Profit</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.daily_stats.map(d => `
                        <tr>
                            <td>${d.date}</td>
                            <td class="text-right">₹${(d.revenue || 0).toFixed(2)}</td>
                            <td class="text-right">₹${(d.expense || 0).toFixed(2)}</td>
                            <td class="text-right" style="font-weight:bold; color:${d.profit >= 0 ? '#10b981' : '#ef4444'};">₹${(d.profit || 0).toFixed(2)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>` : ''}

        <!-- 2. FINANCIAL & PAYMENT MODE ANALYSIS -->
        <div class="section no-break">
            <div class="section-title">2. FINANCIAL & PAYMENT MODE ANALYSIS</div>
            <table>
                <tr>
                    ${Object.keys(data.financial || {}).map(k => `<th>${k}</th>`).join('')}
                    <th>Total Collected</th>
                </tr>
                <tr>
                    ${Object.values(data.financial || {}).map(v => `<td>₹${parseFloat(v || 0).toFixed(2)}</td>`).join('')}
                    <td><strong>₹${(data.executive.collection_amount || 0).toFixed(2)}</strong></td>
                </tr>
            </table>
        </div>

        <!-- 3. DOCTOR REVENUE SUMMARY -->
        <div class="section no-break">
            <div class="section-title">3. DOCTOR REVENUE SUMMARY</div>
            <table>
                <thead>
                    <tr>
                        <th>Doctor Name</th>
                        <th>Type</th>
                        <th class="text-center">Consultations</th>
                        <th class="text-right">Consultation Rev</th>
                        <th class="text-right">Medicine Rev</th>
                        <th class="text-right">Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.doctors && data.doctors.length > 0 ? data.doctors.map(d => `
                        <tr>
                            <td>${d.doctor_name}</td>
                            <td>${d.doctor_type}</td>
                            <td class="text-center">${d.p_count}</td>
                            <td class="text-right">₹${(d.doc_rev || 0).toFixed(2)}</td>
                            <td class="text-right">₹${(d.med_rev || 0).toFixed(2)}</td>
                            <td class="text-right"><strong>₹${((d.doc_rev || 0) + (d.med_rev || 0)).toFixed(2)}</strong></td>
                        </tr>
                    `).join('') : '<tr><td colspan="6" class="text-center">No data found</td></tr>'}
                </tbody>
            </table>
        </div>

        <!-- 4. PATIENT VISIT HISTORY -->
        <div class="section">
            <div class="section-title">4. PATIENT VISIT HISTORY</div>
            ${groupedPatients.length > 0 ? groupedPatients.map(g => `
                <div style="background:#f8fafc; padding:5px 10px; font-weight:bold; border:1px solid #cbd5e1; border-bottom:none; margin-top:10px;">Date: ${g.date}</div>
                <table style="margin-top:0;">
                    <thead>
                        <tr>
                            <th>Patient Name</th>
                            <th>Phone</th>
                            <th>Doctor</th>
                            <th class="text-right">Fee</th>
                            <th>Medicines Purchased</th>
                            <th class="text-right">Total Amount</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Balance</th>
                            <th class="text-center">Pay Mode</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${g.items.map(p => `
                            <tr class="no-break">
                                <td>${p.name}</td>
                                <td>${p.phone}</td>
                                <td>${p.doctor_name}</td>
                                <td class="text-right">₹${(p.consultation_fee || 0).toFixed(2)}</td>
                                <td><div style="max-width:150px;white-space:normal;overflow-wrap:break-word">${p.parsed_medicines || ''}</div></td>
                                <td class="text-right"><strong>₹${(p.total_amount || 0).toFixed(2)}</strong></td>
                                <td class="text-right">₹${(p.paid_amount || 0).toFixed(2)}</td>
                                <td class="text-right" style="color:${p.balance_amount > 0 ? 'red' : 'inherit'}">₹${(p.balance_amount || 0).toFixed(2)}</td>
                                <td class="text-center">${(p.cash_amount > 0 ? 'Cash ' : '')}${(p.gpay_amount > 0 ? (p.upi_account ? 'UPI (' + p.upi_account + ') ' : 'UPI ') : '')}${(p.bank_amount > 0 ? 'Bank' : '')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `).join('') : '<p class="text-center" style="padding:10px; border:1px solid #e2e8f0;">No patients found</p>'}
        </div>

        <!-- 5. PHARMACY - TOP SELLING MEDICINES -->
        <div class="section no-break">
            <div class="section-title">5. PHARMACY - TOP SELLING MEDICINES</div>
            <table>
                <thead>
                    <tr>
                        <th>Medicine Name</th>
                        <th class="text-center">Qty Sold</th>
                        <th class="text-right">Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.top_medicines && data.top_medicines.length > 0 ? data.top_medicines.map(m => `
                        <tr>
                            <td>${m.name}</td>
                            <td class="text-center">${m.qty}</td>
                            <td class="text-right">₹${(m.revenue || 0).toFixed(2)}</td>
                        </tr>
                    `).join('') : '<tr><td colspan="3" class="text-center">No medicine sales found</td></tr>'}
                </tbody>
            </table>
        </div>

        <!-- 6. DIRECT MEDICINE SALES -->
        <div class="section">
            <div class="section-title">6. DIRECT MEDICINE SALES</div>
            ${groupedDirectSales.length > 0 ? groupedDirectSales.map(g => `
                <div style="background:#f8fafc; padding:5px 10px; font-weight:bold; border:1px solid #cbd5e1; border-bottom:none; margin-top:10px;">Date: ${g.date}</div>
                <table style="margin-top:0;">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Medicines</th>
                            <th class="text-right">Total Amount</th>
                            <th class="text-right">Paid Amount</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${g.items.map(s => `
                            <tr class="no-break">
                                <td>${s.customer_name}</td>
                                <td>${s.mobile_number}</td>
                                <td><div style="max-width:150px;white-space:normal;overflow-wrap:break-word">${s.parsed_medicines || ''}</div></td>
                                <td class="text-right">₹${(s.total_amount || 0).toFixed(2)}</td>
                                <td class="text-right">₹${(s.paid_amount || 0).toFixed(2)}</td>
                                <td class="text-center" style="color:${s.balance_amount > 0 ? 'red' : 'green'}">${s.balance_amount > 0 ? 'Pending' : 'Paid'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `).join('') : '<p class="text-center" style="padding:10px; border:1px solid #e2e8f0;">No direct sales found</p>'}
        </div>

        <!-- 7. SUPPLIER / AGENCY PURCHASES -->
        <div class="section">
            <div class="section-title">7. SUPPLIER / AGENCY PURCHASES</div>
            ${groupedPurchases.length > 0 ? groupedPurchases.map(g => `
                <div style="background:#f8fafc; padding:5px 10px; font-weight:bold; border:1px solid #cbd5e1; border-bottom:none; margin-top:10px;">Date: ${g.date}</div>
                <table style="margin-top:0;">
                    <thead>
                        <tr>
                            <th>Invoice Number</th>
                            <th>Supplier Name</th>
                            <th>Payment Mode</th>
                            <th class="text-right">Grand Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${g.items.map(a => `
                            <tr class="no-break">
                                <td>${a.invoice_number}</td>
                                <td>${a.supplier_name}</td>
                                <td>${a.payment_mode}</td>
                                <td class="text-right"><strong>₹${(a.grand_total || 0).toFixed(2)}</strong></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `).join('') : '<p class="text-center" style="padding:10px; border:1px solid #e2e8f0;">No purchases found</p>'}
        </div>

        <!-- 8. CURRENT INVENTORY STATUS -->
        <div class="section">
            <div class="section-title">8. CURRENT INVENTORY STATUS</div>
            <table>
                <thead>
                    <tr>
                        <th>Medicine Name</th>
                        <th class="text-center">Current Stock</th>
                        <th class="text-right">MRP</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.inventory && data.inventory.length > 0 ? data.inventory.map(i => `
                        <tr class="no-break">
                            <td>${i.name}</td>
                            <td class="text-center">${i.stock}</td>
                            <td class="text-right">₹${(i.mrp || 0).toFixed(2)}</td>
                        </tr>
                    `).join('') : '<tr><td colspan="3" class="text-center">No inventory found</td></tr>'}
                </tbody>
            </table>
        </div>

        <div class="section no-break">
            <div class="section-title">9. MEDICINE RETURNS</div>
            ${groupedReturns.length > 0 ? groupedReturns.map(g => `
                <div style="background:#f8fafc; padding:5px 10px; font-weight:bold; border:1px solid #cbd5e1; border-bottom:none; margin-top:10px;">Date: ${g.date}</div>
                <table style="margin-top:0;">
                    <thead>
                        <tr><th>Patient / Type</th><th>Medicine</th><th>Qty</th><th class="text-right">Refund</th></tr>
                    </thead>
                    <tbody>
                        ${g.items.map(r => `
                            <tr class="no-break">
                                <td>${r.patient_name || 'Direct Sale Return'}</td>
                                <td>${r.medicine_name}</td>
                                <td>${r.returned_qty}</td>
                                <td class="text-right" style="color:red">-₹${(r.return_amount || 0).toFixed(2)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `).join('') : '<p class="text-center" style="padding:10px; border:1px solid #e2e8f0;">No returns recorded</p>'}
        </div>
        
        ${(data.patients && data.patients.filter(p => p.balance_amount > 0).length > 0) || (data.direct_sales && data.direct_sales.filter(s => s.balance_amount > 0).length > 0) ? `
        <div class="section">
            <div class="section-title">Pending Payments</div>
            <table>
                <thead>
                    <tr><th>Name</th><th>Phone</th><th>Date</th><th class="text-right">Balance Due</th></tr>
                </thead>
                <tbody>
                    ${(data.patients || []).filter(p => p.balance_amount > 0).map(p => `<tr><td>${p.name}</td><td>${p.phone}</td><td>${(p.created_at || '').split(' ')[0]}</td><td class="text-right" style="color:red; font-weight:bold;">₹${(p.balance_amount || 0).toFixed(2)}</td></tr>`).join('')}
                    ${(data.direct_sales || []).filter(s => s.balance_amount > 0).map(s => `<tr><td>${s.customer_name} (Direct)</td><td>${s.mobile_number}</td><td>${(s.created_at || '').split(' ')[0]}</td><td class="text-right" style="color:red; font-weight:bold;">₹${(s.balance_amount || 0).toFixed(2)}</td></tr>`).join('')}
                </tbody>
            </table>
        </div>` : ''}

        <div class="footer">Generated by Crescent Clinic Management System &copy; ${new Date().getFullYear()}</div>
        </div>
    </body>
    </html>`;

    if (format === 'print') {
        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        document.body.appendChild(iframe);
        iframe.contentWindow.document.open();
        iframe.contentWindow.document.write(html + '<script>window.onload = function() { setTimeout(function() { window.print(); }, 500); };</script></body></html>');
        iframe.contentWindow.document.close();
        setTimeout(() => { document.body.removeChild(iframe); }, 10000);

        Swal.fire({
            icon: 'success',
            title: 'Print Ready',
            text: 'Opening print dialog...',
            timer: 1500,
            showConfirmButton: false
        });

        const btn = document.querySelector(`[onclick="exportReport('print')"]`);
        if (btn) { btn.innerHTML = 'Print & PDF'; btn.disabled = false; }
    } else {
        html += `</body></html>`;
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        // Make it renderable but hidden offscreen
        tempDiv.style.position = 'absolute';
        tempDiv.style.top = '-9999px';
        tempDiv.style.left = '0';
        tempDiv.style.width = '1200px';
        tempDiv.style.backgroundColor = '#fff';
        document.body.appendChild(tempDiv);

        // Let fonts/styles load
        setTimeout(async () => {
            const wrapper = tempDiv.querySelector('.print-wrapper');
            if (format === 'whatsapp') {
                if (typeof html2pdf === 'undefined') {
                    alert("html2pdf is loading, please try again.");
                    tempDiv.remove();
                    return;
                }
                Swal.fire({ title: 'Generating PDF...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const opt = {
                    margin: 0.2,
                    filename: `Hospital_Report_${new Date().getTime()}.pdf`,
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2 },
                    jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
                };
                html2pdf().set(opt).from(wrapper).outputPdf('blob').then(blob => {
                    Swal.close();
                    const file = new File([blob], opt.filename, { type: 'application/pdf' });
                    if (navigator.canShare && navigator.canShare({ files: [file] })) {
                        navigator.share({
                            title: 'Hospital Report',
                            files: [file]
                        }).catch(err => {
                            console.log("Share failed:", err);
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = opt.filename;
                            a.click();
                            toast('Direct WhatsApp PDF sharing not supported on this browser. The PDF has been downloaded.', 'info', 6000);
                        });
                    } else {
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = opt.filename;
                        a.click();
                        toast('Direct WhatsApp PDF sharing not supported on this browser. The PDF has been downloaded.', 'info', 6000);
                    }
                    tempDiv.remove();
                }).catch(err => {
                    console.error("WhatsApp PDF generation failed:", err);
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'PDF Generation Failed',
                        text: 'Failed to generate PDF for WhatsApp backup. Please try again.'
                    });
                    tempDiv.remove();
                });
            } else if (format === 'pdf') {
                if (typeof html2pdf === 'undefined') {
                    alert("html2pdf is loading, please try again.");
                    tempDiv.remove();
                    return;
                }
                Swal.fire({ title: 'Generating PDF...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const isBackup = period === 'custom' && start === '2000-01-01';
                const filename = isBackup ? `Hospital_Backup_${new Date().getTime()}.pdf` : `Hospital_Report_${new Date().getTime()}.pdf`;
                const opt = {
                    margin: 0.2,
                    filename: filename,
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2 },
                    jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
                };
                html2pdf().set(opt).from(wrapper).save().then(() => {
                    Swal.close();
                    Swal.fire({
                        icon: 'success',
                        title: 'PDF Exported',
                        text: 'Your PDF Report has been generated and downloaded successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    tempDiv.remove();
                }).catch(err => {
                    console.error("PDF generation failed:", err);
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'PDF Generation Failed',
                        text: 'Failed to generate and download PDF. Please try again or use the print option.'
                    });
                    tempDiv.remove();
                });
            }
        }, 800);
    }
}

function generateBackup() {
    const today = new Date().toISOString().split('T')[0];
    exportReportCustom('custom', '2000-01-01', today, 'pdf');
}

async function exportReportCustom(period, start, end, format) {
    try {
        Swal.fire({ title: 'Preparing Full Backup...', text: 'Fetching all records, please wait.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const url = `/reports_api.php?action=get_print_report&period=${period}&start=${start}&end=${end}&_=${new Date().getTime()}`;
        const data = await fetch(url).then(r => r.json());
        Swal.close();

        if (!data || !data.executive) {
            throw new Error("Invalid or empty report payload received from server.");
        }

        generatePrintableReport(data, period, start, end, format);
    } catch (e) {
        console.error("Error generating backup", e);
        Swal.fire('Error', 'Failed to generate full backup.', 'error');
    }
}

function toggleReportCustomDate() {
    const period = document.getElementById('reportPeriod').value;
    const customGroup = document.getElementById('reportCustomDateGroup');
    if (period === 'custom') {
        customGroup.style.display = 'flex';
    } else {
        customGroup.style.display = 'none';
    }
}

function searchReport() {
    const query = document.getElementById('reportSearchInput').value.toLowerCase();

    // Filter Patients Table
    const patRows = document.querySelectorAll('#repPatientTable tr');
    patRows.forEach(row => {
        if (row.innerText.toLowerCase().includes(query)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    // Add filter logic for other tables as well
}

// Automatically bind to live sync
if (typeof reloadCurrentSection === 'function') {
    const originalReload = window.reloadCurrentSection;
    window.reloadCurrentSection = function () {
        originalReload();
        loadReportDashboard();
    };
} else {
    window.reloadCurrentSection = function () {
        loadReportDashboard();
    };
}

async function sendWhatsAppBackup() {
    exportReport('whatsapp');
}

// ═══════════════════════════════════════════
// DETAILED REPORT MODAL LOGIC
// ═══════════════════════════════════════════
function showReportDetails(type) {
    if (!reportData || !reportData.executive) return;

    let title = 'Report Details';
    let html = '';

    if (type === 'revenue') {
        title = 'Total Revenue Breakdown';
        html = `
            <table class="data-table">
                <tr><th>Category</th><th style="text-align:right">Amount</th></tr>
                <tr><td>Doctor Consultation</td><td style="text-align:right">₹${(reportData.executive.doctor_revenue || 0).toFixed(2)}</td></tr>
                <tr><td>Pharma Revenue</td><td style="text-align:right">₹${(reportData.executive.pharmacy_sales_revenue || 0).toFixed(2)}</td></tr>
                <tr><td>Direct Medical Sale Revenue</td><td style="text-align:right">₹${(reportData.executive.direct_sales_revenue || 0).toFixed(2)}</td></tr>
                <tr><td>Injection Revenue</td><td style="text-align:right">₹${(reportData.executive.injection_sales_revenue || 0).toFixed(2)}</td></tr>
                <tr><td>IV Revenue</td><td style="text-align:right">₹${(reportData.executive.iv_sales_revenue || 0).toFixed(2)}</td></tr>
                <tr><td>Scan Revenue</td><td style="text-align:right">₹${(reportData.executive.scan_revenue || 0).toFixed(2)}</td></tr>
                <tr><td>UPT Card Fee</td><td style="text-align:right">₹${(reportData.executive.upt_sales_revenue || 0).toFixed(2)}</td></tr>
                ${(reportData.executive.total_discount > 0) ? `<tr><td>Discount Allowed (-)</td><td style="text-align:right; color:var(--danger);">-₹${reportData.executive.total_discount.toFixed(2)}</td></tr>` : ''}
                <tr style="background:#f0f9ff; font-weight:bold;"><td>Total Revenue</td><td style="text-align:right">₹${reportData.executive.total_revenue.toFixed(2)}</td></tr>
            </table>
            
             <h4 style="margin-top:20px;">Payment Modes</h4>
            <table class="data-table">
                <tr><th>Mode</th><th style="text-align:right">Collected</th></tr>
                ${Object.keys(reportData.financial || {}).map(k => `<tr><td>${k}</td><td style="text-align:right">₹${parseFloat(reportData.financial[k] || 0).toFixed(2)}</td></tr>`).join('')}
            </table>
        `;
    } else if (type === 'expenses') {
        title = 'Total Expenses Breakdown';
        html = `
            <table class="data-table">
                <tr><th>Category</th><th style="text-align:right">Amount</th></tr>
                <tr><td>Agency / Supplier Purchases</td><td style="text-align:right">₹${(reportData.executive.total_expenses || 0).toFixed(2)}</td></tr>
                <tr><td>Returns / Refunds</td><td style="text-align:right">₹${(reportData.executive.total_returns || 0).toFixed(2)}</td></tr>
                <tr style="background:#fef2f2; font-weight:bold;"><td>Total Outflow</td><td style="text-align:right">₹${((reportData.executive.total_expenses || 0) + (reportData.executive.total_returns || 0)).toFixed(2)}</td></tr>
            </table>
            <p style="margin-top:10px; color:var(--text-muted); font-size:0.85em;">* Detailed expense splitting requires Other Expenses module.</p>
        `;
    } else if (type === 'profit') {
        title = 'Net Profit Calculation';
        html = `
            <div style="padding:15px; background:#f8fafc; border-radius:8px; margin-bottom:15px;">
                <p><strong>Net Profit</strong> is calculated as the total revenue minus medicine cost and direct sale cost (matching the Analytics formula).</p>
            </div>
            <table class="data-table">
                <tr><th>Component</th><th style="text-align:right">Value</th></tr>
                <tr style="color:var(--emerald);"><td>Total Revenue (+)</td><td style="text-align:right">₹${reportData.executive.total_revenue.toFixed(2)}</td></tr>
                <tr style="color:var(--danger);"><td>Medicine Cost (-)</td><td style="text-align:right">₹${(reportData.executive.medicine_cost || 0).toFixed(2)}</td></tr>
                <tr style="color:var(--danger);"><td>Direct Sale Cost (-)</td><td style="text-align:right">₹${(reportData.executive.direct_sales_cost || 0).toFixed(2)}</td></tr>
                <tr style="background:#ecfdf5; font-weight:bold; font-size:1.1em; color:var(--emerald);">
                    <td>Final Net Profit (=)</td>
                    <td style="text-align:right">₹${reportData.executive.net_profit.toFixed(2)}</td>
                </tr>
            </table>
        `;
    } else if (type === 'patients') {
        title = 'Patient Visit Summary';
        const seen = new Set();
        const uniquePatients = (reportData.patients || []).filter(p => {
            const pid = p.patient_id || p.id;
            if (!pid) return true;
            if (seen.has(pid)) return false;
            seen.add(pid);
            return true;
        });
        html = `
            <div style="max-height: 400px; overflow-y: auto;">
                <table class="data-table">
                    <thead>
                        <tr><th>Patient ID</th><th>Patient Name</th></tr>
                    </thead>
                    <tbody>
                        ${uniquePatients.length > 0 ? uniquePatients.map(p => `
                            <tr>
                                <td>${p.patient_id || p.id || '-'}</td>
                                <td>${p.name || '-'}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="2" style="text-align:center;">No patients found</td></tr>'}
                    </tbody>
                </table>
            </div>
        `;
    } else if (type === 'consultations') {
        title = 'Consultations Summary';
        const consultationPatients = (reportData.patients || []).filter(p => p.consultation_fee !== null && p.consultation_fee !== undefined);
        html = `
            <div style="max-height: 400px; overflow-y: auto;">
                <table class="data-table">
                    <thead>
                        <tr><th>Patient ID</th><th>Patient Name</th><th>Doctor</th></tr>
                    </thead>
                    <tbody>
                        ${consultationPatients.length > 0 ? consultationPatients.map(p => `
                            <tr>
                                <td>${p.patient_id || p.id || '-'}</td>
                                <td>${p.name || '-'}</td>
                                <td>${p.doctor_name || '-'}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="3" style="text-align:center;">No consultations found</td></tr>'}
                    </tbody>
                </table>
            </div>
        `;
    } else if (type === 'direct_customers') {
        title = 'Direct Medical Customers';
        const customers = (reportData.direct_sales || []).map(ds => ({
            id: 'DS-' + (ds.id || ''),
            name: ds.customer_name || '-',
            phone: ds.mobile_number || '-'
        }));
        html = `
            <div style="max-height: 400px; overflow-y: auto;">
                <table class="data-table">
                    <thead>
                        <tr><th>Customer ID</th><th>Customer Name</th><th>Phone Number</th></tr>
                    </thead>
                    <tbody>
                        ${customers.length > 0 ? customers.map(c => `
                            <tr>
                                <td>${c.id}</td>
                                <td>${c.name}</td>
                                <td>${c.phone}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="3" style="text-align:center;">No direct customers found</td></tr>'}
                    </tbody>
                </table>
            </div>
        `;
    } else if (type === 'pending') {
        title = 'Pending Fees Breakdown';
        const pendingPrescriptions = (reportData.patients || []).filter(p => parseFloat(p.balance_amount || 0) > 0).map(p => ({
            id: p.patient_id || p.id || '-',
            name: p.name || '-',
            amount: parseFloat(p.balance_amount),
            type: 'Prescription'
        }));
        const pendingDirectSales = (reportData.direct_sales || []).filter(ds => parseFloat(ds.balance_amount || 0) > 0).map(ds => ({
            id: 'DS-' + (ds.id || ''),
            name: ds.customer_name || '-',
            amount: parseFloat(ds.balance_amount),
            type: 'Direct Sale'
        }));
        const allPending = [...pendingPrescriptions, ...pendingDirectSales];

        html = `
            <div style="max-height: 400px; overflow-y: auto;">
                <table class="data-table">
                    <thead>
                        <tr><th>Patient ID / Ref</th><th>Patient Name</th><th>Source</th><th style="text-align:right">Pending Amount</th></tr>
                    </thead>
                    <tbody>
                        ${allPending.length > 0 ? allPending.map(p => `
                            <tr>
                                <td>${p.id}</td>
                                <td>${p.name}</td>
                                <td>${p.type}</td>
                                <td style="text-align:right; color:var(--danger); font-weight:bold;">₹${p.amount.toFixed(2)}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="4" style="text-align:center;">No pending fees</td></tr>'}
                    </tbody>
                </table>
            </div>
        `;
    } else if (type === 'returns') {
        title = 'Returns Breakdown';
        html = `
            <table class="data-table">
                <tr><th>Date</th><th>Patient</th><th>Item</th><th>Returned Qty</th><th>Refunded Amount</th></tr>
                ${(reportData.returns && reportData.returns.length > 0) ? reportData.returns.map(r => `<tr><td>${r.return_date || ''}</td><td>${r.patient_name || '-'}</td><td>${r.medicine_name}</td><td>${r.returned_qty}</td><td style="color:var(--danger)">-₹${(r.return_amount || 0).toFixed(2)}</td></tr>`).join('') : '<tr><td colspan="5" style="text-align:center;">No returns recorded</td></tr>'}
            </table>
        `;
    } else if (type === 'gents' || type === 'ladies') {
        title = `${type === 'gents' ? 'Gents (Sir)' : 'Ladies (Madam)'} Doctor Fee Breakdown`;
        const matchType = type === 'gents' ? 'gent' : 'lady';
        const filteredDocs = (reportData.doctors || []).filter(d => (d.doctor_type || '').toLowerCase().indexOf(matchType) !== -1);

        html = `
            <div style="margin-bottom:15px;">
                Total Consultation Fee Revenue: <strong style="font-size:1.1rem; color:var(--primary);">₹${(type === 'gents' ? reportData.executive.gents_doctor_revenue : reportData.executive.ladies_doctor_revenue).toFixed(2)}</strong>
            </div>
            <table class="data-table">
                <thead>
                    <tr><th>Doctor Name</th><th>Consultations</th><th style="text-align:right">Consultation Fees</th></tr>
                </thead>
                <tbody>
                    ${filteredDocs.length > 0 ? filteredDocs.map(d => `
                        <tr>
                            <td>${d.doctor_name}</td>
                            <td>${d.p_count}</td>
                            <td style="text-align:right">₹${(d.doc_rev || 0).toFixed(2)}</td>
                        </tr>
                    `).join('') : '<tr><td colspan="3" style="text-align:center;">No doctors of this type found</td></tr>'}
                </tbody>
            </table>
        `;
    } else if (type === 'cleared') {
        title = 'Cleared Pending Payments';
        html = `<p>Total pending amounts that were successfully cleared by patients during this period: <strong>₹${(reportData.executive.cleared_pending || 0).toFixed(2)}</strong></p>`;
    }

    document.getElementById('reportDetailTitle').textContent = title;
    document.getElementById('reportDetailContent').innerHTML = html;
    openModal('reportDetailModal');
}

function showPatientReportDetailsByIndex(index) {
    const p = window.currentReportPatients[index];
    if (!p) return;

    const title = `Patient Visit Statement: ${p.name}`;
    const medicines = JSON.parse(p.medicines || '[]');

    const paidAmt = parseFloat(p.paid_amount) || 0;
    const totalMeds = parseFloat(p.total_amount) || 0;
    const consultationFee = parseFloat(p.consultation_fee) || 0;
    const injCost = parseFloat(p.injection_cost) || 0;
    const ivCost = parseFloat(p.iv_cost) || 0;
    const uptCost = parseFloat(p.upt_cost) || 0;
    const scanFee = parseFloat(p.scan_fee) || 0;
    const discountPercent = parseFloat(p.discount_percent) || 0;

    let subtotal = totalMeds + consultationFee + scanFee + injCost + ivCost + uptCost;
    let discountAmt = subtotal * (discountPercent / 100);
    let grandTotal = subtotal - discountAmt;

    let html = `
        <div style="background:var(--bg-hover); padding:15px; border-radius:8px; margin-bottom:20px; display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
            <div><strong>Patient Name:</strong> ${p.name}</div>
            <div><strong>Phone:</strong> ${p.phone || '-'}</div>
            <div><strong>Doctor:</strong> ${p.doctor_name}</div>
            <div><strong>Visit Date:</strong> ${p.created_at || '-'}</div>
        </div>

        <h4>Itemized Transaction Details</h4>
        <table class="data-table" style="margin-bottom:15px;">
            <thead>
                <tr>
                    <th>Item/Service</th>
                    <th style="text-align:right">Fee / Cost</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Consultation Fee</td><td style="text-align:right">₹${consultationFee.toFixed(2)}</td></tr>
                ${scanFee > 0 ? `<tr><td>Scan Fee (${p.scan_type || 'General'})<br><small style="color:var(--text-muted)">${p.scan_notes || ''}</small></td><td style="text-align:right">₹${scanFee.toFixed(2)}</td></tr>` : ''}
                ${injCost > 0 ? `<tr><td>Injection Fee (${p.injection_details || 'General'})</td><td style="text-align:right">₹${injCost.toFixed(2)}</td></tr>` : ''}
                ${ivCost > 0 ? `<tr><td>IV Fluid Fee (${p.iv_details || 'General'})</td><td style="text-align:right">₹${ivCost.toFixed(2)}</td></tr>` : ''}
                ${uptCost > 0 ? `<tr><td>UPT Card Fee</td><td style="text-align:right">₹${uptCost.toFixed(2)}</td></tr>` : ''}
                ${totalMeds > 0 ? `<tr><td>Pharmacy Medicines</td><td style="text-align:right">₹${totalMeds.toFixed(2)}</td></tr>` : ''}
            </tbody>
        </table>
    `;

    if (medicines.length > 0) {
        html += `
            <h4 style="margin-top:15px;">Prescribed Medicines List</h4>
            <table class="data-table" style="margin-bottom:15px; font-size:0.9em;">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Qty Prescribed</th>
                        <th>Returned Qty</th>
                        <th style="text-align:right">Returned Amt</th>
                    </tr>
                </thead>
                <tbody>
                    ${medicines.map(m => {
            const retQty = parseFloat(m.returned_qty) || 0;
            const retAmt = parseFloat(m.returned_amount) || 0;
            return `
                            <tr>
                                <td>${m.name}</td>
                                <td>${m.qty}</td>
                                <td>${retQty > 0 ? `<span style="color:var(--danger); font-weight:600;">${retQty}</span>` : '0'}</td>
                                <td style="text-align:right">${retAmt > 0 ? `<span style="color:var(--danger)">-₹${retAmt.toFixed(2)}</span>` : '₹0.00'}</td>
                            </tr>
                        `;
        }).join('')}
                </tbody>
            </table>
        `;
    }

    html += `
        <h4>Summary Calculation</h4>
        <table class="data-table">
            <tr><td>Subtotal</td><td style="text-align:right">₹${subtotal.toFixed(2)}</td></tr>
            ${discountPercent > 0 ? `<tr style="color:var(--danger);"><td>Discount (${discountPercent}%)</td><td style="text-align:right">-₹${discountAmt.toFixed(2)}</td></tr>` : ''}
            <tr style="font-weight:bold; font-size:1.05em; background:var(--bg-hover);"><td>Grand Total</td><td style="text-align:right">₹${grandTotal.toFixed(2)}</td></tr>
            <tr style="color:var(--emerald);"><td>Paid Amount</td><td style="text-align:right">₹${paidAmt.toFixed(2)}</td></tr>
            ${(parseFloat(p.balance_amount) || 0) > 0 ? `
                <tr style="color:var(--danger); font-weight:bold;">
                    <td>Pending Balance</td>
                    <td style="text-align:right">₹${parseFloat(p.balance_amount).toFixed(2)}</td>
                </tr>
            ` : '<tr style="color:var(--emerald); font-weight:bold;"><td>Status</td><td style="text-align:right">Fully Paid</td></tr>'}
        </table>
        
        <h4 style="margin-top:15px;">Payment Splitting</h4>
        <table class="data-table">
            <tr><td>Cash Paid</td><td style="text-align:right">₹${(parseFloat(p.cash_amount) || 0).toFixed(2)}</td></tr>
            <tr><td>GPay Paid</td><td style="text-align:right">₹${(parseFloat(p.gpay_amount) || 0).toFixed(2)}</td></tr>
            <tr><td>PhonePe Paid</td><td style="text-align:right">₹${(parseFloat(p.phonepe_amount) || 0).toFixed(2)}</td></tr>
        </table>
    `;

    document.getElementById('reportDetailTitle').textContent = title;
    document.getElementById('reportDetailContent').innerHTML = html;
    openModal('reportDetailModal');
}

function showDirectSaleReportDetailsByIndex(index) {
    const s = window.currentReportDirectSales[index];
    if (!s) return;

    const title = `Direct Sale Statement: ${s.customer_name}`;
    const medicines = typeof s.medicines === 'string' ? JSON.parse(s.medicines || '[]') : (s.medicines || []);

    const paidAmt = parseFloat(s.paid_amount) || 0;
    const totalMeds = parseFloat(s.total_amount) || 0;
    const injCost = parseFloat(s.injection_cost) || 0;
    const ivCost = parseFloat(s.iv_cost) || 0;
    const uptCost = parseFloat(s.upt_cost) || 0;
    const discountPercent = parseFloat(s.discount_percent || s.discount_percentage) || 0;

    let subtotal = totalMeds + injCost + ivCost + uptCost;
    let discountAmt = subtotal * (discountPercent / 100);
    let grandTotal = subtotal - discountAmt;

    let html = `
        <div style="background:var(--bg-hover); padding:15px; border-radius:8px; margin-bottom:20px; display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
            <div><strong>Customer Name:</strong> ${s.customer_name}</div>
            <div><strong>Phone:</strong> ${s.mobile_number || '-'}</div>
            <div><strong>Sale Date:</strong> ${s.created_at || '-'}</div>
            <div><strong>Transaction Reference:</strong> DS-${s.id}</div>
        </div>

        <h4>Itemized Transaction Details</h4>
        <table class="data-table" style="margin-bottom:15px;">
            <thead>
                <tr>
                    <th>Item/Service</th>
                    <th style="text-align:right">Fee / Cost</th>
                </tr>
            </thead>
            <tbody>
                ${injCost > 0 ? `<tr><td>Injection Fee (${s.injection_details || 'General'})</td><td style="text-align:right">₹${injCost.toFixed(2)}</td></tr>` : ''}
                ${ivCost > 0 ? `<tr><td>IV Fluid Fee (${s.iv_details || 'General'})</td><td style="text-align:right">₹${ivCost.toFixed(2)}</td></tr>` : ''}
                ${uptCost > 0 ? `<tr><td>UPT Card Fee</td><td style="text-align:right">₹${uptCost.toFixed(2)}</td></tr>` : ''}
                ${totalMeds > 0 ? `<tr><td>Pharmacy Medicines</td><td style="text-align:right">₹${totalMeds.toFixed(2)}</td></tr>` : ''}
            </tbody>
        </table>
    `;

    if (medicines.length > 0) {
        html += `
            <h4 style="margin-top:15px;">Medicines List</h4>
            <table class="data-table" style="margin-bottom:15px; font-size:0.9em;">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Qty Purchased</th>
                        <th>Returned Qty</th>
                        <th style="text-align:right">Returned Amt</th>
                    </tr>
                </thead>
                <tbody>
                    ${medicines.map(m => {
            const retQty = parseFloat(m.returned_qty) || 0;
            const retAmt = parseFloat(m.returned_amount) || 0;
            return `
                            <tr>
                                <td>${m.name}</td>
                                <td>${m.qty}</td>
                                <td>${retQty > 0 ? `<span style="color:var(--danger); font-weight:600;">${retQty}</span>` : '0'}</td>
                                <td style="text-align:right">${retAmt > 0 ? `<span style="color:var(--danger)">-₹${retAmt.toFixed(2)}</span>` : '₹0.00'}</td>
                            </tr>
                        `;
        }).join('')}
                </tbody>
            </table>
        `;
    }

    html += `
        <h4>Summary Calculation</h4>
        <table class="data-table">
            <tr><td>Subtotal</td><td style="text-align:right">₹${subtotal.toFixed(2)}</td></tr>
            ${discountPercent > 0 ? `<tr style="color:var(--danger);"><td>Discount (${discountPercent}%)</td><td style="text-align:right">-₹${discountAmt.toFixed(2)}</td></tr>` : ''}
            <tr style="font-weight:bold; font-size:1.05em; background:var(--bg-hover);"><td>Grand Total</td><td style="text-align:right">₹${grandTotal.toFixed(2)}</td></tr>
            <tr style="color:var(--emerald);"><td>Paid Amount</td><td style="text-align:right">₹${paidAmt.toFixed(2)}</td></tr>
            ${(parseFloat(s.balance_amount) || 0) > 0 ? `
                <tr style="color:var(--danger); font-weight:bold;">
                    <td>Pending Balance</td>
                    <td style="text-align:right">₹${parseFloat(s.balance_amount).toFixed(2)}</td>
                </tr>
            ` : '<tr style="color:var(--emerald); font-weight:bold;"><td>Status</td><td style="text-align:right">Fully Paid</td></tr>'}
        </table>
        
        <h4 style="margin-top:15px;">Payment Splitting</h4>
        <table class="data-table">
            <tr><td>Cash Paid</td><td style="text-align:right">₹${(parseFloat(s.cash_amount) || 0).toFixed(2)}</td></tr>
            <tr><td>GPay Paid</td><td style="text-align:right">₹${(parseFloat(s.gpay_amount) || 0).toFixed(2)}</td></tr>
            <tr><td>PhonePe Paid</td><td style="text-align:right">₹${(parseFloat(s.phonepe_amount) || 0).toFixed(2)}</td></tr>
            ${s.bank_amount ? `<tr><td>Bank Transfer Paid</td><td style="text-align:right">₹${(parseFloat(s.bank_amount) || 0).toFixed(2)}</td></tr>` : ''}
        </table>
    `;

    document.getElementById('reportDetailTitle').textContent = title;
    document.getElementById('reportDetailContent').innerHTML = html;
    openModal('reportDetailModal');
}

// ═══════════════════════════════════════════
// AUTO BACKUP LOGIC
// ═══════════════════════════════════════════
window.toggleWaProviderFields = function () {
    const providerEl = document.getElementById('settingWaProvider');
    if (!providerEl) return;
    const provider = providerEl.value;

    const metaFields = document.getElementById('metaApiFields');
    const customFields = document.getElementById('customApiFields');
    if (metaFields) metaFields.style.display = (provider === 'meta') ? 'block' : 'none';
    if (customFields) customFields.style.display = (provider === 'custom') ? 'block' : 'none';
};

function syncBackupSettingsInputs(settings) {
    if (!settings) return;

    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.value = val;
    };

    setVal('settingWaNumber', settings.whatsapp_backup_number || '');
    setVal('settingBackupTime', settings.auto_backup_time || '');
    setVal('settingWaProvider', settings.whatsapp_api_provider || 'mock');
    setVal('settingWaToken', settings.whatsapp_meta_token || '');
    setVal('settingWaPhoneId', settings.whatsapp_meta_phone_id || '');
    setVal('settingWaCustomUrl', settings.whatsapp_custom_url || '');
    setVal('settingWaCustomToken', settings.whatsapp_api_token || '');

    // Also save in localStorage for client-side interval or backwards-compatibility
    if (settings.whatsapp_backup_number) localStorage.setItem('whatsapp_backup_number', settings.whatsapp_backup_number);
    if (settings.auto_backup_time) localStorage.setItem('auto_backup_time', settings.auto_backup_time);

    window.toggleWaProviderFields();
}

async function saveAutoBackupSettings() {
    const wa = document.getElementById('settingWaNumber').value.trim();
    const time = document.getElementById('settingBackupTime').value;
    const provider = document.getElementById('settingWaProvider').value;
    const token = document.getElementById('settingWaToken').value.trim();
    const phoneId = document.getElementById('settingWaPhoneId').value.trim();
    const customUrl = document.getElementById('settingWaCustomUrl').value.trim();
    const customToken = document.getElementById('settingWaCustomToken').value.trim();

    if (wa) localStorage.setItem('whatsapp_backup_number', wa);
    if (time) localStorage.setItem('auto_backup_time', time);

    const formData = new FormData();
    formData.append('whatsapp_backup_number', wa);
    formData.append('auto_backup_time', time);
    formData.append('whatsapp_api_provider', provider);
    formData.append('whatsapp_meta_token', token);
    formData.append('whatsapp_meta_phone_id', phoneId);
    formData.append('whatsapp_custom_url', customUrl);
    formData.append('whatsapp_api_token', customToken);

    try {
        const response = await fetch('/reports_api.php?action=save_backup_settings', {
            method: 'POST',
            body: formData
        });
        const resData = await response.json();
        if (resData.success) {
            closeModal('autoBackupSettingsModal');
            toast('Auto Backup Settings Saved Successfully!');
            // Reload the report dashboard to refresh all setting fields from server
            if (typeof loadReportDashboard === 'function') {
                loadReportDashboard();
            }
        } else {
            toast('Failed to save settings to server.', 'error');
        }
    } catch (e) {
        console.error(e);
        toast('Error saving backup settings.', 'error');
    }
}

// Populate settings on load (as a fallback before API load)
document.addEventListener('DOMContentLoaded', () => {
    const wa = localStorage.getItem('whatsapp_backup_number');
    const time = localStorage.getItem('auto_backup_time');
    if (wa && document.getElementById('settingWaNumber')) document.getElementById('settingWaNumber').value = wa;
    if (time && document.getElementById('settingBackupTime')) document.getElementById('settingBackupTime').value = time;
    window.toggleWaProviderFields();
});

async function openReportSupplierDetails(supplierId) {
    try {
        const res = await api(`/api/agency/supplier/details/${supplierId}`);
        const supp = res.supplier;
        const purcs = res.purchases;

        const formatAmount = (val) => {
            let num = parseFloat(val || 0);
            return num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        };

        document.getElementById('repSuppDetailsTitle').textContent = `Purchases from ${supp.name}`;

        let html = '';
        if (purcs.length === 0) {
            html = '<p>No purchases found for this supplier.</p>';
        } else {
            purcs.forEach(p => {
                const pendingVal = parseFloat(p.balance_amount || 0);
                const paidVal = parseFloat(p.paid_amount || 0);
                const isFullyPaid = pendingVal <= 0;
                const statusColor = isFullyPaid ? 'var(--emerald)' : (pendingVal > 0 ? 'var(--danger)' : 'var(--primary)');

                let itemsHtml = `<div id="rep-supp-purc-items-${p.id}" style="display:none; margin-top:10px; background:var(--bg-primary); padding:10px; border-radius:4px; border:1px solid var(--border);">
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
                            <button class="btn btn-outline btn-sm" onclick="document.getElementById('rep-supp-purc-items-${p.id}').style.display = document.getElementById('rep-supp-purc-items-${p.id}').style.display === 'none' ? 'block' : 'none'">View Items</button>
                        </div>
                    </div>
                    ${itemsHtml}
                </div>`;
            });
        }

        // Add the supplier overall summary at the bottom (read-only)
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
                <div><small style="color:var(--text-secondary);">Total Paid</small><br><strong style="font-size:1.2em; color:var(--emerald);">₹ ${formatAmount(supp.total_paid || (parseFloat(supp.cash_amount) + parseFloat(supp.gpay_amount) + parseFloat(supp.phonepe_amount) + parseFloat(supp.bank_amount)))}</strong></div>
                <div><small style="color:var(--text-secondary);">Pending Balance</small><br><strong style="font-size:1.2em; color:var(--danger);">₹ ${formatAmount(supp.pending_balance)}</strong></div>
            </div>
        </div>`;

        document.getElementById('repSuppDetailsContent').innerHTML = html;
        openModal('repSuppDetailsModal');
    } catch (e) {
        toast('Failed to load supplier details', 'error');
    }
}
