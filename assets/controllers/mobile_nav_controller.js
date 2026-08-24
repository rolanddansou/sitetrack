import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['panel', 'button', 'iconOpen', 'iconClose'];

    toggle() {
        const expanded = this.buttonTarget.getAttribute('aria-expanded') === 'true';

        this.panelTarget.classList.toggle('hidden', expanded);
        this.buttonTarget.setAttribute('aria-expanded', String(!expanded));
        this.iconOpenTarget.classList.toggle('hidden', !expanded);
        this.iconCloseTarget.classList.toggle('hidden', expanded);
    }

    close() {
        this.panelTarget.classList.add('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.iconOpenTarget.classList.remove('hidden');
        this.iconCloseTarget.classList.add('hidden');
    }
}
