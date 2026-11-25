# T25 — Portfolio dạng link hoặc file

## Đã làm

- Portfolio bỏ trống hoặc một link HTTP/HTTPS hoặc một file PDF/JPG/JPEG/PNG tối đa 10 MiB. Gửi cả link/file bị từ chối trước captcha và lưu file; server không tự mở link.
- `InspectPortfolio` kiểm tra dung lượng thật, finfo, đuôi và cấu trúc, SHA-256. PNG có kiểm tra chunk/CRC/biên/IEND, JPEG kiểm tra marker/scan/EOI; không trailing payload. PDF dùng lại đúng qpdf inspector T23, kể cả object stream/mã hóa/marker nguy hiểm và fail closed.
- UUID và đường dẫn portfolio riêng theo company/job/application trên disk ngoài web root, metadata pending. Chưa tạo bất kỳ route xem/tải nào; test URL storage bị chặn 403/404. Cùng giao dịch với CV/application, lỗi file thứ hai hoặc DB rollback và dọn cả hai file mới; nếu cleanup lỗi vẫn thử các file còn lại.
- Ô upload và hướng dẫn tiếng Việt, lỗi upload PHP/đồng thời link-file/quá dung lượng có tiếng Việt. Trang 413 độc lập trước route/session và CSP script none; không lộ stack trace.
- Sửa cấu hình PHP portable local: 10M/file, 24M tổng request, upload_tmp_dir riêng trong workspace; ghi yêu cầu PHP/Nginx/tmp private ở README và checklist T48. Không thêm thư viện, không sửa migration cũ, T25 không thêm migration.

## Tiêu chí đạt và test

`tests/Feature/PortfolioTest.php`:

- `denies portfolio uploads to paused expired and closed jobs before storing files` (403 từng trạng thái).
- `accepts optional empty or HTTP HTTPS portfolio links without fetching them`; `rejects non web portfolio protocols` (javascript/data/file/ftp/protocol-relative).
- `stores a real JPG or PNG portfolio privately by UUID with pending metadata` (mỗi định dạng, private mode trên Linux; NTFS kiểm tra cấu hình và URL).
- `rejects simultaneous portfolio link and file without writing either file or application`.
- `rejects executables renamed to each portfolio extension using actual content`; `rejects oversized files by their real bytes rather than the reported upload size`.
- `rejects malformed images trailing payload and excessive dimensions`; `rejects malformed JPEG boundaries and trailing data`; `accepts exactly ten MiB of a structurally valid PNG without trusting a fake reported size`.
- `removes both newly stored CV and portfolio and rolls back both metadata records on database failure`; `removes a stored CV if portfolio inspection fails before application insertion`.
- `does not accept portfolio PDFs when inspection is disabled`; `uses the real PDF inspector for portfolio submission including compressed actions and encryption` (clean/JS object stream/encrypted, CI bắt buộc runner thật).
- `returns a friendly Vietnamese 413 when the whole request exceeds the PHP upload limit`; `reports a failed PHP temporary upload in Vietnamese without saving a record`.

Browser local: file PNG tổng hợp **10.485.760 byte** chọn qua file chooser và gửi form thật cùng Turnstile demo; sau sửa upload_tmp_dir đã nhận xác nhận, anchor `#ung-tuyen` đúng và console không lỗi. Không dùng file hoặc thông tin thật. Các lỗi PHP trước bản sửa được ghi nhận là lỗi, không tính là đạt. Dữ liệu thử được dọn riêng theo ID fixture, tài khoản có sẵn giữ nguyên.

Ngày 25/11/2026, local `PDF_SANDBOX_TESTS=1 composer test`: **646 test, 2132 assertions**, không skip; `composer lint` (Pint/Larastan) xanh. `git diff --check` xanh; CSS/JS kế thừa bản build T24 (T25 không đổi asset). CI của commit cuối được ghi trong PR; không dùng kết quả T24 thay cho T25.

## Chưa đạt và giới hạn còn lại

- T23 còn thiếu mẫu Canva sạch/điện thoại và chốt rootless trên server; PDF production vẫn tắt, các PR giữ draft vì phụ thuộc. Không tuyên bố T23 hoàn tất.
- Ảnh chỉ kiểm tra cấu trúc/header có giới hạn, không giải mã toàn bộ entropy/pixel, không phát hiện mọi ảnh lỗi hoặc exploit của decoder, không thay ClamAV. Giữ pending, chưa xem/tải; AV/decoder sandbox ở T27 và policy/audit/CSP ở T33 vẫn cần trước dữ liệu thật.
- T26 còn lệnh dọn mồ côi và test lỗi nhiều tầng; không tự triển khai trong task này. Chưa có bằng chứng cấu hình PHP/Nginx/ACL/cgroup production hoặc tải đồng thời.

## Giả định

10 MB = 10 MiB, dùng cùng giới hạn CV. JPEG được lưu đuôi chuẩn jpg; tối đa 16.384 px mỗi chiều/40 triệu pixel để giới hạn xử lý tương lai, PNG tối đa 2048 chunk, JPEG 100.000 marker. Không đổi nội dung file gốc.

## Đề xuất

Kiểm tra upload thật trên staging đúng user PHP-FPM và Nginx; bổ sung mẫu ảnh thiết bị thực khi triển khai decoder, không nới kiểm tra file hoặc bỏ pending để chạy demo. Dừng sau T25 theo phạm vi hai task được yêu cầu.
