<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db = getDB();

// ── API: items de un comprobante emitido (llamada AJAX desde el form) ─────────
if (($_GET['api'] ?? '') === 'comp_items') {
    $compId = (int)($_GET['comp_id'] ?? 0);
    if ($compId > 0) {
        $stApi = $db->prepare("
            SELECT ci.producto_id, ci.nombre_producto, ci.cantidad_cajas, ci.cantidad_unidades,
                   ci.costo_unitario, COALESCE(p.codigo, '') AS codigo
            FROM comprobante_items ci
            LEFT JOIN productos p ON p.id = ci.producto_id
            WHERE ci.comprobante_id = ?
            ORDER BY ci.id ASC
        ");
        $stApi->execute([$compId]);
        header('Content-Type: application/json');
        echo json_encode($stApi->fetchAll(PDO::FETCH_ASSOC));
    } else {
        header('Content-Type: application/json');
        echo '[]';
    }
    exit;
}

// ── Modo edición ──────────────────────────────────────────────────────────────
$editId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editMode = $editId > 0;
$editPed  = null;
$editItems = [];

if ($editMode) {
    $st = $db->prepare("SELECT * FROM pedidos_galpon WHERE id=?");
    $st->execute([$editId]);
    $editPed = $st->fetch();
    if (!$editPed) redirect('/attos/pedidos_galpon/');
    if ($editPed['estado_pedido'] !== 'borrador') {
        redirect('/attos/pedidos_galpon/ver.php?id=' . $editId . '&msg=no_editable');
    }
    $stItems = $db->prepare("SELECT * FROM pedidos_galpon_items WHERE pedido_id=? ORDER BY id ASC");
    $stItems->execute([$editId]);
    foreach ($stItems->fetchAll() as $it) {
        $editItems[] = [
            'producto_id'    => (int)$it['producto_id'],
            'codigo'         => $it['codigo'],
            'nombre'         => $it['nombre'],
            'cajas'          => (int)$it['cajas'],
            'unidades'       => (int)$it['unidades'],
            'costo_unitario' => $it['costo_unitario'] !== null ? (float)$it['costo_unitario'] : null,
            'subtotal'       => $it['subtotal'] !== null ? (float)$it['subtotal'] : null,
        ];
    }
}

$proveedores = $db->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();

// Productos con costo de referencia (primer costo disponible en lista_precios)
$productos = $db->query("
    SELECT p.id,
           COALESCE(p.codigo,'') AS codigo,
           p.nombre,
           (SELECT lp.costo      FROM lista_precios lp WHERE lp.producto_id = p.id ORDER BY lp.lista_id ASC LIMIT 1) AS costo_unitario,
           (SELECT lp.costo_caja FROM lista_precios lp WHERE lp.producto_id = p.id ORDER BY lp.lista_id ASC LIMIT 1) AS costo_caja
    FROM productos p
    WHERE p.activo = 1
    ORDER BY p.nombre COLLATE utf8mb4_unicode_ci ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Comprobantes emitidos para importar
$comprobantesEmitidos = $db->query("
    SELECT c.id, c.numero, cl.nombre AS cliente
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.estado = 'emitido'
    ORDER BY c.numero DESC
")->fetchAll();

$pageTitle     = $editMode ? 'Editar pedido #' . $editId : 'Nuevo pedido al proveedor';
$topbarActions = $editMode
    ? '<a href="/attos/pedidos_galpon/ver.php?id=' . $editId . '" class="btn btn-secondary">← Volver</a>'
    : '<a href="/attos/pedidos_galpon/" class="btn btn-secondary">← Volver</a>';

require_once __DIR__ . '/../config/layout.php';
?>

<form method="POST" action="/attos/pedidos_galpon/actions.php" id="form-pedido" onsubmit="return validarForm()">
<input type="hidden" name="action" value="<?= $editMode ? 'update' : 'create' ?>">
<?php if ($editMode): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>

<div class="d-flex gap-2" style="align-items:flex-start;">

    <!-- Panel izquierdo -->
    <div style="flex:2;">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Datos del pedido</span>
                <span class="text-muted" style="font-size:12px;">Precios de costo (referencia lista)</span>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Proveedor *</label>
                        <?php if (empty($proveedores)): ?>
                        <div class="alert" style="background:#fef3cd; color:#856404; padding:8px 12px; border-radius:4px; font-size:13px;">
                            No hay proveedores. <a href="/attos/pedidos_galpon/proveedores.php" class="text-bordo">Agregá uno primero →</a>
                        </div>
                        <?php else: ?>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <select name="proveedor_id" class="form-control" required>
                                <option value="">— Seleccionar —</option>
                                <?php foreach ($proveedores as $pv): ?>
                                <option value="<?= $pv['id'] ?>"
                                    <?= ($editMode && (int)$editPed['proveedor_id'] === (int)$pv['id']) ? 'selected' : '' ?>>
                                    <?= e($pv['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <a href="/attos/pedidos_galpon/proveedores.php" class="btn btn-sm btn-outline" target="_blank" title="Gestionar proveedores">+</a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha pedido *</label>
                        <input type="date" name="fecha_pedido" class="form-control" required
                               value="<?= $editMode ? e($editPed['fecha_pedido']) : date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="2"><?= $editMode ? e($editPed['observaciones'] ?? '') : '' ?></textarea>
                </div>
            </div>
        </div>

        <!-- Importar desde comprobante -->
        <?php if (!empty($comprobantesEmitidos)): ?>
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Importar desde comprobante</span></div>
            <div class="card-body">
                <div style="display:flex; gap:8px; align-items:flex-end;">
                    <div class="form-group" style="flex:1; margin:0;">
                        <label class="form-label">Comprobante emitido</label>
                        <select id="sel-importar-comp" class="form-control">
                            <option value="">— Seleccionar comprobante —</option>
                            <?php foreach ($comprobantesEmitidos as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                #<?= (int)$c['numero'] ?> — <?= e($c['cliente']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="btn btn-outline" onclick="importarDesdeComprobante()"
                            style="white-space:nowrap; padding:8px 16px;">
                        ↓ Importar productos
                    </button>
                </div>
                <div class="text-muted" style="font-size:11px; margin-top:6px;">
                    Agrega los productos del comprobante con sus cantidades y costos. Podés importar varios.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Items -->
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Productos</span></div>
            <div class="card-body" style="padding:12px 12px 0;">
                <div style="display:flex; gap:8px; margin-bottom:12px; position:relative;">
                    <div style="flex:1; position:relative;">
                        <input type="text" id="buscador" class="form-control"
                               placeholder="Código o nombre — Enter para agregar…"
                               autocomplete="off"
                               oninput="onBuscar(this.value)"
                               onkeydown="onBuscadorKey(event)"
                               onblur="cerrarDD()">
                        <div id="buscador-dd" class="prod-dropdown" style="display:none;"></div>
                    </div>
                    <button type="button" class="btn btn-outline"
                            onclick="document.getElementById('buscador').focus()">+ Agregar</button>
                </div>

                <div class="table-wrap" style="overflow:visible;">
                    <table id="tabla-items">
                        <thead>
                            <tr>
                                <th style="width:100px;">Código</th>
                                <th>Nombre</th>
                                <th style="width:80px; text-align:center;">Cajas</th>
                                <th style="width:80px; text-align:center;">Unidades</th>
                                <th style="width:130px; text-align:right;">Costo unit.</th>
                                <th style="width:130px; text-align:right;">Subtotal</th>
                                <th style="width:34px;"></th>
                            </tr>
                        </thead>
                        <tbody id="items-body">
                            <tr id="row-empty">
                                <td colspan="6" class="text-center text-muted" style="padding:24px;">
                                    Buscá productos arriba para agregarlos al pedido.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel derecho -->
    <div style="flex:1; min-width:210px;">
        <div class="card">
            <div class="card-body">
                <div id="resumen-count" class="text-muted" style="font-size:13px; margin-bottom:12px;">0 productos</div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" style="display:flex; justify-content:space-between;">
                        Total del pedido
                        <a href="#" id="btn-recalcular" onclick="recalcularTotal(); return false;"
                           style="font-size:11px; color:#888;">↺ recalcular</a>
                    </label>
                    <input type="number" name="total" id="total-field" class="form-control"
                           step="0.01" min="0" placeholder="0.00"
                           value="<?= $editMode && $editPed['total'] !== null ? (float)$editPed['total'] : '' ?>"
                           oninput="totalManual = true">
                    <div class="text-muted" style="font-size:11px; margin-top:4px;">Editable — el proveedor puede facturar distinto.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <?= $editMode ? 'Guardar cambios' : 'Guardar pedido' ?>
                </button>
            </div>
        </div>
    </div>
</div>
</form>

<style>
.prod-dropdown {
    position:absolute; top:100%; left:0; right:0; z-index:999;
    background:#fff; border:1px solid #ddd0c4; border-top:none;
    border-radius:0 0 6px 6px; max-height:240px; overflow-y:auto;
    box-shadow:0 4px 14px rgba(0,0,0,.13);
}
.prod-option { padding:8px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid #f0ece6; display:flex; gap:8px; align-items:baseline; }
.prod-option:last-child { border-bottom:none; }
.prod-option:hover, .prod-option.dd-active { background:#f4ede3; }
.opt-cod { font-size:10px; color:#999; font-family:monospace; min-width:54px; flex-shrink:0; }
.opt-nom { font-weight:600; flex:1; }
.opt-costo { font-size:11px; color:#888; flex-shrink:0; }
</style>

<script>
var FORM_ID   = 'form-pedido';
var PRODUCTOS = <?= json_encode(array_values($productos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var PROD_MAP  = {};
PRODUCTOS.forEach(function(p) { PROD_MAP[p.id] = p; });

var EDIT_MODE  = <?= $editMode ? 'true' : 'false' ?>;
var EDIT_ITEMS = <?= json_encode($editItems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

var itemCount  = 0;
var ddMatches  = [];
var totalManual = false;

// ── Buscador ──────────────────────────────────────────────────────────────────
function onBuscar(query) {
    var dd = document.getElementById('buscador-dd');
    query = query.trim().toLowerCase();
    if (!query) { dd.style.display = 'none'; ddMatches = []; return; }

    ddMatches = PRODUCTOS.filter(function(p) {
        return p.nombre.toLowerCase().indexOf(query) !== -1 ||
               (p.codigo && p.codigo.toLowerCase().indexOf(query) !== -1);
    }).slice(0, 12);

    if (!ddMatches.length) { dd.style.display = 'none'; return; }

    dd.innerHTML = ddMatches.map(function(p, i) {
        var costoStr = p.costo_unitario !== null ? ' · $' + parseFloat(p.costo_unitario).toFixed(2) : '';
        return '<div class="prod-option" data-i="' + i + '" onmousedown="seleccionarProd(' + p.id + ')">' +
               (p.codigo ? '<span class="opt-cod">' + escHtml(p.codigo) + '</span>' : '') +
               '<span class="opt-nom">' + escHtml(p.nombre) + '</span>' +
               '<span class="opt-costo">' + costoStr + '</span>' +
               '</div>';
    }).join('');
    dd.style.display = 'block';
}

function onBuscadorKey(e) {
    var dd = document.getElementById('buscador-dd');
    if (e.key === 'Escape') { dd.style.display = 'none'; return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var opts = dd.querySelectorAll('.prod-option');
        if (!opts.length) return;
        var cur = dd.querySelector('.prod-option.dd-active');
        var ci  = cur ? parseInt(cur.dataset.i) : -1;
        ci = e.key === 'ArrowDown' ? Math.min(ci + 1, opts.length - 1) : Math.max(ci - 1, 0);
        opts.forEach(function(o) { o.classList.remove('dd-active'); });
        opts[ci].classList.add('dd-active');
        return;
    }
    if (e.key === 'Enter') {
        e.preventDefault();
        var active = dd.querySelector('.prod-option.dd-active');
        if (active) {
            seleccionarProd(ddMatches[parseInt(active.dataset.i)].id);
        } else if (ddMatches.length) {
            seleccionarProd(ddMatches[0].id);
        }
    }
}

function cerrarDD() {
    setTimeout(function() { document.getElementById('buscador-dd').style.display = 'none'; }, 160);
}

function seleccionarProd(prodId) {
    var p = PROD_MAP[prodId];
    if (!p) return;

    var existente = document.querySelector('#items-body tr[data-prod-id="' + prodId + '"]');
    if (existente) {
        var cajasInput = existente.querySelector('.cajas-vis');
        var nuevo = (parseInt(cajasInput.value) || 0) + 1;
        cajasInput.value = nuevo;
        sincronizar(parseInt(existente.dataset.idx));
        cajasInput.focus(); cajasInput.select();
    } else {
        agregarFila(p, 1, 0, p.costo_unitario !== null ? parseFloat(p.costo_unitario) : 0);
    }
    document.getElementById('buscador').value = '';
    document.getElementById('buscador-dd').style.display = 'none';
    ddMatches = [];
    document.getElementById('buscador').focus();
}

// ── Fila de item ──────────────────────────────────────────────────────────────
function agregarFila(p, cajas, unidades, costoUnitario) {
    var empty = document.getElementById('row-empty');
    if (empty) empty.remove();

    var idx      = itemCount++;
    var cajasVal = cajas    !== undefined && cajas    !== null ? parseInt(cajas)    : 1;
    var unidVal  = unidades !== undefined && unidades !== null ? parseInt(unidades) : 0;
    var costoVal = costoUnitario !== undefined && costoUnitario !== null ? parseFloat(costoUnitario) : 0;
    var subVal   = (cajasVal + unidVal) * costoVal;

    // Fila visual — inputs SIN name
    var tr = document.createElement('tr');
    tr.id = 'item-row-' + idx;
    tr.dataset.idx    = idx;
    tr.dataset.prodId = p.id;
    tr.innerHTML =
        '<td class="text-muted" style="font-size:12px;font-family:monospace;">' + escHtml(p.codigo || '—') + '</td>' +
        '<td style="font-size:13px;">' + escHtml(p.nombre) + '</td>' +
        '<td style="text-align:center;">' +
            '<input type="number" class="form-control cajas-vis" data-idx="' + idx + '"' +
            ' min="0" value="' + cajasVal + '" style="width:65px;text-align:center;"' +
            ' oninput="sincronizar(' + idx + ')">' +
        '</td>' +
        '<td style="text-align:center;">' +
            '<input type="number" class="form-control unid-vis" data-idx="' + idx + '"' +
            ' min="0" value="' + unidVal + '" style="width:65px;text-align:center;"' +
            ' oninput="sincronizar(' + idx + ')">' +
        '</td>' +
        '<td style="text-align:right;">' +
            '<input type="number" class="form-control costo-vis" data-idx="' + idx + '"' +
            ' min="0" step="0.01" value="' + costoVal.toFixed(2) + '" style="width:110px;text-align:right;"' +
            ' oninput="sincronizar(' + idx + ')">' +
        '</td>' +
        '<td id="subtotal-td-' + idx + '" style="text-align:right; font-weight:600; white-space:nowrap;">$' + subVal.toFixed(2) + '</td>' +
        '<td>' +
            '<button type="button" class="btn btn-sm btn-danger" onclick="eliminarFila(' + idx + ')" style="padding:2px 8px;">×</button>' +
        '</td>';
    document.getElementById('items-body').appendChild(tr);

    // Inputs hidden directamente en el <form>
    var form = document.getElementById(FORM_ID);
    var grp  = document.createElement('span');
    grp.id   = 'item-grp-' + idx;
    grp.style.display = 'none';
    grp.innerHTML =
        '<input type="hidden" name="items[' + idx + '][producto_id]"   value="' + p.id + '">' +
        '<input type="hidden" name="items[' + idx + '][codigo]"         value="' + escHtml(p.codigo || '') + '">' +
        '<input type="hidden" name="items[' + idx + '][nombre]"         value="' + escHtml(p.nombre) + '">' +
        '<input type="hidden" name="items[' + idx + '][cajas]"          id="hid-cajas-' + idx + '" value="' + cajasVal + '">' +
        '<input type="hidden" name="items[' + idx + '][unidades]"       id="hid-unid-'  + idx + '" value="' + unidVal  + '">' +
        '<input type="hidden" name="items[' + idx + '][costo_unitario]" id="hid-costo-' + idx + '" value="' + costoVal.toFixed(2) + '">' +
        '<input type="hidden" name="items[' + idx + '][subtotal]"       id="hid-sub-'   + idx + '" value="' + subVal.toFixed(2) + '">';
    form.appendChild(grp);

    actualizarConteo();
    recalcularTotalAuto();
}

function sincronizar(idx) {
    var tr = document.getElementById('item-row-' + idx);
    if (!tr) return;
    var cajasVis = tr.querySelector('.cajas-vis');
    var unidVis  = tr.querySelector('.unid-vis');
    var costoVis = tr.querySelector('.costo-vis');
    var hidCajas = document.getElementById('hid-cajas-' + idx);
    var hidUnid  = document.getElementById('hid-unid-'  + idx);
    var hidCosto = document.getElementById('hid-costo-' + idx);
    var hidSub   = document.getElementById('hid-sub-'   + idx);
    var subTd    = document.getElementById('subtotal-td-' + idx);

    var cajas = parseInt(cajasVis ? cajasVis.value : 0) || 0;
    var unid  = parseInt(unidVis  ? unidVis.value  : 0) || 0;
    var costo = parseFloat(costoVis ? costoVis.value : 0) || 0;
    var sub   = (cajas + unid) * costo;

    if (hidCajas) hidCajas.value = cajas;
    if (hidUnid)  hidUnid.value  = unid;
    if (hidCosto) hidCosto.value = costo.toFixed(2);
    if (hidSub)   hidSub.value   = sub.toFixed(2);
    if (subTd)    subTd.textContent = '$' + sub.toFixed(2);

    recalcularTotalAuto();
}

function eliminarFila(idx) {
    var tr  = document.getElementById('item-row-' + idx);
    var grp = document.getElementById('item-grp-' + idx);
    if (tr)  tr.remove();
    if (grp) grp.remove();
    actualizarConteo();
    recalcularTotalAuto();
    if (!document.querySelector('#items-body tr[id^="item-row-"]')) {
        var empty = document.createElement('tr');
        empty.id = 'row-empty';
        empty.innerHTML = '<td colspan="7" class="text-center text-muted" style="padding:24px;">Buscá productos arriba para agregarlos al pedido.</td>';
        document.getElementById('items-body').appendChild(empty);
    }
}

function actualizarConteo() {
    var n = document.querySelectorAll('#items-body tr[id^="item-row-"]').length;
    document.getElementById('resumen-count').textContent = n + ' producto' + (n !== 1 ? 's' : '');
}

function recalcularTotalAuto() {
    if (totalManual) return;
    var suma = 0;
    document.querySelectorAll('[id^="hid-sub-"]').forEach(function(el) {
        suma += parseFloat(el.value) || 0;
    });
    document.getElementById('total-field').value = suma > 0 ? suma.toFixed(2) : '';
}

function recalcularTotal() {
    totalManual = false;
    recalcularTotalAuto();
}

// ── Validación ────────────────────────────────────────────────────────────────
function validarForm() {
    document.querySelectorAll('#items-body tr[id^="item-row-"]').forEach(function(tr) {
        sincronizar(parseInt(tr.dataset.idx));
    });
    var rows = document.querySelectorAll('#items-body tr[id^="item-row-"]');
    if (!rows.length) { alert('Agregá al menos un producto.'); return false; }
    return true;
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Importar desde comprobante ────────────────────────────────────────────────
function importarDesdeComprobante() {
    var sel = document.getElementById('sel-importar-comp');
    if (!sel) return;
    var compId = sel.value;
    if (!compId) { alert('Seleccioná un comprobante.'); return; }

    var btn = sel.parentNode.nextElementSibling;
    btn.disabled = true;
    btn.textContent = '…';

    fetch('/attos/pedidos_galpon/crear.php?api=comp_items&comp_id=' + compId)
        .then(function(r) { return r.json(); })
        .then(function(items) {
            btn.disabled = false;
            btn.textContent = '↓ Importar productos';
            if (!items.length) { alert('El comprobante no tiene productos.'); return; }

            items.forEach(function(item) {
                var prodId = item.producto_id ? parseInt(item.producto_id) : 0;
                var cajas  = parseInt(item.cantidad_cajas)   || 0;
                var unid   = parseInt(item.cantidad_unidades) || 0;
                var costo  = parseFloat(item.costo_unitario)  || 0;

                // Si el producto ya está en la tabla, suma las cajas
                var existente = prodId
                    ? document.querySelector('#items-body tr[data-prod-id="' + prodId + '"]')
                    : null;

                if (existente) {
                    var cajasInput = existente.querySelector('.cajas-vis');
                    cajasInput.value = (parseInt(cajasInput.value) || 0) + cajas;
                    var unidInput = existente.querySelector('.unid-vis');
                    unidInput.value = (parseInt(unidInput.value) || 0) + unid;
                    sincronizar(parseInt(existente.dataset.idx));
                } else {
                    var p = prodId && PROD_MAP[prodId] ? PROD_MAP[prodId] : {
                        id: prodId,
                        codigo: item.codigo || '',
                        nombre: item.nombre_producto,
                        costo_unitario: costo
                    };
                    agregarFila(p, cajas, unid, costo);
                }
            });

            sel.value = '';
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = '↓ Importar productos';
            alert('Error al cargar el comprobante.');
        });
}

// ── Precargar modo edición ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    if (EDIT_MODE && EDIT_ITEMS.length) {
        EDIT_ITEMS.forEach(function(item) {
            var p = PROD_MAP[item.producto_id];
            if (p) agregarFila(p, item.cajas, item.unidades, item.costo_unitario);
        });
        // Si había total guardado, respetar el valor del campo (ya precargado en PHP)
        totalManual = true;
    }
});
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>
