import { Controller } from '@hotwired/stimulus';

/*
 * The machine names of the fields next to their labels, for an administrator writing layouts
 * with {name} tokens. Hidden until the toggle of the sheet header shows them; the choice is
 * kept in the browser for the next forms.
 *
 * The rows carry their name already: data-gv-field (the gridview-bundle controllers read it),
 * or data-md-name on a service field. The names are added on connect, and to the rows a
 * collection adds later, through a MutationObserver disconnected with the controller.
 */
const STORAGE_KEY = 'md-machine-names';

export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.annotate = this.annotate.bind(this);
        this.observer = new MutationObserver(this.annotate);
        this.observer.observe(this.element, { childList: true, subtree: true });
        this.annotate();
        this.show(this.stored() === 'on');
    }

    disconnect() {
        this.observer?.disconnect();
    }

    toggle() {
        const on = !this.element.classList.contains('md-machine-names--on');
        this.show(on);
        try {
            window.localStorage.setItem(STORAGE_KEY, on ? 'on' : 'off');
        } catch (error) {
            // Storage refused (private browsing): the choice lasts for this page only.
            console.warn('The machine names choice cannot be kept.', error);
        }
    }

    show(on) {
        this.element.classList.toggle('md-machine-names--on', on);
        this.toggleTargets.forEach((toggle) => toggle.setAttribute('aria-pressed', on ? 'true' : 'false'));
    }

    annotate() {
        this.element.querySelectorAll('[data-gv-field], [data-md-name]').forEach((row) => {
            const label = row.querySelector('label');
            // Once per label: the names added here trigger the observer too.
            if (!label || label.querySelector('.md-machine-name')) {
                return;
            }
            const name = document.createElement('code');
            name.className = 'md-machine-name';
            name.textContent = row.dataset.gvField ?? row.dataset.mdName;
            label.append(' ', name);
        });
    }

    stored() {
        try {
            return window.localStorage.getItem(STORAGE_KEY);
        } catch (error) {
            return null;
        }
    }
}
