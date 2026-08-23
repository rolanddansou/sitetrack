import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.onClickOutside = this.onClickOutside.bind(this);
        document.addEventListener('click', this.onClickOutside);
    }

    disconnect() {
        document.removeEventListener('click', this.onClickOutside);
    }

    toggle(event) {
        event.stopPropagation();
        this.menuTarget.classList.toggle('hidden');
    }

    onClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.menuTarget.classList.add('hidden');
        }
    }
}
