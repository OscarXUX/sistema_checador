// Filtros por columna y exportación a Excel. Página: Kardex.
import * as wjFilter from '@mescius/wijmo.grid.filter';
import * as wjXlsx from '@mescius/wijmo.xlsx';
import * as wjGridXlsx from '@mescius/wijmo.grid.xlsx';
import JSZip from 'jszip';
import { registerWijmo } from './register';

// Registrar JSZip para exportación nativa a Excel con FlexGridXlsxConverter
const resolvedJsZip = typeof JSZip === 'function' ? JSZip : (JSZip?.default ?? JSZip);
wjXlsx.useJSZip(resolvedJsZip);

registerWijmo({ filter: wjFilter, xlsx: wjXlsx, gridXlsx: wjGridXlsx });
