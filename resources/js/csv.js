/**
 * Utilidades para exportar CSV sin inyección de fórmulas (auditoría SE-15, guía 4.5).
 *
 * Excel, LibreOffice y Google Sheets ejecutan como fórmula una celda que empieza con
 * =, +, -, @, tabulador o retorno de carro. Si un nombre o departamento empieza así,
 * se le antepone un apóstrofo para que se abra como texto.
 */

const FORMULA_START = /^[=+\-@\t\r]/;

// Valores que generamos nosotros y que no son fórmulas: números (incluidos negativos) y el marcador "--:--"
const SAFE_VALUE = /^-?\d+(\.\d+)?$|^--:--$/;

/**
 * Neutraliza el valor si podría interpretarse como fórmula.
 *
 * @param {unknown} value
 * @returns {string}
 */
export function neutralizeFormula(value) {
    const text = String(value ?? '');

    if (FORMULA_START.test(text) && !SAFE_VALUE.test(text)) {
        return `'${text}`;
    }

    return text;
}

/**
 * Celda CSV entre comillas, con comillas internas duplicadas y sin fórmulas.
 *
 * @param {unknown} value
 * @returns {string}
 */
export function csvEscape(value) {
    return `"${neutralizeFormula(value).replaceAll('"', '""')}"`;
}
