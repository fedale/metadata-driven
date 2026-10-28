import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
// The date filters' picker: its JavaScript is imported by the gridview controller, its CSS here.
import 'flatpickr/dist/flatpickr.min.css';
import './styles/grid.scss';
import './styles/app.css';

// Remote autocompletes (relation fields): UX Autocomplete shows every option loaded so far
// ("score" always 1, the server filters), so the results of an earlier search linger next to
// the new ones. Keep only the options whose label contains what is typed: the server's results
// always do.
document.addEventListener('autocomplete:pre-connect', (event) => {
    const options = event.detail.options;
    if (typeof options.load !== 'function') {
        return;
    }
    const labelField = options.labelField ?? 'text';
    options.score = (search) => {
        const term = search.trim().toLocaleLowerCase();

        return (item) => ('' === term || String(item[labelField] ?? '').toLocaleLowerCase().includes(term) ? 1 : 0);
    };
});
