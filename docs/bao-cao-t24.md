# T24 — Form ứng tuyển

## Đã làm

- Form guest trên tin nhận hồ sơ: họ tên/SĐT/đồng ý bắt buộc; email, CV, portfolio link tùy chọn. Chuẩn hóa SĐT Việt Nam 0/+84/84. Không thêm cấu hình CV bắt buộc theo tin (T24B riêng).
- POST thật, CSRF/rate limit, scope công khai và ApplicationPolicy trước kiểm tra dữ liệu; transaction khóa/đọc lại Job và công ty, gọi lại đúng hàm T18. Company/ULID/nguồn/thời điểm lấy từ server. Policy là nơi truy vấn trùng hồ sơ; chưa mở đọc hồ sơ.
- Mã form bind session/job, khóa chống đồng thời, cache chỉ tiêu thụ sau thành công. CV kiểm tra/lưu bằng T23; lỗi DB rollback cả bản ghi files/applications và xóa file vừa tạo. Không log QueryException/PII. Portfolio link HTTP/HTTPS, không server-fetch.
- Turnstile qua Laravel HTTP client, timeout 5s, atomic digest chống token dùng lại; production thiếu/demo config/sai hostname/action đều từ chối. Không Composer package mới. CSP nonce chỉ form mở host script/frame cần thiết; trang khác không đổi quyền.
- Thông báo nhận/lỗi tiếng Việt, giữ dữ liệu chữ trừ token; hướng dẫn chọn lại file. Trang chính sách dữ liệu và README nêu Cloudflare, 6 tháng và người được xem; không tự thêm email/queue/AV của task sau.

## Tiêu chí và kiểm tra

- `ApplicationSubmissionTest`: `denies direct submission to each non accepting public state before captcha or file storage` (paused/expired/closed, 403, viết trước mã endpoint); `accepts a guest without CV and normalizes equivalent phone formats`; `rejects invalid required fields and retains safe entered fields but never the captcha token`; `prevents double clicks and a fresh form with the same normalized phone on the same job`.
- Cùng nhóm: `stores a checked private CV and link with source from the session without fetching the link`; `rolls back application and file rows and removes a newly stored CV when database saving fails`; `rejects submission if the job expires after form loading`; `rechecks the locked job after captcha verification before saving`; `binds a form token to its job and session and rejects missing forged or busy tokens`; `only grants Turnstile CSP on an accepting detail page and gives its script the request nonce`.
- `TurnstileTest`: token hợp lệ/sai, dùng lại local và remote, ConnectionException timeout, HTTP lỗi/JSON hỏng, thiếu/khóa demo production, hostname/action sai. Tất cả Http::fake, không gọi dịch vụ thật trong test tự động.
- `accepts a ten MiB image PDF through the submission pipeline with fake captcha and preserves original bytes`: HTTP endpoint + kiểm tra PDF thật + file/DB, chỉ chạy khi PDF_SANDBOX_TESTS=1 (CI bắt buộc). Local khoảng 1,8s ở lượt ngày 25/11; không gồm upload qua mạng hoặc thời gian Cloudflare, không phải benchmark production/p95. Raw `.runtime/t24-submission-benchmark.json` không chứa PII.
- Browser local: gửi không CV qua widget demo thật nhận “Đã nhận hồ sơ”; nhập SĐT 123 có lỗi tiếng Việt, giữ tên/SĐT/đồng ý. Console không lỗi. Bố cục hẹp một cột và thông báo lỗi rõ. Chỉ dữ liệu mẫu, không CV người dùng. Phát hiện và sửa anchor redirect để về `#ung-tuyen` thay vì query.

Ngày 25/11/2026, local chạy tuần tự `PDF_SANDBOX_TESTS=1 composer test`: **613 test, 2006 assertions**, không skip; `composer lint` (Pint/Larastan), `npm run build` và `git diff --check` xanh. CI của commit cuối được ghi trong PR; không coi kết quả CI cũ là bằng chứng thay thế.

## Chưa đạt và giới hạn

- T23 vẫn draft/thiếu mẫu Canva sạch và điện thoại; PDF production tắt. User cho phép viết T24–T25 dù điều kiện này chưa xong, không nới chính sách file.
- Chưa xác minh Turnstile bằng khóa production thật, tải đồng thời, tổng thời gian qua mạng hay server rootless. Khóa demo có thể dùng token cố định và bị chặn dùng lại 10 phút; đây là giới hạn demo đã ghi README, không bypass captcha.
- File portfolio T25; lệnh dọn mồ côi/test lỗi mở rộng T26; quét/chuyển đổi T27, quyền đọc/audit T30–T35, xóa sau 6 tháng T42/T46 chưa thuộc task này. Dữ liệu chính sách mô tả hành vi đích; chưa mở cho hồ sơ thật tới khi các phần này vận hành.

## Giả định

Migration mới chỉ thêm unique `submission_hash` nullable cho dữ liệu cũ; test `keeps submission idempotency in PostgreSQL even if a cache lease expires and the payload changes` kiểm tra lớp chống lặp cuối ở DB ngoài khóa cache.

SĐT là số di động Việt Nam 10 chữ số (đầu 03/05/07/08/09); 10 MB = 10 MiB. Mã form sống một giờ và khóa xử lý 30 giây; Turnstile digest sống 10 phút. CV pending không được phục vụ trực tiếp.

## Đề xuất

Kiểm tra cấu hình và monitor trên staging trước nhận người thật; bổ sung corpus T23, không đổi nhãn nguồn PDF hoặc nới marker để hợp thức hóa mẫu. Không tự làm các task sau trong T24.
