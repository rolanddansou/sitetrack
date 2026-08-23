import { Controller } from '@hotwired/stimulus';
import { Chart } from 'chart.js/auto';

/*
 * Generic Chart.js wrapper — reused for the analytics time-series line
 * chart and the channel breakdown doughnut. `chart.js/auto` registers
 * every controller/element/scale in one import since AssetMapper has no
 * bundler-level tree-shaking step.
 */
export default class extends Controller {
    static targets = ['canvas'];
    static values = { type: String, data: Object, options: Object };

    connect() {
        this.chart = new Chart(this.canvasTarget, {
            type: this.typeValue,
            data: this.dataValue,
            options: this.hasOptionsValue ? this.optionsValue : {},
        });
    }

    disconnect() {
        this.chart?.destroy();
    }
}
