# T38 — quyết định đã duyệt ngày28/11/2026

Người dùng duyệt ba quy tắc T38, bổ sung snapshot duration_days và tách T38B. Đây là bản chốt thay cho đề xuất ban đầu.

## Thời hạn

starts_at lấy từ server tại xác nhận, không nhập tay hoặc lùi ngày. Admin chọn ends_at giờ Việt Nam, lưu UTC. ACCESS_MIN_DAYS/ACCESS_MAX_DAYS mặc định1/366, có thể cấu hình; tối thiểu là đủ số ngày tính từ thời điểm xác nhận, tối đa bao gồm23:59:59VN của ngày cuối. Không chọn ngày mặc định khi đơn không có số ngày. Sau cấp, thay thời hạn thuộc T39.

Đơn mới chụp ACCESS_DURATION_DAYS do server; không tự đặt giá trị, bỏ qua duration gửi từ employer, snapshot bất biến. Migration mới, không backfill đơn cũ; null thì ô ends_at trống và bắt buộc nhập. Gift/trial có thể có số ngày lúc admin tạo; thiếu vẫn nhập tay. Có số ngày thì gợi ý starts_at+Nngày tới23:59:59VN. Admin đổi gợi ý phải audit chỉ default_ends_at/selected_ends_at. duration_max_days là cột kỹ thuật chụp giới hạn cho CHECK database, không phải gói mới.

## Đối chiếu

Bank transfer bắt buộc mã giao dịch, bỏ whitespace và viết hoa; dùng snapshot ngân hàng/tài khoản của đơn. Partial unique database cho paid theo ngân hàng/tài khoản và mã giao dịch, kể cả hai admin đồng thời. Đơn0đ gift/free_trial không cần mã nhưng cần reason theo T29. Không thay số tiền/ngân hàng khi xác nhận.

Nhập tiền thấp/cao hơn: giữ pending, không grant, báo số tiền đơn và số tiền nhập, chờ T38B. Audit order.confirm_rejected, reason_code amount_mismatch, metadata đúng order_id/order_amount/received_amount. Số tiền không phải số/0/âm không được dùng xác nhận chuyển khoản. T38B hủy/tạo thay thế là task riêng, không có nút hoặc workflow này trong T38.

## Xung đột và audit

Chỉ pending access_grant có scope, company/job chưa xóa và chưa bị chặn vi phạm, giữ các trạng thái published/paused/expired/closed đã duyệt. Cờ vi phạm qua ApplicationRestriction; T43 mới thêm dữ liệu cờ thật. Không tự gán legacy thiếu scope, không sửa paid thiếu grant; báo lỗi và audit. Khoảng grant giao nhau giữ pending, gia hạn T39. Paid có grant trả kết quả cũ, không cấp thêm hoặc phục hồi grant revoked.

Khóa job trước order (cùng T37), recheck admin active, kiểm tra grant dưới khóa; unique access_grants.order_id giữ nguyên. Paid+grant+order.paid_confirmed+access_grant.created cùng transaction qua RecordAudit. Metadata thành công chỉ IDs/thời hạn. Lỗi audit rollback hết. Audit từ chối nằm trong transaction riêng sau rollback, lỗi thì503. Không email, payment API hoặc thêm dependency.
