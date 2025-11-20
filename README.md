# POS ligero (PHP + SQLite + JS)

Aplicación de punto de venta de escritorio (HTML/JS vanilla + PHP + SQLite) lista para usarse sin instalar dependencias adicionales.

## Funcionalidades principales
- **Importación CSV mapeable** de productos (referencia, descripción, código de barras, PVP) con previsualización.
- **Búsqueda rápida** por referencia, descripción o código de barras (compatible con pistola) y alta manual.
- **Líneas editables** con cantidad, precio, descuento opcional y borrado.
- **Control de IVA por ticket** antes de cobrar, con desglose de base e IVA en tickets y cierres de caja.
- **Plantilla editable** (encabezado/pie, alias de impresora, IVA por defecto, ruta de exportación).
- **Caja**: abrir/cerrar, exportar CSV diario al cerrar (referencia, unidades, ingresos) y ticket de cierre.
- **Historial borrable** por rango de fechas (día, mes, etc.).
- **Tickets imprimibles** usando el diálogo del sistema (selecciona tu impresora térmica USB configurada) con plantilla personalizable.

## Estructura
- `index.html`: UI moderna con modales para ticket y cierre de caja.
- `styles.css`: estilos oscuros y componentes responsivos.
- `app.js`: lógica de UI, importación CSV, búsqueda, carrito, cálculo de IVA y acciones de caja.
- `api.php`: API JSON para productos, tickets, sesiones, exportes, historial y ajustes.
- `db.php`: conexión SQLite y creación de tablas.
- `data/`: se crea automáticamente y aloja la base `db.sqlite`.
- `exports/`: carpeta de CSV generados en cierres (configurable).

## Puesta en marcha
1. Coloca el código en tu servidor PHP (>=7.4) con SQLite habilitado.
2. Asegúrate de que el proceso web pueda escribir en `data/` y `exports/` (se crean si no existen).
3. Abre `index.html` desde el mismo servidor (misma carpeta que `api.php`).

## Flujo de uso
1. **Configura** encabezado/pie, IVA y alias de impresora (para recordar la térmica seleccionada en el diálogo de impresión) y la ruta de exportación.
2. **Importa productos** con un CSV: selecciona archivo, mapea columnas y confirma.
3. **Abre caja** indicando efectivo inicial si aplica.
4. **Vende**: busca por referencia/descripcion/código o escanea; ajusta cantidad, precio y DTO; selecciona IVA por ticket y cobra.
5. **Imprime ticket** (plantilla editable con desglose de base/IVA/total).
6. **Cierra caja**: se genera CSV de ventas por referencia y ticket de cierre con base, IVA y total. El sistema pregunta por descarga del CSV y permite imprimir el ticket.
7. **Limpia historial** por rango (día/mes) para mantener el almacenamiento bajo control.

## Seguridad y datos
- Los datos se almacenan localmente en `data/db.sqlite` (SQLite). No se envían a terceros.
- Las escrituras usan sentencias preparadas para evitar inyecciones.
- Las rutas de exportación e impresión son configurables y visibles antes de descargar/imprimir.
