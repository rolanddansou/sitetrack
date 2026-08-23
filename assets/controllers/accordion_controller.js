import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content', 'icon'];

    toggle(event) {
        const trigger = event.currentTarget;
        const expanded = trigger.getAttribute('aria-expanded') === 'true';

        this.contentTarget.classList.toggle('hidden', expanded);
        trigger.setAttribute('aria-expanded', String(!expanded));
        this.iconTarget.classList.toggle('rotate-180', !expanded);
    }
}
