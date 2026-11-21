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
