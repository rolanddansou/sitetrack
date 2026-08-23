import { Controller } from '@hotwired/stimulus';

/*
 * Polls the "online now" count. `disconnect()` clears the interval — under
 * Turbo Drive the DOM gets swapped on every navigation, so a leaked
 * setInterval would keep firing after the user leaves the page.
 */
export default class extends Controller {
    static targets = ['count'];
    static values = { url: String, interval: { type: Number, default: 15000 } };

    connect() {
        this.timer = setInterval(() => this.poll(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async poll() {
        let response;
        try {
            response = await fetch(this.urlValue);
        } catch {
            return;
        }
        if (!response.ok) {
            return;
        }
        const data = await response.json();
        this.countTarget.textContent = data.count;
    }
}
