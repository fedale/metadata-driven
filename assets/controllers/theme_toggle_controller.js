import { Controller } from '@hotwired/stimulus';
import { removeCsrfToken } from './csrf_protection_controller.js';

/*
 * The light / dark toggle of the top bar. Until it is used the page follows the system's
 * colour scheme; a click forces the other one at once: data-gv-theme on <html> (read by app.css
 * and by the gridview-bundle stylesheet), kept for a year in the md_theme cookie, which
 * base.html.twig reads to set the attribute before the first paint.
 *
 * For a signed-in user the toggle is a form (submit event): the choice is also saved in the
 * profile, in the background, so that it follows the user to other browsers. The csrf-protection
 * listener has turned the token into a double-submit cookie by the time this runs.
 */
const COOKIE = 'md_theme';
const ONE_YEAR = 60 * 60 * 24 * 365;

export default class extends Controller {
    static targets = ['theme'];

    toggle(event) {
        event.preventDefault();
        const theme = this.current() === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.gvTheme = theme;
        document.cookie = `${COOKIE}=${theme}; path=/; max-age=${ONE_YEAR}; SameSite=Lax`;

        if (this.element instanceof HTMLFormElement && this.hasThemeTarget) {
            this.save(this.element, theme);
        }
    }

    current() {
        const forced = document.documentElement.dataset.gvTheme;
        if ('light' === forced || 'dark' === forced) {
            return forced;
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    async save(form, theme) {
        this.themeTarget.value = theme;
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
            if (!response.ok) {
                console.warn('The theme could not be saved in the profile.', response.status);
            }
        } catch (error) {
            // Offline: the page and the cookie have the theme; the profile keeps the old one.
            console.warn('The theme could not be saved in the profile.', error);
        } finally {
            removeCsrfToken(form);
        }
    }
}
