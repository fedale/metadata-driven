import { Controller } from '@hotwired/stimulus';

/*
 * The bell of the top bar: its count of unread notifications asked again every minute, while the
 * page is open (the application has no hub to push it). The menu is a lazy Turbo Frame: it loads
 * when the bell opens; opened again, it is loaded afresh. The timer stops with the controller,
 * so a Turbo visit replacing the top bar leaves none running.
 */
const EVERY_MS = 60000;

export default class extends Controller {
    static targets = ['count'];

    static values = { url: String };

    connect() {
        this.refresh = this.refresh.bind(this);
        this.toggled = this.toggled.bind(this);
        this.timer = window.setInterval(this.refresh, EVERY_MS);
        this.element.addEventListener('toggle', this.toggled);
    }

    disconnect() {
        window.clearInterval(this.timer);
        this.element.removeEventListener('toggle', this.toggled);
    }

    toggled() {
        if (!this.element.open) {
            return;
        }
        const frame = this.element.querySelector('turbo-frame');
        if (frame && frame.complete) {
            frame.reload();
        }
    }

    async refresh() {
        if (document.hidden) {
            return;
        }
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) {
                return;
            }
            const { unread } = await response.json();
            this.show(Number(unread) || 0);
        } catch (error) {
            // Offline for a moment: the next minute tries again.
            console.warn('The count of the notifications could not be read.', error);
        }
    }

    show(unread) {
        if (!this.hasCountTarget) {
            return;
        }
        this.countTarget.textContent = String(unread);
        this.countTarget.hidden = 0 === unread;
    }
}
