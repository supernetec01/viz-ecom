# Báo cáo T30 — ApplicationPolicy

## Đã làm

ApplicationPolicy có viewSummary, viewDetails, download qua Gate với Application::class + ULID chuỗi. Mỗi lần lấy lại actor active, quan hệ công ty/phân công và grant hiện tại; không dùng quyền lưu session hoặc object cũ. Model Application chỉ cho serialize id mặc định, không mass assign. Policy lấy context chỉ id/company/job, không đọc contact/file path khi từ chối.

Employer active đúng công ty xem summary không cần mua; details/download cần AccessGrant::effective đúng company/job, không lọc ngày nộp nên bao gồm hồ sơ trước ngày mua. Admin active và staff active được phân công không cần grant. Download xác minh file gắn vào hồ sơ và dùng đúng FileReadiness T27; mọi role đều bị chặn khi scan khác clean, clean/preview failed vẫn đủ trạng thái tải gốc.

Theo lựa chọn đã duyệt: application/company xóa mềm bị chặn cho tất cả; job xóa mềm hoặc paused/expired/closed vẫn giữ hồ sơ theo quyền. Thu hồi xác minh công ty chưa chặn hồ sơ cũ. Không thêm endpoint thật detail/file, không đọc nội dung hoặc tạo audit_logs trước T35.

## Tiêu chí đạt kèm test

`ApplicationPolicyTest`: các ca 403 viết trước; chưa mua, grant future/expired/revoked, công ty/tin khác, locked ở mọi role, không còn membership/phân công/role, nonexistent/malformed/guest. Cùng phản hồi403 khi nonexistent/ngoài phạm vi (debug=false). Ca allow đúng công ty/tin và hồ sơ cũ, admin/staff không mua; starts_at/ends_at sát biên microsecond với Carbon test clock; revoke/mất membership/đổi role giữa hai request không cần đăng nhập lại. Test file khác hồ sơ, tất cả role × scan statuses, order không còn paid và query từ chối không select PII. Route kiểm tra chỉ đăng ký trong test, không vào routes/web.php.

Kết quả composer test/lint và CI commit cuối ghi trong PR. Không đổi UI, không kiểm tra lại browser/chức năng không liên quan ngoài bộ composer/CI bắt buộc.

## Chưa đạt / chờ task sau

T31 mới danh sách, T32 chi tiết, T33 route phục vụ file; T35 nhật ký thật. Khi phục vụ inline preview ở T33, phải gọi policy và FileReadiness.can_view, kiểm tra lại quyền từng yêu cầu + audit/CSP. T30 chỉ authorization backend, không tuyên bố luồng xem/tải đã dùng được. PDF production vẫn tắt.

## Giả định

Actor được xác thực bằng session; middleware hiện tại chịu trách nhiệm login/2FA. ULID truyền dạng chuỗi, không route-model-bind toàn bộ hồ sơ trước Gate. Quyền được tính theo thời điểm check của yêu cầu; không thu hồi dữ liệu đã được người dùng xem trong phản hồi trước.

## Đề xuất

T31 dùng query projection đặt trong policy; T32/T33 không select PII trước authorize. T35 ghi sự kiện truy cập thực ở lớp phục vụ dữ liệu, không ghi trong policy bool để tránh log trùng khi UI chỉ hỏi quyền.
