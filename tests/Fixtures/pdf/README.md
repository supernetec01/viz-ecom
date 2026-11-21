# PDF test data

`generated/` contains real, syntactically valid synthetic PDFs created by this project (`scripts/generate-pdf-security-fixtures.php`, qpdf 11.9.0). They contain no real applicant information. The JavaScript variants only contain an `app.alert` dialog for detection; do not open them in a viewer that executes PDF JavaScript. Two encrypted examples use public, test-only passwords from the generator. The object-count fixture exercises a valid oversized object table.

No original applicant PDF is checked into this directory. The 10 MiB RGB image benchmark is generated under ignored `.runtime/` by `pdfFixtureBytes(image: true)`; it is not a worst-case workload.

`corpus.json` records public upstream samples, fixed repository commit/blob hashes or SHA-256, and declared producer provenance. `scripts/fetch-pdf-corpus.php` downloads and verifies them into ignored `.runtime/pdf-corpus`, for CI/local regression only. Repository samples come from smalot/pdfparser; retain their upstream attribution/license and source links. The Google Docs attachment is the short sample explicitly described by its uploader in issue 675. No Producer field was edited to simulate a different source.

The corpus deliberately includes Word/LibreOffice examples that must be **rejected** for OpenAction under the approved policy. Those are not counted as clean passing samples. The privately supplied Canva PDF was inspected locally and rejected for embedded attachments; it is not redistributed or counted as a clean Canva acceptance case. Mobile-export and clean Canva cases remain outstanding.
