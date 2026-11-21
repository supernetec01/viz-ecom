# T23 — Dịch vụ file riêng tư (đang làm, PR draft)

## Đã làm

- Thêm disk candidate-files ngoài web root, tách disk local có route ký: serve=false, private, throw=true; không URL hoặc symlink trực tiếp.
- Kiểm tra dung lượng thật 1–10 MiB và finfo trên nội dung, không tin MIME/size client. DOC/DOCM từ chối với thông báo tiếng Việt; DOC cũ hướng dẫn lưu lại PDF hoặc DOCX, hiện nêu rõ PDF cũng chưa mở.
- DOCX đọc ZIP không extract: kiểm tra OPC/content types/main document/relationships, chặn macro/VBA, OLE/ActiveX/embedded binary và mọi quan hệ ngoài. Chặn DTD/entity, đường dẫn nguy hiểm, mục trùng, mã hóa ZIP; giới hạn số mục, kích thước giải nén, tỉ lệ nén/XML; đọc stream từng mục có giới hạn và đối chiếu số byte thực.
- StoreCandidateFile lưu UUID theo cv/company/job/applicationULID, streaming, metadata/hash SHA-256 thật, trạng thái pending; không ghi DB. DTO metadata không tự JSON serialize lộ path/tên gốc; có cleanup đúng path mới tạo khi lỗi, không phải chức năng xóa hồ sơ của ứng viên.
- Đã triển khai phương án qpdf + JSON v2 được người dùng duyệt, dùng Docker Linux trên Windows và CI Ubuntu. Đọc object stream đã mở rộng, kiểm tra mọi key/name/indirect name, chặn toàn bộ danh sách JS/JavaScript/OpenAction/AA/Launch/GoToR/GoToE/SubmitForm/ImportData/XFA/RichMedia/3D/EmbeddedFile và marker liên quan; từ chối mã hóa cả mật khẩu rỗng, giữ nguyên original. Không thêm Composer package.
- Runner qpdf không shell, env tối thiểu, thư mục/file staging riêng; container non-root/read-only/network none/cap-drop/no-new-privileges. Kiểm chứng thực tế TCP ra ngoài nhận ENETUNREACH, chỉ interface lo, cgroup memory đúng, ghi rootfs bị EROFS. Test RAM thật ghi oom_kill; treo/missing/output quá mức đều từ chối và dọn file/container. Giới hạn 5s xử lý, 256 MiB, 1 CPU, PID 32, output16MiB, object10k/depth32/node100k; lỗi/cleanup lỗi fail closed.
- Mặc định PDF vẫn tắt, production tiếp tục khóa tới khi đủ mẫu/signoff; production cần rootless Docker và image ID pin, không fallback native hay bỏ kiểm tra. Có hướng dẫn Windows/Ubuntu và phụ thuộc T48 tại `docs/pdf-inspector-t48.md`. Chỉ cài qpdf trong image, không thêm PHP parser/Ghostscript; chưa có form/endpoint.

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

Thêm `PdfInspectionTest.php`: từng marker trong danh sách bị cấm, indirect action name, object stream thụ động hợp lệ, encryption/JSON sai/object/depth limit và chốt production tắt/image phải pin.

Thêm `PdfSandboxTest.php`: PDF sạch nguyên bytes, object stream sạch; PDF JavaScript hộp thoại thực trong object stream (không tìm thấy marker trong bytes nén) bị chặn; encryption có/không mật khẩu bị chặn; PDF hỏng/thiếu image, quá object count; chương trình giả qpdf treo/missing/output lớn bị chặn và dọn; OOM thật có cgroup oom_kill. Corpus Word print/Chromium/Google Docs được nhận; Word 2016/LibreOffice có OpenAction bị chặn; PDF ảnh đúng 10 MiB được nhận.

Ngày 21/11/2026: **573 test, 1828 assertions**, không skip khi `PDF_SANDBOX_TESTS=1`; `composer lint` (Pint/Larastan) và `git diff --check` xanh. CI bắt buộc build image/tải corpus checksum rồi chạy nhóm sandbox; chưa coi riêng local là bằng chứng production. Không thay asset nên không build/test lại UI không liên quan. Unit/integration dùng dữ liệu test; CV người dùng kiểm tra riêng local, không Git/CI/log nội dung, không tạo tài khoản. Unix private visibility assert trên CI Linux; Windows kiểm tra root/cấu hình, cần ACL thật khi triển khai.

## Số đo và mẫu người dùng

PDF ảnh RGB hợp lệ tổng hợp đúng 10.485.760 byte, qpdf11.9.0/DockerDesktopLinux29.9.2/Windows, quota1CPU256MiB: ba lượt **2.873s / 2.839s / 2.848s**, peak **19.025.920 / 18.747.392 / 19.922.944 byte** (~17,9–19,0MiB). Median2.848s; không phải worst case/p95/tải đồng thời hoặc số đo server. Raw metrics `.runtime/t23-ten-mib-benchmark.json`.

Mẫu người dùng metadata Canva, 383.002 byte: engine đọc được nhưng policy từ chối EmbeddedFiles/EmbeddedFile/EF/Filespec. Khoảng2.877s, peak9.080.832byte ở lượt riêng. Không kết luận file có malware; đúng cấu trúc bị chính sách cấm. Original giữ nguyên. Mẫu Word print, GoogleDocs và Chromium thật có nguồn upstream/checksum; không đổi Producer để giả nguồn.

## Tiêu chí chưa đạt — chưa hoàn tất T23

- Còn thiếu mẫu PDF xuất từ điện thoại và Canva không đính kèm để đủ ma trận tương thích; bản Canva người dùng gửi là test từ chối theo chính sách, không được ghi thành mẫu sạch đã qua.
- Chưa kiểm tra runner rootless/cgroup/ACL trên máy chủ triển khai thật hoặc AV T27; không mở production PDF trước khi đạt và được review. Nếu server không bảo đảm cô lập/limits thì giữ từ chối và báo vận hành/người dùng.
- Chưa đo tổng thời gian nộp vì T24 chưa được triển khai; chưa benchmark tải đồng thời/đa dạng PDF10MiB. Đã có số đo runner10MiB, không thay bằng cam kết thời gian cho mọi tài liệu.

Giữ PR draft, không merge và không báo T23 xong. Người dùng đã chốt **chờ đủ mẫu tương thích và T23 hoàn tất rồi mới làm T24**; không tự chuyển task dù phần engine đã qua test.

## Giả định đã dùng

- 10 MB là 10 MiB = 10.485.760 byte, cùng DB T22. Giới hạn ZIP dùng các mức đã ghi ở CODEMAP, không coi DOCX container là file nén thông thường được phép upload tùy ý.
- T23 chỉ trả metadata để T24 ghi trong giao dịch; không thêm model/quyền/truy vấn hồ sơ. T24 sẽ lấy Job mới nhất từ server và tạo ULID; không truyền path/ID công ty do client quyết định.
- Pending không được xem/tải. Kiểm tra cấu trúc không thay antivirus hay sandbox chuyển đổi.

## Đề xuất và bước kế tiếp

Bổ sung mẫu điện thoại/Canva không đính kèm rồi chạy đúng engine/policy, cập nhật kết quả thật. Không tự nới OpenAction/embedded để hợp thức hóa mẫu; các false positive về mặt sản phẩm phải được xem lại riêng nếu muốn đổi chính sách. Parser/denylist chưa chứng minh chặn mọi lỗ hổng renderer, nội dung giả mạo hoặc malware mới; AV T27 và bảo vệ xem/tải T33 vẫn cần. Xem hướng dẫn T48 để kiểm chứng trên server trước mở production.

T24 đã chốt: họ tên/SĐT bắt buộc, CV tùy chọn mọi tin (không thêm cờ; T24B riêng); portfolio link HTTP/HTTPS tùy chọn, không fetch; transaction + rollback và xóa file mới khi lỗi; Turnstile server mỗi lần, một lần dùng, timeout khoảng 5s, production fail closed; nonce/CSP chỉ form, chính sách dữ liệu/README nêu Cloudflare. File portfolio T25 và lệnh dọn/test lỗi mở rộng T26 vẫn để sau.
