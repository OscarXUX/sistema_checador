// Tablas (FlexGrid). Páginas: Dashboard, Empleados y Kardex.
import '../../css/wijmo.css';
import wjCore from '../wijmo-bootstrap';
import '@mescius/wijmo.cultures/wijmo.culture.es-MX.js';
import * as wjGrid from '@mescius/wijmo.grid';
import { registerWijmo } from './register';

registerWijmo({ core: wjCore, grid: wjGrid });
