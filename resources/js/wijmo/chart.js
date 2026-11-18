// Gráficas (FlexChart). Página: Dashboard.
import '../../css/wijmo.css';
import wjCore from '../wijmo-bootstrap';
import '@mescius/wijmo.cultures/wijmo.culture.es-MX.js';
import * as wjChart from '@mescius/wijmo.chart';
import { registerWijmo } from './register';

registerWijmo({ core: wjCore, chart: wjChart });
