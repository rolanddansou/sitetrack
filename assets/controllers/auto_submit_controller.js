import { Controller } from '@hotwired/stimulus';

/*
 * Auto-submits the nearest <form> on change — generic, not analytics-
 * specific. A plain GET form submit is intercepted natively by Turbo
 * Drive (already active app-wide), so no extra wiring is needed for
 * that part; this controller only saves the user a manual click.
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
