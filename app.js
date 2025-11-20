const api = (action, data = {}) => fetch(`api.php?action=${action}`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(data)
}).then(r => r.json());

const state = { products: [], saleLines: [], csvRows: [], csvHeaders: [] };

function populateSelect(select, headers) {
  select.innerHTML = headers.map(h => `<option value="${h}">${h}</option>`).join('');
}

function parseCsv(content) {
  const rows = content.trim().split(/\r?\n/).map(line => line.split(/,(?=(?:[^"]*"[^"]*")*[^"]*$)/).map(cell => cell.replace(/^"|"$/g, '')));
  const headers = rows.shift();
  state.csvHeaders = headers;
  state.csvRows = rows;
  populateSelect(document.getElementById('mapReference'), headers);
  populateSelect(document.getElementById('mapDescription'), headers);
  populateSelect(document.getElementById('mapBarcode'), headers);
  populateSelect(document.getElementById('mapPrice'), headers);
  document.getElementById('mappingArea').style.display = 'block';
  renderPreview(headers);
}

function renderPreview(headers) {
  const tbody = document.getElementById('productsPreview');
  tbody.innerHTML = '';
  state.csvRows.slice(0, 5).forEach(row => {
    const tr = document.createElement('tr');
    headers.forEach((_, idx) => {
      const td = document.createElement('td');
      td.textContent = row[idx];
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
}

function importProducts() {
  const headers = {
    reference: document.getElementById('mapReference').value,
    description: document.getElementById('mapDescription').value,
    barcode: document.getElementById('mapBarcode').value,
    price: document.getElementById('mapPrice').value,
  };

  const selected = Object.values(headers);
  if (new Set(selected).size !== selected.length) {
    alert('Cada campo debe mapear a una columna distinta.');
    return;
  }

  const index = (key) => state.csvHeaders.indexOf(headers[key]);
  const products = state.csvRows.map(row => ({
    reference: row[index('reference')],
    description: row[index('description')],
    barcode: row[index('barcode')],
    price: parseFloat(row[index('price')])
  }));
  api('importProducts', { items: products }).then(res => {
    alert(`Productos importados: ${res.imported}`);
    document.getElementById('mappingArea').style.display = 'none';
  });
}

function loadSettings() {
  api('settings').then(({ settings }) => {
    document.getElementById('printerName').value = settings.printerName || '';
    document.getElementById('ticketHeader').value = settings.ticketHeader || '';
    document.getElementById('ticketFooter').value = settings.ticketFooter || '';
    document.getElementById('defaultVat').value = settings.defaultVat || 21;
    document.getElementById('exportPath').value = settings.exportPath || 'exports';
    document.getElementById('vatRate').value = settings.defaultVat || 21;
    updateTotals();
  });
}

function saveSettings() {
  const payload = {
    printerName: document.getElementById('printerName').value,
    ticketHeader: document.getElementById('ticketHeader').value,
    ticketFooter: document.getElementById('ticketFooter').value,
    defaultVat: parseFloat(document.getElementById('defaultVat').value || '21'),
    exportPath: document.getElementById('exportPath').value || 'exports'
  };
  api('saveSettings', payload).then(() => alert('Configuración guardada'));
}

function sessionStatus() {
  api('sessionStatus').then(res => {
    const status = document.getElementById('sessionStatus');
    const closePanelBtn = document.getElementById('closeSessionPanel');
    if (res.open) {
      status.textContent = `Caja abierta (#${res.sessionId})`;
      status.classList.remove('closed');
      if (closePanelBtn) closePanelBtn.disabled = false;
    } else {
      status.textContent = 'Caja cerrada';
      status.classList.add('closed');
      if (closePanelBtn) closePanelBtn.disabled = true;
    }
  });
}

function openSession() {
  const openingCash = parseFloat(document.getElementById('openingCash').value || '0');
  api('openSession', { openingCash }).then(() => sessionStatus());
}

function addSaleLine(product = {}) {
  const line = {
    reference: product.reference || 'MANUAL',
    description: product.description || 'Línea manual',
    barcode: product.barcode || '',
    price: product.price ? Number(product.price) : 0,
    quantity: 1,
    discount: 0,
  };
  state.saleLines.push(line);
  renderSaleLines();
}

function renderSaleLines() {
  const tbody = document.getElementById('saleLines');
  tbody.innerHTML = '';
  state.saleLines.forEach((line, idx) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td contenteditable onblur="updateLine(${idx}, 'reference', this.innerText)">${line.reference}</td>
      <td contenteditable onblur="updateLine(${idx}, 'description', this.innerText)">${line.description}</td>
      <td><input type="number" min="0.01" step="0.01" value="${line.quantity}" onchange="updateLine(${idx}, 'quantity', this.value)"></td>
      <td><input type="number" min="0" step="0.01" value="${line.price}" onchange="updateLine(${idx}, 'price', this.value)"></td>
      <td><input type="number" min="0" step="0.1" value="${line.discount}" onchange="updateLine(${idx}, 'discount', this.value)"></td>
      <td>${lineTotal(line).toFixed(2)}</td>
      <td><button class="secondary" onclick="removeLine(${idx})">Eliminar</button></td>`;
    tbody.appendChild(tr);
  });
  updateTotals();
}

window.updateLine = (idx, field, value) => {
  const line = state.saleLines[idx];
  if (!line) return;
  line[field] = field === 'description' || field === 'reference' ? value : parseFloat(value);
  renderSaleLines();
};

window.removeLine = (idx) => {
  state.saleLines.splice(idx, 1);
  renderSaleLines();
};

function lineTotal(line) {
  const qty = Number(line.quantity || 0);
  const price = Number(line.price || 0);
  const discount = Number(line.discount || 0);
  return qty * price * (1 - (discount / 100));
}

function updateTotals() {
  const subtotal = state.saleLines.reduce((sum, l) => sum + lineTotal(l), 0);
  const vatRate = parseFloat(document.getElementById('vatRate').value || '0');
  const tax = subtotal * (vatRate / 100);
  const total = subtotal + tax;
  document.getElementById('baseValue').textContent = subtotal.toFixed(2);
  document.getElementById('taxValue').textContent = tax.toFixed(2);
  document.getElementById('totalValue').textContent = total.toFixed(2);
}

function fetchProducts(search = '') {
  api('products', { search }).then(res => state.products = res.products || []);
}

function searchHandler(e) {
  const term = e.target.value.trim();
  if (e.key === 'Enter' && state.products.length) {
    const match = state.products[0];
    addSaleLine(match);
    e.target.value = '';
  } else {
    fetchProducts(term);
  }
}

function charge() {
  if (!state.saleLines.length) {
    alert('Añade líneas antes de cobrar');
    return;
  }
  const vatRate = parseFloat(document.getElementById('vatRate').value || '0');
  const items = state.saleLines.map(l => ({ ...l, lineTotal: lineTotal(l) }));
  api('createTicket', { items, vatRate }).then(res => {
    if (res.error) { alert(res.error); return; }
    renderTicket(res.ticket);
    state.saleLines = [];
    renderSaleLines();
  });
}

function renderTicket(ticket) {
  const text = [ticket.header, '----------------------'];
  ticket.items.forEach(l => text.push(`${l.reference} x${l.quantity} ${lineTotal(l).toFixed(2)}`));
  text.push('----------------------');
  text.push(`Base: ${ticket.totals.subtotal.toFixed(2)}`);
  text.push(`IVA (${ticket.totals.vatRate}%): ${ticket.totals.tax.toFixed(2)}`);
  text.push(`TOTAL: ${ticket.totals.total.toFixed(2)}`);
  text.push(ticket.footer);
  const preview = document.getElementById('ticketPreview');
  preview.textContent = text.join('\n');
  document.getElementById('ticketModal').classList.add('active');
}

function closeTicketModal() { document.getElementById('ticketModal').classList.remove('active'); }

function openCloseSession() {
  api('closeSession').then(res => {
    if (res.error) { alert(res.error); return; }
    const lines = [
      `Total caja: ${res.total.toFixed(2)}`,
      `Base: ${res.closingTicket.base.toFixed(2)}`,
      `IVA (${res.closingTicket.vatRate}%): ${res.closingTicket.tax.toFixed(2)}`,
      `Exportado a: ${res.exportFile}`
    ];
    document.getElementById('closingPreview').textContent = lines.join('\n');
    document.getElementById('closingModal').dataset.csv = res.csvContent;
    document.getElementById('closingModal').classList.add('active');
    sessionStatus();
  });
}

function downloadCsv() {
  const csv = document.getElementById('closingModal').dataset.csv || '';
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'ventas_cierre.csv';
  a.click();
  URL.revokeObjectURL(url);
}

function printNode(nodeId) {
  const content = document.getElementById(nodeId).textContent;
  const win = window.open('', 'PRINT', 'height=400,width=600');
  win.document.write(`<pre>${content}</pre>`);
  win.document.close();
  win.focus();
  win.print();
  win.close();
}

function clearHistory() {
  const from = document.getElementById('fromDate').value;
  const to = document.getElementById('toDate').value;
  if (!from || !to) { alert('Selecciona fechas'); return; }
  api('clearHistory', { from, to }).then(res => alert(res.cleared ? 'Historial borrado' : 'Sin cambios'));
}

function switchTab(target) {
  document.querySelectorAll('.tab').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === target));
  document.querySelectorAll('.tab-panel').forEach(panel => panel.classList.toggle('active', panel.dataset.section === target));
}

function init() {
  document.getElementById('csvInput').addEventListener('change', e => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = evt => parseCsv(evt.target.result);
    reader.readAsText(file);
  });
  document.getElementById('importBtn').addEventListener('click', importProducts);
  document.getElementById('saveSettings').addEventListener('click', saveSettings);
  document.getElementById('openSession').addEventListener('click', openSession);
  document.getElementById('closeSession').addEventListener('click', openCloseSession);
  document.getElementById('closeSessionPanel').addEventListener('click', openCloseSession);
  document.getElementById('search').addEventListener('keyup', searchHandler);
  document.getElementById('addManual').addEventListener('click', () => addSaleLine());
  document.getElementById('chargeBtn').addEventListener('click', charge);
  document.getElementById('vatRate').addEventListener('change', updateTotals);
  document.getElementById('printTicket').addEventListener('click', () => printNode('ticketPreview'));
  document.getElementById('closeModal').addEventListener('click', closeTicketModal);
  document.getElementById('closeClosing').addEventListener('click', () => document.getElementById('closingModal').classList.remove('active'));
  document.getElementById('downloadCsv').addEventListener('click', downloadCsv);
  document.getElementById('printClosing').addEventListener('click', () => printNode('closingPreview'));
  document.getElementById('clearHistory').addEventListener('click', clearHistory);
  document.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => switchTab(btn.dataset.tab)));
  loadSettings();
  sessionStatus();
  fetchProducts();
}

document.addEventListener('DOMContentLoaded', init);
