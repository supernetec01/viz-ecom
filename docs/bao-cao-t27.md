# Báo cáo T27 — Quét và tạo bản xem trước

## Đã làm

- Worker database queue quét ClamAV, chuyển DOCX bằng LibreOffice cô lập, dùng lại InspectPdf T23 và quét preview trước ready. Request chỉ enqueue trong cùng giao dịch DB, không chờ AV/chuyển đổi. Giữ nguyên file gốc; DOC cũ vẫn từ chối.
- Migration mới thêm scan_status error, preview_status, lượt/lỗi/review. Bản ghi trước T27 chưa có bằng chứng quét chuyển error + verification_required để admin xử lý; infected giữ nguyên. Không sửa migration cũ hay xóa file cũ.
- Ba lượt thực chạy tối đa, giãn 60s rồi 300s; khóa mỗi file và version chống job cũ. Lỗi chắc chắn không retry. Hết lỗi scan: error; chỉ preview lỗi: clean/failed; virus ở gốc hoặc preview: infected, khóa toàn bộ, không retry.
- FileReadiness tính sẵn sàng và thông báo ở một nơi, không thay ApplicationPolicy. Admin chỉ thấy mã/trạng thái/lỗi/lượt/thời gian, có lọc/bộ đếm. Retry kiểm tra admin mới nhất, hash và bước lỗi. Event FileProcessingRetryRequested + log audit là điểm nối T35; chưa tạo audit_logs hoặc route nội dung.
- LibreOffice dùng InitialPage=0, không sửa PDF hậu kỳ; xem [bằng chứng](t27-libreoffice-openaction.md). Không nới T23.

## Tiêu chí đạt và kiểm tra

- `FileProcessingAuthorizationTest`: staff/employer nhận 403 ở trang/retry/widget; không lộ định danh/tên file; infected không retry; thu hồi admin chặn ngay; bộ đếm và không có route nội dung.
- `FileProcessingTest`: DOCX sạch ready/idempotent; nhiễm khóa; lỗi tạm ba lượt; hỏng và Word có mật khẩu thật không retry; lỗi preview giữ khả năng tải gốc clean; file đổi phải quét lại; job cũ/bận không xử lý trùng. Test riêng virus preview và qpdf timeout.
- `FileProcessingEnqueueTest`: payload ID/version, vẫn bất đồng bộ khi default sync; enqueue lỗi hoàn tác DB/file.
- `DocumentSandboxTest`: LibreOffice thật, nội dung/số trang không đổi, PDF upload vẫn chặn OpenAction; thiếu image/signatures fail closed; timeout dừng cả container/dọn staging. Runner kiểm tra TCP ENETUNREACH, rootfs chỉ đọc, memory/PID cgroup và dung lượng tmpfs mỗi lượt.
- Bộ tổng local: **706 passed, 2447 assertions**, một test symlink bỏ qua Windows; `composer lint` (Pint/Larastan) xanh. Sau đó bổ sung dataset cleanup processor staging và chạy lại riêng phần bị ảnh hưởng. CI Ubuntu chạy toàn bộ commit cuối, kết quả ghi tại PR.
- ClamAV thật với definitions chính thức: DOCX tổng hợp và preview sạch, tổng hai scan khoảng **19,405 giây** local. Không phải benchmark 10 MB/p95 hoặc chứng minh phát hiện mọi malware. CI dùng AV giả để xác định; runner LibreOffice/qpdf chạy thật.
- Browser: bộ đếm ba metadata thử, infected không retry, clean/failed hiện đúng thông báo. Retry thành clean/pending, scan_attempts giữ 1, preview_attempts/processing_attempts về 0. Không lỗi console, không thông tin ứng viên hay nút xem/tải. Tài khoản/file/queue thử đã dọn.

## Chưa đạt / chờ task sau

Chưa kiểm chứng rootless/cgroup/khóa trên Ubuntu production, worker thường trực, updater definitions và giám sát. T23 vẫn draft thiếu mẫu Canva sạch/điện thoại, PDF production giữ tắt. T30/T33 nối readiness + policy/audit; T35 nối audit event; T42 nối lịch cleanup đang tắt. Không làm trước các phần này.

## Giả định

Disk local riêng tư hỗ trợ flock, cache lock dùng chung giữa worker, database queue cùng kết nối PostgreSQL với giao dịch hồ sơ. Vận hành kiểm soát signatures; runner chỉ đọc, daily tối đa 72 giờ. Clean chỉ là vượt các kiểm tra hiện có, không bảo đảm tuyệt đối không có exploit/malware mới.

## Đề xuất

T48 thử trên chính máy chủ, đo tải thực và giám sát queue/definitions. Chưa nhận dữ liệu ứng viên thật trước khi đủ phân quyền/audit/retention và kiểm chứng vận hành. Dừng T27 chờ xem lại.
