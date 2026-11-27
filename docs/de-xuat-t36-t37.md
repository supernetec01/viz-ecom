# Các quyết định cần chốt cho T36–T37

Người dùng duyệt cả ba điểm ngày 27/11/2026: “Duyệt cả 3, triển khai T36 rồi T37”.

## T36 — đã duyệt

Giới hạn hai phiên cho mỗi tài khoản employer, không theo công ty và không áp dụng admin/staff. Hoàn tất đăng nhập (sau MFA nếu có) mới tính phiên. Lần đăng nhập thứ ba thu hồi phiên đăng nhập lâu nhất, không theo last_activity. Kiểm tra lại ở request kế tiếp, kể cả xem/tải file và Livewire.

Dùng registry phiên trong DB và khóa theo người dùng để tránh hai lần đăng nhập đồng thời vượt ngưỡng; Laravel vẫn xác thực bằng session cookie. Không thêm JWT/Sanctum/token auth hoặc thư viện. Không dựa riêng vào xóa dòng sessions vì request đang chạy/cookie ghi nhớ có thể khôi phục phiên.

Khi thu hồi, vô hiệu cookie ghi nhớ của tài khoản để phiên cũ không tự đăng nhập lại; hai phiên còn hiệu lực tiếp tục bằng cookie phiên hiện tại. Sau triển khai, các phiên employer cũ cần đăng nhập lại một lần để có registry.

## T37 — đã duyệt

Giá VND và tài khoản nhận chuyển khoản bắt buộc qua cấu hình; thiếu cấu hình chặn tạo đơn. Không tự đặt giá, số ngày hoặc thông tin chuyển khoản giả. Admin chọn thời hạn ở T38 theo quyết định đã chốt. Test dùng cấu hình giả riêng, không đưa thành giá production.

Chỉ employer active thuộc đúng công ty, tin published/paused/expired/closed, kể cả chưa có hồ sơ. Chưa tạo đơn mới cho draft/pending hoặc tin/công ty xóa mềm. Quyền cũ đọc hồ sơ theo T30 giữ nguyên. Có grant đang hiệu lực thì không tạo thêm đơn; có pending cùng công ty/tin thì dùng lại. Nhiều tài khoản công ty và request đồng thời vẫn chỉ có một pending.

T37 chỉ pending/hướng dẫn chuyển khoản, không xác nhận paid/cấp quyền hoặc làm T38. T36 hoàn tất trước khi tạo nhánh/viết mã T37.
