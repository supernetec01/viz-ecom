# Quy tắc làm việc

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
