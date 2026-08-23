import { Controller } from '@hotwired/stimulus';

/*
 * Toggles the HTTP vs SMTP fields on the "new monitor" form. This used to be
 * a global inline <script> function — that broke under Turbo Drive, since
 * inline scripts inserted via a Turbo page render don't execute. Stimulus
 * controllers reconnect automatically on every Turbo navigation, so this is
 * the correct replacement, not just a nicer one.
 */
export default class extends Controller {
    static targets = ['typeSelect', 'httpFields', 'smtpFields', 'targetLabel', 'targetHelp'];

    connect() {
        this.toggle();
    }

    toggle() {
        const isHttp = this.typeSelectTarget.value === 'http';

        this.httpFieldsTarget.classList.toggle('hidden', !isHttp);
        this.smtpFieldsTarget.classList.toggle('hidden', isHttp);

        if (isHttp) {
            this.targetLabelTarget.textContent = 'Target URL';
            this.targetHelpTarget.textContent = 'Example: https://your-website.com/health';
        } else {
            this.targetLabelTarget.textContent = 'SMTP Server Host & Port';
            this.targetHelpTarget.textContent = 'Example: smtp.postmarkapp.com:587';
        }
    }
}
