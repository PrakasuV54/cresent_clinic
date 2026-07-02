import sys
import os

with open('templates/management.html', 'r', encoding='utf-8') as f:
    lines = f.readlines()

def get_lines(start_line, end_line):
    return "".join(lines[start_line-1 : end_line])

inv_tab = get_lines(247, 287)
inv_modal = get_lines(2386, 2526)
agency_tab = get_lines(756, 1910)

os.makedirs('templates/partials', exist_ok=True)

with open('templates/partials/inventory_tab.html', 'w', encoding='utf-8') as f:
    f.write(inv_tab)

with open('templates/partials/inventory_modals.html', 'w', encoding='utf-8') as f:
    f.write(inv_modal)

with open('templates/partials/agency_tab.html', 'w', encoding='utf-8') as f:
    f.write(agency_tab)

new_lines = []
i = 1
while i <= len(lines):
    if i == 247:
        new_lines.append("            {% include 'partials/inventory_tab.html' %}\n")
        i = 287 + 1
    elif i == 756:
        new_lines.append("            {% include 'partials/agency_tab.html' %}\n")
        i = 1910 + 1
    elif i == 2386:
        new_lines.append("    {% include 'partials/inventory_modals.html' %}\n")
        i = 2526 + 1
    else:
        new_lines.append(lines[i-1])
        i += 1

with open('templates/management.html', 'w', encoding='utf-8') as f:
    f.write("".join(new_lines))

print("Extraction successful!")
