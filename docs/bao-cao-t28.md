# Báo cáo T28

## Đã làm

Trang cảm ơn riêng sau POST thành công, không dữ liệu ứng viên, noindex/no-store, tải lại không nộp lại. Email tóm tắt qua database queue riêng application-mail, chỉ tên công ty, vị trí, số hồ sơ mới và nút /app. Người nhận là các employer active thuộc công ty tại lúc worker chạy, kiểm tra lại trước gửi; không admin/staff/CC/BCC.

Counter tăng cùng giao dịch hồ sơ, enqueue sau commit; lỗi enqueue/SMTP không làm mất hồ sơ. Lệnh phục hồi application-summaries:dispatch đọc counters, không đọc hồ sơ. Không thêm thư viện. Thêm migration mới 000016, không sửa migration cũ.

Chốt từng tin/người nhận một email mỗi giờ; gộp các hồ sơ phát sinh trong thời gian chờ. Claim/watermark DB trước SMTP chống worker phát lại. Lỗi trước gửi retry tối đa ba lượt, chờ60/300s; SMTP mất phản hồi hoặc crash không tự phát lại lô đã claim, trạng thái uncertain/sending cần đối soát. Lô sau chỉ đếm từ watermark đã thử, nên lô chưa rõ kết quả có thể không tới hộp thư; không hứa exactly-once SMTP.

## Tiêu chí đạt kèm test

- `ApplicationReceiptTest`: redirect khi thành công, không PII/JobPosting/sitemap, noindex/no-store, CSP không thêm Turnstile/eval, reload được.
- `ApplicationSummaryTest`: queue sau commit/payload không PII; khóa/đổi vai trò/detach/công ty khác/admin/staff không nhận; người mới được xác định lúc worker; nội dung/attachment an toàn; giờ biên3599/3600s và count gộp; rollback không enqueue; lỗi enqueue không ảnh hưởng nộp; uncertain/crash không gửi trùng; retry trước SMTP có giới hạn; command phục hồi.
- Browser local đã mở trang cảm ơn và kiểm tra bố cục/nút về danh sách. Test POST tự động xác minh redirect, không gửi hồ sơ/mail thật bên ngoài. Kết quả composer test/lint và CI cuối ghi ở PR.

## Chưa đạt

Chưa cấu hình SMTP thật hoặc chứng minh email tới hộp thư. Local mailer log chỉ mô phỏng giao thư, cần worker application-mail mới xử lý. T42 chưa gắn lịch phục hồi, T31 chưa có danh sách nên link /app. Không mở dữ liệu thật hoặc PDF production trong task này.

## Giả định

Cache lock dùng chung giữa worker; database queue cùng DB nghiệp vụ, MAIL_TIMEOUT10s thấp hơn timeout worker60s, retry_after300s. Counter chỉ tính hồ sơ nộp từ T28, không gửi bù toàn bộ hồ sơ cũ. Nhiều job trùng có thể cùng chờ nhưng watermark/khóa DB quyết định duy nhất lô được thử gửi.

## Đề xuất

T48 kiểm tra SMTP, theo dõi uncertain/sending bị bỏ dở và failed jobs, cấu hình chạy command phục hồi theo lịch T42. Không tự gửi lại lô chưa rõ kết quả trước đối soát nhà cung cấp.
