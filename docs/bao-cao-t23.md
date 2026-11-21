# T23 — Dịch vụ file riêng tư (đang làm, PR draft)

## Đã làm

- Thêm disk candidate-files ngoài web root, tách disk local có route ký: serve=false, private, throw=true; không URL hoặc symlink trực tiếp.
- Kiểm tra dung lượng thật 1–10 MiB và finfo trên nội dung, không tin MIME/size client. DOC/DOCM từ chối với thông báo tiếng Việt; DOC cũ hướng dẫn lưu lại PDF hoặc DOCX, hiện nêu rõ PDF cũng chưa mở.
- DOCX đọc ZIP không extract: kiểm tra OPC/content types/main document/relationships, chặn macro/VBA, OLE/ActiveX/embedded binary và mọi quan hệ ngoài. Chặn DTD/entity, đường dẫn nguy hiểm, mục trùng, mã hóa ZIP; giới hạn số mục, kích thước giải nén, tỉ lệ nén/XML; đọc stream từng mục có giới hạn và đối chiếu số byte thực.
- StoreCandidateFile lưu UUID theo cv/company/job/applicationULID, streaming, metadata/hash SHA-256 thật, trạng thái pending; không ghi DB. DTO metadata không tự JSON serialize lộ path/tên gốc; có cleanup đúng path mới tạo khi lỗi, không phải chức năng xóa hồ sơ của ứng viên.
- **Chặn toàn bộ PDF ở mọi môi trường** tới khi bộ kiểm tra sâu được duyệt và hoàn thiện. Không thêm flag tắt kiểm tra, không coi grep bytes nén hoặc chặn toàn bộ object stream là phương án đạt yêu cầu.
- Đã tạo so sánh ba phương án PDF, cách cài Ubuntu sau duyệt, giấy phép/bảo trì, xử lý mã hóa, sandbox/resource limits và kế hoạch test/benchmark tại `docs/de-xuat-kiem-tra-pdf-t23.md`. Chưa cài qpdf/PHP parser/Ghostscript. README/thiết kế/CODEMAP/checklist nêu rõ giới hạn và quyết định T24 mới chốt.

## Tiêu chí đã đạt và test

`tests/Feature/CandidateFileStorageTest.php`:

- `rejects an executable renamed to PDF using actual bytes rather than the client MIME`.
- `rejects an executable renamed to DOCX`.
- `rejects a file that is ten MiB plus one byte by its real on disk size`.
- `rejects legacy DOC with a friendly conversion message`.
- `accepts a clean DOCX after inspecting its actual package structure`.
- `rejects DOCX containing vbaProject`.
- `rejects DOCM even when renamed content looks like a plain DOCX`.
- `rejects a remote template relationship`.
- `rejects an external hyperlink relationship without fetching it`.
- `rejects embedded OLE objects`.
- `rejects ActiveX controls`.
- `rejects dangerous archive member paths` (6 mẫu).
- `rejects a DOCX compression bomb before expanding it`.
- `rejects an abnormal number of zip entries`.
- `rejects XML external entity declarations`.
- `rejects empty required document XML with a validation error`.
- `does not accept any PDF until a deep inspector has been approved and implemented`.
- `stores checked DOCX under private company job and application directories with a UUID name and actual hash` (HTTP trực tiếp bị chặn 403 hoặc 404, không trả file; metadata JSON rỗng).
- `never writes a rejected upload to the private disk`.
- `does not allow cleanup paths supplied from outside a created candidate file context`.

Ngày 21/11/2026: **529 test, 1770 assertions**, `composer lint` (Pint/Larastan) và `git diff --check` xanh cho phần mã hiện tại. Không thay asset nên không build/test lại giao diện không liên quan. Chỉ fixture giả trên DB/disk test, không tạo tài khoản hoặc CV thực. Private Unix visibility được assert trên CI Linux; Windows không báo chính xác Unix mode, kiểm tra cấu hình/root thay thế, cần ACL thật khi triển khai.

## Tiêu chí chưa đạt — chưa hoàn tất T23

- Chưa có bộ kiểm tra PDF hiện đại/object stream; clean PDF **chưa được nhận**, chưa có test phát hiện JavaScript/OpenAction/Launch trong object stream bằng engine thật.
- Chưa chứng minh xử lý PDF mã hóa, timeout/RAM/output/no-network của engine, hoặc tương thích file xuất từ Word/Google Docs/browser/Canva/điện thoại. Test hiện tại chỉ chứng minh PDF bị chặn, không chứng minh đã xử lý PDF an toàn.
- Chưa có số đo thời gian/peak RAM với file PDF 10 MB; ngân sách trong đề xuất là mức đề xuất chờ benchmark, không phải kết quả.
- Chưa kiểm tra deployment Ubuntu/NTFS ACL hoặc antivirus T27. Không có endpoint nhận/xem/tải CV, không có form T24.

Giữ PR draft, không merge và không báo T23 xong. Người dùng đã yêu cầu duyệt công cụ trước cài/thêm, nên phần PDF đang chờ lựa chọn; không làm trước T24 trong lúc T23 chưa hoàn tất.

## Giả định đã dùng

- 10 MB là 10 MiB = 10.485.760 byte, cùng DB T22. Giới hạn ZIP dùng các mức đã ghi ở CODEMAP, không coi DOCX container là file nén thông thường được phép upload tùy ý.
- T23 chỉ trả metadata để T24 ghi trong giao dịch; không thêm model/quyền/truy vấn hồ sơ. T24 sẽ lấy Job mới nhất từ server và tạo ULID; không truyền path/ID công ty do client quyết định.
- Pending không được xem/tải. Kiểm tra cấu trúc không thay antivirus hay sandbox chuyển đổi.

## Đề xuất và bước kế tiếp

Đề xuất qpdf + kiểm tra đồ thị JSON, runner cô lập với giới hạn cứng và bộ test PDF thật; chờ duyệt trước cài. Sau khi test PDF và kiểm chứng giới hạn đạt mới chốt T23 rồi làm T24.

T24 đã chốt: họ tên/SĐT bắt buộc, CV tùy chọn mọi tin (không thêm cờ; T24B riêng); portfolio link HTTP/HTTPS tùy chọn, không fetch; transaction + rollback và xóa file mới khi lỗi; Turnstile server mỗi lần, một lần dùng, timeout khoảng 5s, production fail closed; nonce/CSP chỉ form, chính sách dữ liệu/README nêu Cloudflare. File portfolio T25 và lệnh dọn/test lỗi mở rộng T26 vẫn để sau.
