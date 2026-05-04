/**
 * BACKEND: Google Apps Script (codigo.gs)
 * Este script actúa como la API para la aplicación de Gastos Compartidos.
 */

const SHEET_NAME = "Gastos";
const CONFIG_SHEET_NAME = "Config";

/**
 * Inicializa la hoja de cálculo si no existe o no tiene cabeceras.
 */
function setupSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(SHEET_NAME);
  if (!sheet) {
    sheet = ss.insertSheet(SHEET_NAME);
    sheet.appendRow(["ID", "Fecha", "Comercio", "Importe", "Categoria", "Moneda", "Usuario", "Apellido", "ImporteBase"]);
  }
  return sheet;
}

/**
 * Inicializa la hoja de configuración de monedas si no existe.
 */
function setupConfigSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(CONFIG_SHEET_NAME);
  if (!sheet) {
    sheet = ss.insertSheet(CONFIG_SHEET_NAME);
    sheet.appendRow(["Code", "Rate", "IsBase"]);
    // Valores iniciales por defecto (Base: BRL)
    sheet.appendRow(["BRL", 1, true]);
    sheet.appendRow(["ARS", 210, false]);
    sheet.appendRow(["USD", 0.18, false]);
  }
  return sheet;
}

/**
 * Maneja las peticiones GET para obtener el historial o la configuración.
 */
function doGet(e) {
  const type = e.parameter.type;
  
  if (type === "config") {
    const sheet = setupConfigSheet();
    const data = sheet.getDataRange().getValues();
    const rows = data.slice(1);
    const result = rows
      .filter(row => row[0] && row[0].toString().trim() !== "") // Filtrar filas vacías
      .map(row => ({
        code: row[0].toString(),
        rate: parseFloat(row[1]) || 1,
        isBase: row[2] === true || row[2] === "true"
      }));
    return jsonResponse(result);
  }

  // Por defecto: Historial de gastos
  const sheet = setupSheet();
  const data = sheet.getDataRange().getValues();
  const headers = data[0];
  const rows = data.slice(1);
  const result = rows.map(row => {
    let obj = {};
    headers.forEach((header, i) => {
      let val = row[i];
      if (header === "Fecha" && val instanceof Date) {
        val = Utilities.formatDate(val, SpreadsheetApp.getActive().getSpreadsheetTimeZone(), "yyyy-MM-dd");
      }
      obj[header] = val;
    });
    return obj;
  });
  return jsonResponse(result);
}

/**
 * Maneja las peticiones POST para crear o editar registros.
 */
function doPost(e) {
  try {
    const payload = JSON.parse(e.postData.contents);
    const action = payload.action; 
    
    if (action === "create") {
      const sheet = setupSheet();
      const id = Utilities.getUuid();
      const newRow = [
        id,
        payload.fecha || new Date().toISOString().split('T')[0],
        payload.comercio,
        payload.importe,
        payload.categoria,
        payload.moneda,
        payload.usuario,
        payload.apellido,
        payload.importeBase
      ];
      sheet.appendRow(newRow);
      return successResponse({ id: id });
    } 
    
    if (action === "update" || action === "delete") {
      const sheet = setupSheet();
      const id = (payload.id || "").toString().trim();
      if (!id) return errorResponse("Falta el ID del registro");
      
      // Búsqueda ultra-robusta usando TextFinder en Columna A
      const finder = sheet.getRange("A:A").createTextFinder(id).matchEntireCell(true);
      const match = finder.findNext();
      
      if (!match) {
        logData("ERROR: ID no encontrado para " + action + ": " + id);
        return errorResponse("No se encontró el registro con ID: " + id);
      }

      const rowNum = match.getRow();
      logData("Accion: " + action + " en Fila: " + rowNum + " ID: " + id);

      if (action === "delete") {
        try {
          sheet.deleteRow(rowNum);
          return successResponse({ message: "Eliminado correctamente" });
        } catch (e) {
          logData("Excepcion al borrar: " + e.toString());
          return errorResponse("Error al borrar fila: " + e.toString());
        }
      } else {
        // Update logic
        sheet.getRange(rowNum, 2).setValue(payload.fecha);
        sheet.getRange(rowNum, 3).setValue(payload.comercio);
        sheet.getRange(rowNum, 4).setValue(payload.importe);
        sheet.getRange(rowNum, 5).setValue(payload.categoria);
        sheet.getRange(rowNum, 6).setValue(payload.moneda);
        sheet.getRange(rowNum, 7).setValue(payload.usuario);
        sheet.getRange(rowNum, 8).setValue(payload.apellido);
        sheet.getRange(rowNum, 9).setValue(payload.importeBase);
        return successResponse({ message: "Actualizado correctamente" });
      }
    }

    if (action === "updateCurrencies") {
      logData("Sincronizando divisas...");
      const sheet = setupConfigSheet();
      if (payload.currencies && Array.isArray(payload.currencies)) {
        sheet.clearContents();
        sheet.appendRow(["Code", "Rate", "IsBase"]);
        payload.currencies.forEach(c => {
          if (c.code) {
             sheet.appendRow([c.code, parseFloat(c.rate) || 1, c.isBase]);
          }
        });
        return successResponse({ message: "Divisas actualizadas" });
      }
      return errorResponse("Faltan divisas o formato inválido");
    }

    if (action === "reset") {
      logData("Reiniciando App...");
      const ss = SpreadsheetApp.getActiveSpreadsheet();
      const oldSheet = ss.getSheetByName(SHEET_NAME);
      if (oldSheet) {
        const timestamp = Utilities.formatDate(new Date(), "GMT-3", "yyyyMMdd_HHmm");
        oldSheet.setName(SHEET_NAME + "_Archive_" + timestamp);
      }
      setupSheet(); 
      return successResponse({ message: "Reinicio completo" });
    }

    return errorResponse("Invalid action: " + action);
  } catch (err) {
    logData("CATCH ERROR: " + err.toString());
    return errorResponse(err.toString());
  }
}

/**
 * Registra logs de depuración en una hoja oculta.
 */
function logData(msg) {
  try {
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    let logSheet = ss.getSheetByName("_DEBUG_LOG_");
    if (!logSheet) {
      logSheet = ss.insertSheet("_DEBUG_LOG_");
      logSheet.hideSheet();
      logSheet.appendRow(["Timestamp", "Mensaje"]);
    }
    logSheet.appendRow([new Date(), msg]);
  } catch (e) {}
}

function jsonResponse(data) {
  return ContentService.createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

function successResponse(data) {
  return ContentService.createTextOutput(JSON.stringify({ status: "success", data: data }))
    .setMimeType(ContentService.MimeType.JSON);
}

function errorResponse(msg) {
  return ContentService.createTextOutput(JSON.stringify({ status: "error", message: msg }))
    .setMimeType(ContentService.MimeType.JSON);
}
