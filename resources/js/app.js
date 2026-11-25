for (const section of document.querySelectorAll('[data-job-share]')) {
    const input = section.querySelector('input');
    const button = section.querySelector('[data-copy-link]');
    const status = section.querySelector('[data-copy-status]');
    button.hidden = false;
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = status.dataset.success;
        } catch {
            input.focus();
            input.select();
            status.textContent = status.dataset.failure;
        }
    });
}

for (const form of document.querySelectorAll('[data-application-form]')) {
    const button = form.querySelector('button[type="submit"]');
    const label = button.textContent;
    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        button.disabled = true;
        button.textContent = button.dataset.submitting;
    });
    window.addEventListener('pageshow', () => {
        delete form.dataset.submitting;
        button.disabled = false;
        button.textContent = label;
    });
}
