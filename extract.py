import sys
import re

with open('templates/management.html', 'r', encoding='utf-8') as f:
    content = f.read()

# --- 1. Extract Pharmacy Inventory ---
# sectionInventory
m_inv = re.search(r'(\s*<div class="section" id="sectionInventory">.*?</div>\s*)<!-- 3\. PATIENTS -->', content, re.DOTALL)
if m_inv:
    inv_tab_content = m_inv.group(1)
else:
    print("Failed to find sectionInventory")
    sys.exit(1)

# invModal (up to historyModal)
m_inv_modal = re.search(r'(\s*<div class="modal-overlay" id="invModal">.*?)(\s*<div class="modal-overlay" id="historyModal">)', content, re.DOTALL)
if m_inv_modal:
    inv_modal_content = m_inv_modal.group(1)
else:
    print("Failed to find invModal")
    sys.exit(1)

# --- 2. Extract Agency Inventory ---
m_agency = re.search(r'(\s*<div class="section" id="sectionAgency">.*?</div>\s*)<!-- 6\. SETTINGS -->', content, re.DOTALL)
if m_agency:
    agency_tab_content = m_agency.group(1)
else:
    print("Failed to find sectionAgency")
    sys.exit(1)

# Agency modals (from gmEditModal to invModal)
m_agency_modal = re.search(r'(\s*<div class="modal-overlay" id="gmEditModal">.*?)(\s*<div class="modal-overlay" id="invModal">)', content, re.DOTALL)
if m_agency_modal:
    agency_modal_content = m_agency_modal.group(1)
else:
    print("Failed to find agency modals")
    sys.exit(1)

# --- Write Partials ---
import os
os.makedirs('templates/partials', exist_ok=True)

with open('templates/partials/inventory.html', 'w', encoding='utf-8') as f:
    f.write("<!-- Pharmacy Inventory Tab -->\n")
    f.write(inv_tab_content)
    f.write("\n<!-- Pharmacy Inventory Modals -->\n")
    f.write(inv_modal_content)

with open('templates/partials/agency_inventory.html', 'w', encoding='utf-8') as f:
    f.write("<!-- Agency Inventory Tab -->\n")
    f.write(agency_tab_content)
    f.write("\n<!-- Agency Inventory Modals -->\n")
    f.write(agency_modal_content)

# --- Update management.html ---
# Replace extracted sections with includes
new_content = content
new_content = new_content.replace(inv_tab_content, '\n            {% include \'partials/inventory_tab_only.html\' %}\n')
new_content = new_content.replace(inv_modal_content, '\n    {% include \'partials/inventory_modals_only.html\' %}\n')

new_content = new_content.replace(agency_tab_content, '\n            {% include \'partials/agency_tab_only.html\' %}\n')
new_content = new_content.replace(agency_modal_content, '\n    {% include \'partials/agency_modals_only.html\' %}\n')

# Wait, the user wanted inventory.html to contain BOTH tab and modal?
# If we include it in management.html, the tab goes inside the main <div class="dashboard"> structure.
# But the modals are typically placed at the end of the <body> so they don't break z-index or styling.
# Yes! It's better to separate them into:
# templates/partials/inventory.html (the tab content)
# templates/partials/inventory_modals.html (the modals)

with open('templates/partials/inventory.html', 'w', encoding='utf-8') as f:
    f.write(inv_tab_content)
with open('templates/partials/inventory_modals.html', 'w', encoding='utf-8') as f:
    f.write(inv_modal_content)

with open('templates/partials/agency_inventory.html', 'w', encoding='utf-8') as f:
    f.write(agency_tab_content)
with open('templates/partials/agency_modals.html', 'w', encoding='utf-8') as f:
    f.write(agency_modal_content)

new_content = content
new_content = new_content.replace(inv_tab_content, '\n            {% include \'partials/inventory.html\' %}\n')
new_content = new_content.replace(inv_modal_content, '\n    {% include \'partials/inventory_modals.html\' %}\n')

new_content = new_content.replace(agency_tab_content, '\n            {% include \'partials/agency_inventory.html\' %}\n')
new_content = new_content.replace(agency_modal_content, '\n    {% include \'partials/agency_modals.html\' %}\n')

# Also extract the JS? The JS is from `// --- INVENTORY MANAGEMENT ---` to the end?
# Let's just do the HTML for now.

with open('templates/management_new.html', 'w', encoding='utf-8') as f:
    f.write(new_content)

print("Extraction complete!")
