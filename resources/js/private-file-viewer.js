import { getDocument, GlobalWorkerOptions, AnnotationMode } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

GlobalWorkerOptions.workerSrc = workerUrl;
const root = document.querySelector('#file-viewer');
if (root) {
    const status = root.querySelector('[data-status]');
    const canvas = root.querySelector('canvas');
    const image = root.querySelector('img');
    const controls = root.querySelector('.viewer-controls');
    const previous = root.querySelector('[data-previous]');
    const next = root.querySelector('[data-next]');
    let pdf, pageNumber = 1, rendering = false, task, loadingTask;
    const fail = () => {
        canvas.hidden = true;
        image.hidden = true;
        controls.hidden = true;
        status.textContent = '';
        root.querySelector('[data-error-message]').hidden = false;
    };
    const render = async () => {
        if (rendering) return;
        rendering = true;
        previous.disabled = next.disabled = true;
        try {
            const page = await pdf.getPage(pageNumber);
            const base = page.getViewport({ scale: 1 });
            const fit = Math.min((root.clientWidth - 24) / base.width, 1.5);
            const zoom = Number(root.querySelector('[data-zoom]').value);
            const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
            const scale = Math.min(fit * zoom * pixelRatio, Math.sqrt(8_000_000 / (base.width * base.height)));
            const viewport = page.getViewport({ scale });
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);
            canvas.style.width = `${viewport.width / pixelRatio}px`;
            task = page.render({ canvasContext: canvas.getContext('2d'), viewport, annotationMode: AnnotationMode.DISABLE });
            await task.promise;
            page.cleanup();
            canvas.hidden = false;
            controls.hidden = false;
            status.textContent = '';
            root.querySelector('[data-page]').textContent = `${root.dataset.pageLabel} ${pageNumber} / ${pdf.numPages}`;
        } catch { fail(); }
        finally {
            rendering = false;
            previous.disabled = pageNumber <= 1;
            next.disabled = pageNumber >= pdf.numPages;
        }
    };
    previous.addEventListener('click', () => { if (!rendering && pageNumber > 1) { pageNumber--; void render(); } });
    next.addEventListener('click', () => { if (!rendering && pageNumber < pdf.numPages) { pageNumber++; void render(); } });
    root.querySelector('[data-zoom]').addEventListener('change', () => void render());
    const open = async () => {
        try {
            const url = new URL(root.dataset.url, window.location.href);
            if (url.origin !== window.location.origin) throw new Error();
            if (root.dataset.pdf !== '1') {
                image.onload = () => { status.textContent = ''; image.hidden = false; };
                image.onerror = fail;
                image.src = url.href;
                return;
            }
            // Only the checked first-party endpoint is fetched. No ranges, persistence,
            // PDF scripting, HTML annotations, eval, WebAssembly or external resources.
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', redirect: 'error' });
            if (!response.ok || !response.headers.get('Content-Type')?.startsWith('application/pdf')) throw new Error();
            const bytes = await response.arrayBuffer();
            if (!bytes.byteLength || bytes.byteLength > 10 * 1024 * 1024) throw new Error();
            loadingTask = getDocument({ data: new Uint8Array(bytes), verbosity: 0, useWasm: false,
                disableFontFace: true, useWorkerFetch: false, cMapPacked: true,
                cMapUrl: `${root.dataset.assets}cmaps/`, standardFontDataUrl: `${root.dataset.assets}standard_fonts/`,
                maxImageSize: 40_000_000, canvasMaxAreaInBytes: 32_000_000, stopAtErrors: true });
            pdf = await loadingTask.promise;
            if (pdf.numPages > 1000) throw new Error();
            await render();
        } catch { fail(); }
    };
    window.addEventListener('pagehide', () => {
        task?.cancel();
        void loadingTask?.destroy();
        canvas.width = canvas.height = 0;
        image.removeAttribute('src');
    });
    window.addEventListener('pageshow', (event) => { if (event.persisted) window.location.reload(); });
    void open();
}
