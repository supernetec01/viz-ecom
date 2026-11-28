# T39 — quyết định đã duyệt ngày 28/11/2026

Người dùng đã duyệt cả ba điểm. T39 triển khai trên nhánh riêng từ T38, giữ PR draft chờ xem lại.

## Gia hạn

Mỗi lần gia hạn tạo một đơn mới, sau khi đối chiếu paid tạo một grant mới. Không kéo dài hoặc sửa khoảng của quyền cũ. `orders.is_renewal` do server đặt, bất biến sau tạo; `access_grants.previous_grant_id` lưu quyền trước thực tế ở lúc xác nhận, nullable với quyền cấp mới/cũ. Migration 000024 mới, không backfill chuỗi lịch sử.

Chỉ admin active tạo và xác nhận. Đơn bank transfer chụp giá, số ngày và ngân hàng hiện tại qua AccessPaymentSettings; bỏ qua các giá trị giá/ngân hàng/số ngày giả gửi từ client. Gift/free_trial 0 đồng có reason bắt buộc, admin có thể chọn số ngày hoặc để trống rồi nhập ends_at thủ công khi xác nhận. Một pending theo company/job; dùng lại pending gia hạn hiện có, không tự biến pending cấp mới thành gia hạn. Không thêm luồng employer tự yêu cầu gia hạn.

Xác nhận dùng T38: khóa job → order → grant; tìm grant cuối CHƯA thu hồi theo ends_at rồi ID. Starts mới = max(server now, ends_at cuối). Confirmed_at vẫn là lúc xác nhận, không phải starts tương lai. Ends mặc định tính từ starts mới cộng duration_days tới 23:59:59 VN; kiểm tra min/max tính từ starts mới. Giữ nguyên UTC trong DB. Khoảng nửa mở [starts_at, ends_at), bằng nhau ở ranh giới được phép; trùng thật vẫn bị từ chối. Audit access_grant.created thêm previous_grant_id. Paid/confirmation/grant/audit cùng transaction.

Khi không còn quyền chưa thu hồi, gia hạn bị từ chối; cần cấp mới qua đơn mới T37/T38. Quyền revoked không được bật lại. Nếu đã có pending gia hạn rồi mọi quyền bị thu hồi, đơn đó giữ pending và không được xác nhận; không tự đổi loại/hủy/sửa đơn. Việc hủy/thay thế chờ T38B.

## Thu hồi theo tin

Admin xem danh sách grant đang hiệu lực hoặc lên lịch (status active, ends_at > now) của đúng company/job, gồm mã quyền và khoảng thời gian, trước khi xác nhận. Lý do bắt buộc chọn trong danh mục company_request/security/payment_review/administrative/other, không nhập nội dung tự do vào audit. Không thêm ghi chú hoàn tiền trong T39.

Chữ ký HMAC gắn với admin, company/job và danh sách ID/order/khoảng quyền. Server đọc lại dưới khóa job: danh sách thay đổi thì 409, yêu cầu xem lại trước; không thu hồi quyền mới chưa liệt kê. Đây là bảo vệ bản xem trước form, không phải token đăng nhập hay quyền truy cập; xác thực vẫn cookie Laravel + CSRF + policy admin fresh.

Thu hồi toàn bộ tập đã xác nhận trong một transaction, lưu revoked_at/by/reason và một access_grant.revoked cho từng grant qua RecordAudit. Metadata chỉ grant_id/job_id/order_id/reason code. Audit lỗi hoàn tác toàn bộ, kể cả dòng audit đã ghi trước lỗi. Không đổi đơn paid, không tự hoàn tiền. Quyền đã hết và tin khác được giữ. Yêu cầu chi tiết/xem/tải kế tiếp bị chặn ngay, kể cả cùng phiên và URL ký còn hạn.

## Phạm vi còn lại

Không thu hồi toàn công ty vì vi phạm (T43), không làm T38B, email, API thanh toán hay T40. Không thêm dependency. Danh sách admin chỉ metadata quyền, không truy vấn hồ sơ ứng viên. Không bảo đảm rút lại dữ liệu mà người có quyền đã tải trước lúc thu hồi. PDF production vẫn tắt theo chốt T23, không dùng dữ liệu CV cá nhân làm fixture công khai.
