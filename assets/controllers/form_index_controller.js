import { Controller } from '@hotwired/stimulus';

/*
 * The index of a form sheet: one link per part (card), with how far its filling has come.
 *
 * - A part of one record counts its required inputs ("2/6 required"), or, without any, all its
 *   inputs ("1/7 filled"). A file field counts once, filled while it holds a file, stored or
 *   chosen.
 * - A part of many records counts its rows ("3 entries").
 *
 * The counts follow the inputs as they change, and the link of the part in view is marked.
 * The texts come translated from the page, with %done%, %total% and %count% placeholders.
 */
export default class extends Controller {
    static targets = ['part', 'link', 'progress', 'fill'];
    static values = { required: String, filled: String, none: String, one: String, many: String };

    connect() {
        this.refresh = this.refresh.bind(this);
        this.element.addEventListener('input', this.refresh);
        this.element.addEventListener('change', this.refresh);
        // Rows added or removed by the collection controller.
        this.observer = new MutationObserver(this.refresh);
        this.partTargets.forEach((part) => this.observer.observe(part, { childList: true, subtree: true }));
        this.refresh();
        this.watchCurrent();
    }

    disconnect() {
        this.element.removeEventListener('input', this.refresh);
        this.element.removeEventListener('change', this.refresh);
        this.observer?.disconnect();
        this.visibility?.disconnect();
    }

    refresh() {
        this.partTargets.forEach((part, index) => {
            const [text, ratio] = 'true' === part.dataset.many ? this.rows(part) : this.inputs(part);
            if (this.progressTargets[index]) {
                this.progressTargets[index].textContent = text;
            }
            if (this.fillTargets[index]) {
                this.fillTargets[index].style.width = `${Math.round(ratio * 100)}%`;
            }
        });
    }

    rows(part) {
        const count = part.querySelectorAll('[data-gridview-collection-target="row"]').length;
        const text = 0 === count ? this.noneValue : (1 === count ? this.oneValue : this.manyValue);

        return [text.replace('%count%', String(count)), count > 0 ? 1 : 0];
    }

    inputs(part) {
        // One entry per name: a radio group or a multiple choice is one input.
        const groups = new Map();
        part.querySelectorAll('input, select, textarea').forEach((input) => {
            if (!input.name || input.disabled || ['hidden', 'submit', 'button'].includes(input.type)) {
                return;
            }
            // A file field is one entry, its file input: its "Remove" box is part of it.
            if ('file' !== input.type && input.closest('.md-file, .md-file-list')) {
                return;
            }
            const group = groups.get(input.name) ?? { required: false, filled: false };
            group.required ||= input.required;
            group.filled ||= this.isFilled(input);
            groups.set(input.name, group);
        });
        const all = [...groups.values()];
        const required = all.filter((group) => group.required);
        const counted = required.length > 0 ? required : all;
        const done = counted.filter((group) => group.filled).length;
        const text = (required.length > 0 ? this.requiredValue : this.filledValue)
            .replace('%done%', String(done))
            .replace('%total%', String(counted.length));

        return [text, 0 === counted.length ? 0 : done / counted.length];
    }

    isFilled(input) {
        if ('file' === input.type) {
            return input.files.length > 0 || this.holdsFiles(input);
        }
        if ('checkbox' === input.type || 'radio' === input.type) {
            return input.checked;
        }
        if ('SELECT' === input.tagName) {
            return [...input.selectedOptions].some((option) => '' !== option.value);
        }

        return '' !== input.value.trim();
    }

    // A file field keeps its stored files: one file unless "Remove" is checked, a list while an
    // item is left (the file-list controller takes the removed ones out of the page).
    holdsFiles(input) {
        const list = input.closest('.md-file-list');
        if (list) {
            return null !== list.querySelector('[data-file-list-target="item"]');
        }
        const field = input.closest('.md-file');

        return null !== field?.querySelector('.md-file__current')
            && true !== field.querySelector('input[type="checkbox"]')?.checked;
    }

    // Marks the link of the part nearest the top of the window.
    watchCurrent() {
        if (!('IntersectionObserver' in window)) {
            return;
        }
        const visible = new Set();
        this.visibility = new IntersectionObserver((entries) => {
            entries.forEach((entry) => (entry.isIntersecting ? visible.add(entry.target) : visible.delete(entry.target)));
            const current = this.partTargets.find((part) => visible.has(part));
            this.linkTargets.forEach((link, index) => {
                const isCurrent = this.partTargets[index] === current;
                link.classList.toggle('md-index__item--current', isCurrent);
                isCurrent ? link.setAttribute('aria-current', 'location') : link.removeAttribute('aria-current');
            });
        }, { rootMargin: '-20% 0px -60% 0px' });
        this.partTargets.forEach((part) => this.visibility.observe(part));
    }
}
