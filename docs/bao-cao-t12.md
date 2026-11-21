# T12 — Quét nội dung trước khi đăng tin

Nhánh `task/t12-content-screening`, nối tiếp T11, một task/một PR; chưa merge main và chưa làm T13. Bản sửa CSP của T11 đã được đưa vào nhánh này sau khi người dùng duyệt điều kiện nonce và kiểm tra trình duyệt đạt.

## Đã làm

- `ContentScanner` quét bốn trường tin: title, description, requirements, benefits. Chuẩn hóa Unicode/NFKC, ký tự vô hình, số viết bằng chữ; nhận diện SĐT di động/cố định Việt Nam, dạng +84/0084, chèn dấu hoặc chữ, email và các dạng [at]/[dot], Zalo/inbox viết cách hoặc xen dấu.
- Theo quyết định người dùng ngày 21/11/2026: mọi link ngoài website trong nội dung tin cần duyệt. Kiểm tra href thật, link có/không HTTP(S), www, link rút gọn/nhắn tin, Unicode giống Latin, hoa thường, giả mạo userinfo/hậu tố và dấu gạch chéo ngược. Link nội bộ theo hostname APP_URL hoặc đường dẫn nội bộ được qua. Không truy cập mạng/DNS để mở các link.
- Không quét giới thiệu hoặc kênh công ty, không thêm danh sách trắng theo kênh. Ảnh nhúng bị bộ lọc HTML T11 loại; scanner cũng nhận diện src ngoài khi kiểm tra HTML chưa lọc.
- `SubmitJob` khóa bản ghi, nạp nội dung mới nhất và kiểm tra policy submit: chỉ admin hoặc nhân viên được giao công ty, tài khoản hoạt động, tin draft. Tin sạch sang published khi hạn hợp lệ; tin cần duyệt sang pending, không có ngày đăng.
- Hook model tự quét trước chuyển draft sang pending/published, để gọi publish trực tiếp không bỏ qua quét. Sửa nội dung/về draft xóa kết quả cũ, gửi đăng sẽ quét lại. Không cho gửi lại pending/published để bỏ qua duyệt.
- Migration mới thêm `content_scan_reasons` JSONB và `content_scanned_at` UTC, không sửa migration cũ. Lý do lưu trường/quy tắc; riêng link ngoài có thêm URL cụ thể cho T13. Metadata ẩn khỏi JSON mặc định và không nhận qua mass assignment. Không ghi SĐT, email hoặc đoạn khớp vào log.
- Cập nhật tài liệu và README; giữ nguyên CSP, dependency và dữ liệu admin demo.

## Tiêu chí đạt và tên test

| Tiêu chí | Test | Kết quả |
| --- | --- | --- |
| Ít nhất 30 biến thể liên hệ | `ContentScannerTest` / `flags contact variants…` | 38 biến thể, gồm không9 và chữ số tiếng Việt |
| Từng dạng link cần giữ | `ContentScannerTest` / `records the offending external link…` | 19 trường hợp, mỗi dataset là một test riêng |
| Không giữ nhầm lương/địa chỉ/giờ, link nội bộ qua | `ContentScannerTest` / `allows clean descriptions…` | 15 trường hợp, có 15.000.000 và 12.5 km |
| Trộn nội bộ/ngoài chỉ rõ link ngoài; không quét công ty | Hai test cuối `ContentScannerTest` | Đạt |
| Từ chối 403 trước khi viết quyền | Bốn trường hợp đầu `JobSubmissionTest` | Đã chạy đỏ trước khi thêm policy submit, hiện xanh |
| Sạch đăng, vi phạm pending, lưu href, không quét kênh công ty | `JobSubmissionTest`: role matrix, field matrix, exact outside href, company channels | Đạt trên PostgreSQL |
| Không bỏ qua quét, không dùng model cũ, giao dịch rollback và quét lại | Các test model boundary, stale clean model, invalid expiry, redraft và repeated submit | Đạt |

Khi hoàn tất riêng T12: **277 passed, 846 assertions**. Sau khi đưa bản sửa CSP/T11 đã được duyệt vào nhánh này: **289 passed, 923 assertions**, bao gồm 12 test CSP/hồi quy phiên. `ContentScannerTest` vẫn có **74 test**, `JobSubmissionTest` **17 test**. `composer lint` (Pint/Larastan) và `composer validate --strict` xanh. Migration đã chạy thành công trên database phát triển.

## Tiêu chí chưa đạt / ngoài phạm vi

Không còn tiêu chí thiếu trong phạm vi dịch vụ T12 và các trường hợp link đã được chốt. T11 đã kiểm tra các thao tác trình duyệt sau sửa CSP, xem báo cáo T11. Chưa thêm nút gửi đăng trong đợt sửa CSP; T13 sẽ làm màn hình xem lý do, duyệt/từ chối và thông báo, chưa làm trước ở đây.

## Giả định đã dùng

- Tên miền nội bộ là hostname chính xác trong APP_URL sau chuẩn hóa IDNA/hoa thường; không tự mở rộng sang www hoặc tên miền con khác. Cần đặt APP_URL đúng tên miền chuẩn khi triển khai.
- Dịch vụ dùng quy tắc/heuristic cho các dạng được kiểm thử, không cam kết nhận diện mọi cách né lọc. Chuỗi có dạng tên miền được giữ để duyệt mà không xác minh DNS, nên có thể có trường hợp cần admin phân loại lại.
- Số/email khớp không lưu vào lý do; URL ngoài lưu nguyên giá trị phát hiện theo yêu cầu người dùng, chỉ để người có quyền duyệt đọc, không ghi log. Không xử lý CV hoặc hồ sơ ứng viên ở task này.
- Ảnh ngoài đã bị loại khỏi rich text không cần giữ tin chỉ vì ảnh đã biến mất; scanner vẫn đánh dấu nếu gọi trực tiếp với HTML chứa src ngoài.

## Đề xuất thêm

- Khi làm T13, hiển thị nhãn trường/quy tắc và URL gây giữ, kiểm tra admin-only, bắt buộc lý do từ chối.
- Thêm regression test cho từng biến thể né lọc hoặc giữ nhầm được phát hiện thực tế trước khi sửa quy tắc.
- Giữ đúng ngoại lệ CSP đã duyệt ở T11 và cập nhật test mỗi lần đổi cấu hình; không tự mở rộng ngoại lệ sang trang công khai hoặc file riêng tư.

## Chạy kiểm tra trên máy hiện tại

```powershell
cd D:\AI\Viz-Ecom
$env:Path = "$PWD\.tools\php;$env:Path"
php vendor/pestphp/pest/bin/pest tests/Unit/ContentScannerTest.php
php vendor/pestphp/pest/bin/pest tests/Feature/JobSubmissionTest.php
php .tools/composer.phar test
php .tools/composer.phar lint
```

Unit test bộ quét không cần database. Bộ test tích hợp dùng `viz_ecom_test`, tách khỏi dữ liệu phát triển. Các giá trị số/email/link trong test đều là dữ liệu giả.
