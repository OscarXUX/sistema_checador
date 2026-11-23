// Filtros por columna en FlexGrid. Página: Kardex.
// La exportación Excel (.xlsx) se genera en el servidor con PhpSpreadsheet (O1).
import * as wjFilter from '@mescius/wijmo.grid.filter';
import { registerWijmo } from './register';

registerWijmo({ filter: wjFilter });
