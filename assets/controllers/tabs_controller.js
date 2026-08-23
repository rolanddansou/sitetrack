import { Controller } from '@hotwired/stimulus';

/* Generic tab switcher — one controller instance per tabbed card. */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    show(event) {
        const index = Number(event.params.index);

        this.panelTargets.forEach((panel, i) => panel.classList.toggle('hidden', i !== index));
        this.tabTargets.forEach((tab, i) => {
            tab.classList.toggle('text-indigo-600', i === index);
            tab.classList.toggle('border-indigo-600', i === index);
            tab.classList.toggle('text-slate-400', i !== index);
        });
    }
}
