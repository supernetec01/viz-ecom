# Quy tắc làm việc

T38 đã duyệt ngày28/11: starts_at server lúc xác nhận; ends_at giờ VN trong min/max cấu hình (mặc định1–366ngày), điền từ duration_days +end-of-dayVN. Duration server snapshot ACCESS_DURATION_DAYS, đơn cũnull nhập tay, không backfill. Chuẩn hóa mã giao dịch bỏ whitespace/hoa; partial unique bank+account+ref khi paid. Paid+grant+hai audit order.paid_confirmed/access_grant.created cùng transaction, failclosed. Override expiry ghi default/selected. Amount mismatch giữ pending, không grant, audit order.confirm_rejected reason_code amount_mismatch metadata chỉorder_id/order_amount/received_amount. T38B hủy/tạo thay thế riêng, không làm trongT38. Cờ vi phạm đi qua ApplicationRestriction, T43 mới có cờ thật.

Người dùng duyệt T34–T35: PDF.js npm chính thức, asset tự host, không script trongPDF/khôngunsafe-eval, CSP file giữ nguyên. T35 mọi vai trò chung ngưỡng10 lượt xem/tải file và30 lượt chi tiết/tài khoản/cửa sổ60s,429/Retry-After, cảnh báo admin trong hệ thống gộp15phút, cấu hình được, không tự khóa/thu hồi. Đăng nhập audit fail closed; hook cấp/thu hồi có actor rõ và test, T38–T39 mới nối workflow thật, không làm UI thanh toán trước.

Quyết định T32 đã duyệt: detail Blade riêng; admin active/staff phụ trách/employer grant còn hạn đọc và sửa. Status thủ công5 giá trị, không tự đổi khi mở. Notes chung công ty2000 ký tự/escape + editor role/time. Save recheck policy, khóa dòng/edit_version chống ghi đè. Audit tối thiểu T32 qua RecordAudit append-only, fail closed trước trả detail, status/note audits cùng transaction; T35 bổ sung. Nút yêu cầu/đơn chờT37; T31 chỉ link khi có quyền hoặc nhãn Chưa mở khóa. T43 nối cờ vi phạm qua ApplicationRestriction duy nhất, non-admin bị chặn; hiện chưa có cờ và helper false.

- Đọc `docs/thiet-ke.md`, đặc biệt mục 3–6, và các quyết định đã chốt trước khi làm.
- Làm từng task T00–T48: một task, một nhánh, một pull request. Không làm trước task sau. Dừng sau báo cáo để người dùng xem lại.
- Chỉ làm đúng phạm vi task. Việc ngoài phạm vi ghi vào đề xuất. Không rõ về phân quyền, file, thanh toán hoặc xóa dữ liệu thì hỏi.
- Stack: Laravel, PostgreSQL, Pest, Pint, Larastan. Filament, Blade, Tailwind, Alpine.js được bổ sung ở task tương ứng.
- Không thêm thư viện ngoài phạm vi được duyệt. Không sửa migration đã chạy ở task trước; thêm migration mới.
- Viết test cùng mã; task chỉ hoàn tất khi `composer test` và `composer lint` xanh. Task phân quyền viết test từ chối 403 trước test cho phép.
- Mọi truy vấn hồ sơ phải qua ApplicationPolicy. Kiểm tra lại quyền và ghi audit_logs mỗi lần xem chi tiết, xem file hoặc tải file.
- Chưa có quyền: HTML và JSON không chứa SĐT, email, link CV hoặc portfolio. Không chỉ ẩn bằng giao diện.
- Không ghi SĐT, email hoặc nội dung CV vào log.
- CV nằm ngoài web root, tên UUID, kiểm tra loại thật, tối đa 10 MB.
- Lưu thời gian UTC, hiển thị Asia/Ho_Chi_Minh. Chuỗi giao diện tiếng Việt đặt trong file ngôn ngữ.
- Xác thực bằng phiên cookie Laravel. Chưa dùng JWT, access/refresh token hoặc Sanctum.
- Fortify đã được người dùng duyệt cho đăng nhập, đặt lại mật khẩu và 2FA; Filament dùng cho giao diện quản trị.
- Quyền xem chỉ theo tin tuyển dụng, xem được hồ sơ nộp trước khi mua, hết hạn/thu hồi chặn ngay yêu cầu kế tiếp.
- Xóa thông tin cá nhân và file sau 6 tháng; chỉ giữ thống kê không định danh. Không có chức năng ứng viên tự xóa. Admin xử lý xóa thủ công ở T46.
- Chưa làm thanh toán tự động, API Zalo hoặc OTP ứng viên.
- Báo cáo cuối task: đã làm; tiêu chí đạt kèm tên test; chưa đạt; giả định; đề xuất.

<!-- CODEGRAPH_START -->
## CodeGraph

Nếu repo có `.codegraph/`, dùng `codegraph_explore` trước khi grep/tìm/đọc để tìm hiểu code. Nếu không có thì bỏ qua; không tự tạo index.
<!-- CODEGRAPH_END -->
