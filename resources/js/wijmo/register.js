/**
 * Agrega módulos de Wijmo a window.AppUI.wijmo sin importar el orden en que se carguen
 * los archivos de la página (auditoría OP-06).
 *
 * @param {Record<string, unknown>} modules
 */
export function registerWijmo(modules) {
    window.AppUI = window.AppUI || {};
    window.AppUI.wijmo = Object.assign(window.AppUI.wijmo || {}, modules);
}
